<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\plugin\updater;

require_once("./UpdateError.php");

class UpdateService {
  const STATUS_COMPLETED = "COMPLETED";
  const STATUS_PENDING = "PENDING";
  const STATUS_FAILED = "FAILED";
  
  protected $config;
  protected $sourceRootPath;
  protected $updateRootPath;
  protected $destRootPath;
  protected $auth = false;
  
  public function __construct($config) {
    $this->config = $config;
    $this->sourceRootPath = $config->sourceRootPath;
    $this->updateRootPath = $config->updateRootPath;
    $this->destRootPath = $config->destRootPath;
  }
  
  /* ====================================================================== */
  
  public function start() {
    UpdateError::clearLastPhpError();
    $updateID = $this->config->updateId;
    
    $checkResult = $this->checkInstalledFilesPermissions($updateID);
    if ($checkResult && $this->isError($checkResult)) {
      return $this->getErrorResponse($checkResult);
    }
    
    $completedPath = $this->getUpdatePath($updateID, "completed.txt");
    if (file_exists($completedPath)) {
      return $this->getErrorResponse(UpdateError::ERROR_ALREADY_UPDATED, array("updateID" => $updateID, "path" => $completedPath));
    }
    
    $error = null;
    $path = $this->getUpdatePath($updateID);
    if (! is_dir($path)) {
      return $this->getErrorResponse(UpdateError::ERROR_UNKNOWN_UPDATE_ID, array("updateID" => $updateID, "path" => $path));
    }
    $this->resetStepStatus($updateID);
    $version = $this->config->version;
    
    if ($this->isError($version)) {
      $error = $version;
      $this->changeStepStatus($updateID, "init", self::STATUS_FAILED, $error);
    }
    else {
      $this->changeStepStatus($updateID, "init", self::STATUS_COMPLETED);
    }
    
    // to avoid nesting hell...
    if (! $error) {
      $downloadResult = $this->downloadZip($updateID, $version);
      if ($this->isError($downloadResult)) {
        $error = $downloadResult;
      }
    }
    if (! $error) {
      $zipValidationResult = $this->validateZip($updateID, $version);
      if ($this->isError($zipValidationResult)) {
        $error = $zipValidationResult;
      }
    }
    if (! $error) {
      $unpackResult = $this->unpackZip($updateID);
      if ($this->isError($unpackResult)) {
        $error = $unpackResult;
      }
    }
    if (! $error) {
      $unzipValidationResult = $this->validateUnzip($updateID);
      if ($this->isError($unzipValidationResult)) {
        $error = $unzipValidationResult;
      }
    }
    if (! $error) {
      $enterMaintenanceModeResult = $this->enterMaintenanceMode($updateID);
      if ($this->isError($enterMaintenanceModeResult)) {
        $error = $enterMaintenanceModeResult;
      }
    }
    if (! $error) {
      $preUpdateScriptResult = $this->preUpdateScript($updateID);
      if ($this->isError($preUpdateScriptResult)) {
        $error = $preUpdateScriptResult;
      }
    }
    if (! $error) {
      $finalResult = $this->makeBackupAndCopyNewFiles($updateID);
      if ($this->isError($finalResult)) {
        $error = $finalResult;
      }
    }
    if (! $error) {
      $postUpdateScriptResult = $this->postUpdateScript($updateID);
      if ($this->isError($postUpdateScriptResult)) {
        $error = $postUpdateScriptResult;
      }
    }
    if (! $error) {
      $exitMaintenanceModeResult = $this->exitMaintenanceMode($updateID);
      if ($this->isError($exitMaintenanceModeResult)) {
        $error = $exitMaintenanceModeResult;
      }
    }
    if (! $error) {
      $completedPath = $this->getUpdatePath($updateID, "completed.txt");
      if (!file_put_contents($completedPath, "1")) {
        $error = $this->getErrorResponse(UpdateError::ERROR_WRITE_DATA_FILE, array("path" => $completedPath));
      }
    }
    if ($error) {
      $this->writeLogMessage($updateID, "Error: " . json_encode($error));
    }
    
    return array(
      "status" => $this->getUpdateStatus($updateID),
      "error" => $error
    );
  }
  
  public function getUpdateStatus($updateID) {
    $steps = $this->loadSteps($updateID);
    if ($steps) {
      $result = array(
        'steps' => $steps
      );
      return $result;
    }
    return $this->getErrorResponse(UpdateError::ERROR_UNKNOWN_UPDATE_ID);
  }
  
  /* ====================================================================== */
  
  protected function downloadZip($updateID, $version) {
    $updatePath = $this->getUpdatePath($updateID);
    $this->changeStepStatus($updateID, "download-zip", self::STATUS_PENDING);
    $this->writeLogMessage($updateID, "Downloading update...");
    $zipPath = self::joinPaths($updatePath, "privmx.zip");
    $url = $this->config->downloadUrl;
    $downloadResult = $this->downloadFile($url, $zipPath, $this->auth);
    if ($downloadResult === true) {
      $this->writeLogMessage($updateID, "Downloaded");
      $this->changeStepStatus($updateID, "download-zip", self::STATUS_COMPLETED);
    } else {
      $this->writeLogMessage($updateID, "Download error - " . $downloadResult);
      $error = $this->getError(UpdateError::ERROR_CANT_DOWNLOAD_ZIP, array("curl_error" => $downloadResult, "url" => $url));
      $this->changeStepStatus($updateID, "download-zip", self::STATUS_FAILED, $error);
      return $error;
    }
    return null;
  }
  
  protected function validateZip($updateID, $version) {
    $zipPath = $this->getUpdatePath($updateID, "privmx.zip");
    $this->changeStepStatus($updateID, "validate-zip", self::STATUS_PENDING);
    $this->writeLogMessage($updateID, "Validating zip - {$zipPath}");
    $hash = hash_file('sha256', $zipPath);
    $oldHash = $this->config->zipHash;
    
    if ($oldHash === $hash) {
      $this->writeLogMessage($updateID, "Checksum OK");
      $this->changeStepStatus($updateID, "validate-zip", self::STATUS_COMPLETED);
      return true;
    }
    $this->writeLogMessage($updateID, "Invalid checksum. calculated: " . $hash . ", expected: " . $oldHash);
    $error = $this->getError(UpdateError::ERROR_INVALID_CHECKSUM, array("path" => $zipPath, "calculated" => $hash, "expected" => $oldHash));
    $this->changeStepStatus($updateID, "validate-zip", self::STATUS_FAILED, $error);
    return $error;
  }
  
  protected function unpackZip($updateID) {
    $zipPath = $this->getUpdatePath($updateID, "privmx.zip");
    $destPath = $this->getUpdatePath($updateID);
    $this->changeStepStatus($updateID, "extract-files", self::STATUS_PENDING);
    $this->writeLogMessage($updateID, "Unpacking zip");
    if (!class_exists('\\ZipArchive')) {
      $this->writeLogMessage($updateID, "Missing ZipArchive class");
      $error = $this->getError(UpdateError::ERROR_ZIPARCHIVE_CLASS_NOT_FOUND);
      $this->changeStepStatus($updateID, "extract-files", self::STATUS_FAILED, $error);
      return $error;
    }
    $zip = new \ZipArchive;
    if ($zip->open($zipPath) !== true) {
      $this->writeLogMessage($updateID, "Can't open: " . $zipPath);
      $error = $this->getError(UpdateError::ERROR_CANT_OPEN_ZIP, array("path" => $zipPath));
      $this->changeStepStatus($updateID, "extract-files", self::STATUS_FAILED, $error);
      return $error;
    }
    $distPath = self::joinPaths($destPath, "privmx");
    if (! $this->fsRemove($distPath)) {
      $this->writeLogMessage($updateID, "Can't remove $distPath");
      $error = $this->getError(UpdateError::ERROR_CANT_REMOVE_OLD_DIST, array("path" => $distPath));
      $this->changeStepStatus($updateID, "extract-files", self::STATUS_FAILED, $error);
      return $error;
    }
    if (!$zip->extractTo($destPath)) {
      $this->writeLogMessage($updateID, "Can't extract $zipPath to $destPath");
      $error = $this->getError(UpdateError::ERROR_CANT_UNZIP, array("zipPath" => $zipPath, "destPath" => $destPath));
      $this->changeStepStatus($updateID, "extract-files", self::STATUS_FAILED, $error);
      return $error;
    }
    if (!$zip->close()) {
      $this->writeLogMessage($updateID, "Can't close: " . $zipPath);
      $error = $this->getError(UpdateError::ERROR_CANT_CLOSE_ZIP, array("path" => $zipPath));
      $this->changeStepStatus($updateID, "extract-files", self::STATUS_FAILED, $error);
      return $error;
    }
    $fixResult = $this->fixDistAppSymlink($updateID);
    if ($this->isError($fixResult)) {
      $this->writeLogMessage($updateID, "Can't fix dist app symlink");
      $this->changeStepStatus($updateID, "extract-files", self::STATUS_FAILED, $fixResult);
      return $fixResult;
    }
    $this->changeStepStatus($updateID, "extract-files", self::STATUS_COMPLETED);
    return true;
  }
  
  protected function validateUnzip($updateID) {
    $this->changeStepStatus($updateID, "validate-files", self::STATUS_PENDING);
    $this->writeLogMessage($updateID, "Validating extracted data");
    $distPath = $this->getUpdatePath($updateID, "privmx");
    if (!is_dir($distPath)) {
      $this->writeLogMessage($updateID, "Doesn't exists: " . $distPath);
      $error = $this->getError(UpdateError::ERROR_DIST_NOT_FOUND, array("path" => $distPath));
      $this->changeStepStatus($updateID, "validate-files", self::STATUS_FAILED, $error);
      return $error;
    }
    $spec = array(
      'requiredFiles' => array(
        'app/index.php',
        'install/index.php',
        'server/index.php',
        'server/api/index.php',
        'server/plugins/updater/UpdaterPlugin.php'
      )
    );
    $missingFiles = array();
    foreach($spec['requiredFiles'] as $file) {
      $filePath = $distPath . "/" . $file;
      if (! is_file($filePath)) {
        $this->writeLogMessage($updateID, "Doesn't exists: " . $filePath);
        $missingFiles[] = $filePath;
      }
    }
    if (count($missingFiles) > 0) {
      $error = $this->getError(UpdateError::ERROR_MISSING_SOME_DIST_FILES, array("missing" => $missingFiles));
      $this->changeStepStatus($updateID, "validate-files", self::STATUS_FAILED, $error);
      return $error;
    }
    $this->changeStepStatus($updateID, "validate-files", self::STATUS_COMPLETED);
    return true;
  }
  
  protected function enterMaintenanceMode($updateID) {
    $this->changeStepStatus($updateID, "enter-maintenance", self::STATUS_PENDING);
    $this->writeLogMessage($updateID, "Entering to maintenance mode");
    if (!$this->config->enterMaintenanceMode()) {
      $error = $this->getError(UpdateError::ERROR_ENTERING_MAINTENANCE_MODE);
      $this->changeStepStatus($updateID, "enter-maintenance", self::STATUS_FAILED, $error);
      return $error;
    }
    $this->changeStepStatus($updateID, "enter-maintenance", self::STATUS_COMPLETED);
    return true;
  }
  
  protected function preUpdateScript($updateID) {
    try {
      $path = self::joinPaths($this->getUpdatePath($updateID, "privmx"), "update-script.php");
      if (!file_exists($path)) {
        return null;
      }
      require_once($path);
      if (function_exists("preUpdateScript")) {
        $this->writeLogMessage($updateID, "Calling preUpdateScript");
        return preUpdateScript($updateID, $this);
      }
      return null;
    }
    catch(\Exception $e) {
      $this->writeLogMessage($updateID, "preUpdateScript Error " . $e->getMessage());
      return $this->getError(UpdateError::ERROR_PRE_SCRIPT);
    }
  }
  
  protected function postUpdateScript($updateID) {
    try {
      $path = self::joinPaths($this->getUpdatePath($updateID, "privmx"), "update-script.php");
      if (!file_exists($path)) {
        return null;
      }
      require_once($path);
      if (function_exists("postUpdateScript")) {
        $this->writeLogMessage($updateID, "Calling postUpdateScript");
        return postUpdateScript($updateID, $this);
      }
      return null;
    }
    catch(\Exception $e) {
      $this->writeLogMessage($updateID, "postUpdateScript Error " . $e->getMessage());
      return $this->getError(UpdateError::ERROR_POST_SCRIPT);
    }
  }
  
  protected function makeBackupAndCopyNewFiles($updateID) {
    
    // MAKING BACKUP
    
    $this->changeStepStatus($updateID, "make-backup", self::STATUS_PENDING);
    $this->writeLogMessage($updateID, "Making backup");
    $rootPath = $this->getRootSourcePath();
    $backupPath = $this->getUpdatePath($updateID, date("YmdHis") . "_backup");
    if (!$this->fsRemove($backupPath)) {
      $error = $this->getError(UpdateError::ERROR_REMOVING_EXISTING_BACKUP_DIR, array("path" => $backupPath));
      $this->changeStepStatus($updateID, "make-backup", self::STATUS_FAILED, $error);
      return $error;
    }
    if (!mkdir($backupPath)) {
      $error = $this->getError(UpdateError::ERROR_CREATING_BACKUP_DIR, array("path" => $backupPath));
      $this->changeStepStatus($updateID, "make-backup", self::STATUS_FAILED, $error);
      return $error;
    }
    
    $files = array(
      'app', 'install', 'server', 'talk', 'contact', 'validate',
      'index.php', '.htaccess', 'pack.json', 'README.txt', 'LICENSE.txt'
    );
    $optional = array('README.txt', 'LICENSE.txt', 'talk', 'contact', 'validate');
    
    $realDataLocation = $this->config->getDataDirectory();
    $realKeysLocation = $this->config->getKeys();
    $keysExists = $realKeysLocation && file_exists($realKeysLocation);
    $backupDataLocation = null;
    $backupKeysLocation = null;
    
    foreach($files as $file) {
      $src = self::joinPaths($rootPath, $file);
      $dest = self::joinPaths($backupPath, $file);
      $this->writeLogMessage($updateID, "Backup from $src to $dest");
      if (self::startsWith($realDataLocation, $src)) {
        $backupDataLocation = str_replace($src, $dest, $realDataLocation);
        $this->writeLogMessage($updateID, "BTW: Data backup from $realDataLocation to $backupDataLocation");
      }
      if ($keysExists && self::startsWith($realKeysLocation, $src)) {
        $backupKeysLocation = str_replace($src, $dest, $realKeysLocation);
        $this->writeLogMessage($updateID, "BTW: Keys backup from $realKeysLocation to $backupKeysLocation");
      }
      if (in_array($file, $optional) && !file_exists($src)) {
        $this->writeLogMessage($updateID, "File $src doesn't exist, but it is optional - skipping");
      }
      else if (! $this->fsCopy($src, $dest)) {
        $this->writeLogMessage($updateID, "Can't copy file(s) from {$src} to {$dest} ");
        $error = $this->getError(UpdateError::ERROR_BACKUP_FILES, array("source" => $src, "destination" => $dest));
        $this->changeStepStatus($updateID, "make-backup", self::STATUS_FAILED, $error);
        return $error;
      }
    }
    
    if (!$backupDataLocation) {
      $backupDataLocation = $this->getUpdatePath($updateID, "backup-data");
      $this->writeLogMessage($updateID, "Data backup from $realDataLocation to $backupDataLocation");
      if (!$this->fsCopy($realDataLocation, $backupDataLocation)) {
        $error = $this->getError(UpdateError::ERROR_BACKUP_DATA, array("source" => $realDataLocation, "destination" => $backupDataLocation));
        $this->changeStepStatus($updateID, "make-backup", self::STATUS_FAILED, $error);
        return $error;
      }
    }
    
    $backupConfigLocation = self::joinPaths($backupPath, "server/config.php");
    
    if (!file_exists($backupConfigLocation)) {
      $error = $this->getError(UpdateError::ERROR_BACKUP_CONFIG, array("missing" => $backupConfigLocation));
      $this->changeStepStatus($updateID, "make-backup", self::STATUS_FAILED, $error);
      return $error;
    }
    
    if ($keysExists && !$backupKeysLocation) {
      $backupKeysLocation = self::joinPaths($backupPath, "backup-keys.php");
      $this->writeLogMessage($updateID, "Keys file backup from $realKeysLocation to $backupKeysLocation");
      if (!$this->fsCopy($realKeysLocation, $backupKeysLocation)) {
        $error = $this->getError(UpdateError::ERROR_BACKUP_KEYS, array("source" => $realKeysLocation, "destination" => $backupKeysLocation));
        $this->changeStepStatus($updateID, "make-backup", self::STATUS_FAILED, $error);
        return $error;
      }
    }
    
    $this->changeStepStatus($updateID, "make-backup", self::STATUS_COMPLETED);
    
    // COPING FILES FROM ZIP
    
    $this->changeStepStatus($updateID, "copy-files", self::STATUS_PENDING);
    
    $distFiles = array(
      'app', 'install', 'server', 'talk', 'contact', 'validate',
      'index.php', '.htaccess', 'pack.json', 'README.txt', 'LICENSE.txt'
    );
    
    $distPath = $this->getUpdatePath($updateID, "privmx");
    $destPath = $this->getRootDestPath();
    foreach($distFiles as $file) {
      $src = self::joinPaths($distPath, $file);
      $dest = self::joinPaths($destPath, $file);
      $this->writeLogMessage($updateID, "Copy from $src to $dest");
      if (! $this->fsCopy($src, $dest)) {
        $this->writeLogMessage($updateID, "Can't copy {$src} to {$dest} ");
        $error = $this->getError(UpdateError::ERROR_COPY_FILES, array("source" => $src, "destination" => $dest));
        $this->changeStepStatus($updateID, "copy-files", self::STATUS_FAILED, $error);
        return $error;
      }
    }
    
    // COPING FILES FROM BACKUP
    
    // Data directory
    if (self::startsWith($realDataLocation, $destPath)) {
      $this->writeLogMessage($updateID, "Copy data from $backupDataLocation to $realDataLocation");
      if (! $this->fsCopy($backupDataLocation, $realDataLocation)) {
        $this->writeLogMessage($updateID, "Can't copy data from {$backupDataLocation} to {$realDataLocation} ");
        $error = $this->getError(UpdateError::ERROR_COPY_DATA, array("source" => $backupDataLocation, "destination" => $realDataLocation));
        $this->changeStepStatus($updateID, "copy-files", self::STATUS_FAILED, $error);
        return $error;
      }
    }
    else {
      $this->writeLogMessage($updateID, "Data directory out of destination directory, no need to copy $realDataLocation");
    }
    
    // config.php
    $destConfigLocation = $this->getRootDestPath("server/config.php");
    $this->writeLogMessage($updateID, "Copy config from $backupConfigLocation to $destConfigLocation");
    if (!$this->fsCopy($backupConfigLocation, $destConfigLocation)) {
      $error = $this->getError(UpdateError::ERROR_COPY_CONFIG, array("source" => $backupConfigLocation, "destination" => $destConfigLocation));
      $this->changeStepStatus($updateID, "copy-files", self::STATUS_FAILED, $error);
      return $error;
    }
    
    // config-custommenuitems.php
    $backupCustomMenuLocation = self::joinPaths($backupPath, "server/config-custommenuitems.php");
    $destCustomMenuLocation = $this->getRootDestPath("server/config-custommenuitems.php");
    if (file_exists($backupCustomMenuLocation)) {
      $this->writeLogMessage($updateID, "Copy custom menu from $backupCustomMenuLocation to $destCustomMenuLocation");
      if (!$this->fsCopy($backupCustomMenuLocation, $destCustomMenuLocation)) {
        $error = $this->getError(UpdateError::ERROR_COPY_CUSTOM_MENU, array("source" => $backupCustomMenuLocation, "destination" => $destCustomMenuLocation));
        $this->changeStepStatus($updateID, "copy-files", self::STATUS_FAILED, $error);
        return $error;
      }
    }
    
    // keys.php
    if ($keysExists && file_exists($backupKeysLocation) && self::startsWith($realKeysLocation, $destPath)) {
      $this->writeLogMessage($updateID, "Copy keys from $backupKeysLocation to $realKeysLocation");
      if (!$this->fsCopy($backupKeysLocation, $realKeysLocation)) {
        $error = $this->getError(UpdateError::ERROR_COPY_KEYS, array("source" => $backupKeysLocation, "destination" => $realKeysLocation));
        $this->changeStepStatus($updateID, "copy-files", self::STATUS_FAILED, $error);
        return $error;
      }
    }
    
    // callbacks directory
    $backupCallbacksLocation = self::joinPaths($backupPath, "server/callbacks");
    $destCallbacksLocation = $this->getRootDestPath("server/callbacks");
    if (is_dir($backupCallbacksLocation)) {
      $this->writeLogMessage($updateID, "Copy callbacks from $backupCallbacksLocation to $destCallbacksLocation");
      if (!$this->fsCopy($backupCallbacksLocation, $destCallbacksLocation)) {
        $error = $this->getError(UpdateError::ERROR_COPY_CALLBACKS, array("source" => $backupCallbacksLocation, "destination" => $destCallbacksLocation));
        $this->changeStepStatus($updateID, "copy-files", self::STATUS_FAILED, $error);
        return $error;
      }
    }
    
    // app/build/lib directory merge (to keep old version of privfs-client)
    $backupAppLibLocation = self::joinPaths($backupPath, "app/build/lib");
    $destAppLibLocation = $this->getRootDestPath("app/build/lib");
    if (file_exists($backupAppLibLocation)) {
      $this->writeLogMessage($updateID, "Copy app lib from $backupAppLibLocation to $destAppLibLocation");
      if (!$this->fsMergeDirs($backupAppLibLocation, $destAppLibLocation)) {
        $error = $this->getError(UpdateError::ERROR_COPY_APP_LIB, array("source" => $backupAppLibLocation, "destination" => $destAppLibLocation));
        $this->changeStepStatus($updateID, "copy-files", self::STATUS_FAILED, $error);
        return $error;
      }
    }
    
    // plugins directory merge
    $backupPluginsLocation = self::joinPaths($backupPath, "server/plugins");
    $destPluginsLocation = $this->getRootDestPath("server/plugins");
    if (is_dir($backupPluginsLocation)) {
      $this->writeLogMessage($updateID, "Copy plugins from $backupPluginsLocation to $destPluginsLocation");
      if (!$this->fsMergeDirs2($backupPluginsLocation, $destPluginsLocation)) {
        $error = $this->getError(UpdateError::ERROR_COPY_PLUGINS, array("source" => $backupPluginsLocation, "destination" => $destPluginsLocation));
        $this->changeStepStatus($updateID, "copy-files", self::STATUS_FAILED, $error);
        return $error;
      }
    }
    
    // ADDING DISPLAY_VERSION TO PACK.JSON
    
    $destPackJson = $this->getRootDestPath("pack.json");
    if (!file_exists($destPackJson) || ($packJsonData = file_get_contents($destPackJson)) === false || ($packJsonObj = json_decode($packJsonData, true)) === false) {
      $error = $this->getError(UpdateError::ERROR_READ_PACK_JSON, array("path" => $destPackJson));
      $this->changeStepStatus($updateID, "copy-files", self::STATUS_FAILED, $error);
      return $error;
    }
    $displayVersion = $this->config->displayVersion;
    $packJsonObj["displayVersion"] = $displayVersion;
    $this->writeLogMessage($updateID, "Saving displayVersion $displayVersion into $destPackJson");
    if (!file_put_contents($destPackJson, json_encode($packJsonObj))) {
      $error = $this->getError(UpdateError::ERROR_WRITE_PACK_JSON, array("path" => $destPackJson));
      $this->changeStepStatus($updateID, "copy-files", self::STATUS_FAILED, $error);
      return $error;
    }
    
    
    $this->changeStepStatus($updateID, "copy-files", self::STATUS_COMPLETED);
    return true;
  }
  
  protected function exitMaintenanceMode($updateID) {
    $this->changeStepStatus($updateID, "exit-maintenance", self::STATUS_PENDING);
    $this->writeLogMessage($updateID, "Exiting from maintenance mode");
    if (!$this->config->exitMaintenanceMode()) {
      $error = $this->getError(UpdateError::ERROR_EXITING_MAINTENANCE_MODE);
      $this->changeStepStatus($updateID, "exit-maintenance", self::STATUS_FAILED, $error);
      return $error;
    }
    $this->changeStepStatus($updateID, "exit-maintenance", self::STATUS_COMPLETED);
    return true;
  }

  public function addPath(&$map, $path, $type, $exists, $read, $write) {
    $key = $type . ":". $path;
    if (isset($map[$key])) {
      $map[$key] = array(
        "path" => $path,
        "type" => $type,
        "exists" => $map[$key]["exists"] || $exists,
        "read" => $map[$key]["read"] || $read,
        "write" => $map[$key]["write"] || $write,
      );
    }
    else {
      $map[$key] = array(
        "path" => $path,
        "type" => $type,
        "exists" => $exists,
        "read" => $read,
        "write" => $write
      );
    }
    $map[$key]["permission"] = $map[$key]["read"] ? "read" . ($map[$key]["write"] ? ",write" : "") : ($map[$key]["write"] ? "write" : "");
  }
  
  protected function checkInstalledFilesPermissions($updateID) {
    $srcPath = $this->getRootSourcePath();
    $destPath = $this->getRootDestPath();
    $updatePath = $this->getRootUpdatePath();
    
    $toCheck = array();
    $this->addPath($toCheck, $srcPath, "dir", true, true, false);
    $this->addPath($toCheck, $destPath, "dir", true, true, true);
    $this->addPath($toCheck, $updatePath, "dir", true, true, true);
    $this->addPath($toCheck, $this->getUpdatesPath(), "dir", true, true, true);
    $this->addPath($toCheck, $this->getUpdatePath($updateID), "dir", true, true, true);
    $this->addPath($toCheck, $this->getUpdatePath($updateID, "completed.txt"), "file", false, true, true);
    $this->addPath($toCheck, $this->getUpdatePath($updateID, "steps.json"), "file", true, true, true);
    $this->addPath($toCheck, $this->getUpdatePath($updateID, "log.txt"), "file", true, true, true);
    $this->addPath($toCheck, $this->getUpdatePath($updateID, "privmx.zip"), "file", false, true, true);
    $this->addPath($toCheck, $this->getUpdatePath($updateID, "privmx"), "dir", false, true, true);
    
    $dirToCheck = array('app', 'install', 'server');
    foreach($dirToCheck as $dir) {
      $this->addPath($toCheck, self::joinPaths($srcPath, $dir), "dir", true, true, false);
      $this->addPath($toCheck, self::joinPaths($destPath, $dir), "dir", false, true, true);
    }
    $filesToCheck = array('index.php', '.htaccess', 'pack.json', 'README.txt', 'LICENSE.txt');
    $optional = array('README.txt', 'LICENSE.txt');
    foreach($filesToCheck as $file) {
      $this->addPath($toCheck, self::joinPaths($srcPath, $file), "file", !in_array($file, $optional), true, false);
      $this->addPath($toCheck, self::joinPaths($destPath, $file), "file", false, true, true);
    }
    $this->addPath($toCheck, $this->config->getDataDirectory(), "dir", true, true, false);
    $this->addPath($toCheck, $this->config->getKeys(), "file", false, true, false);
    
    $errorPaths = array();
    foreach($toCheck as $key => $entry) {
      if (file_exists($entry["path"])) {
        if ($entry["type"] == "dir" && !is_dir($entry["path"])) {
          $errorPaths[$entry["path"]] = "not-dir";
          continue;
        }
        else if ($entry["type"] == "file" && !is_file($entry["path"])) {
          $errorPaths[$entry["path"]] = "not-file";
          continue;
        }
      }
      else {
        if ($entry["exists"]) {
          $errorPaths[$entry["path"]] = "not-exists";
        }
        continue;
      }
      if (!$this->checkFilePermissions($entry["path"], $entry["permission"])) {
        $errorPaths[$entry["path"]] = $entry["permission"];
      }
    }
    
    if (count($errorPaths)) {
      return $this->getError(UpdateError::ERROR_FILE_PERMISSIONS, array("paths" => $errorPaths));
    }
    return null;
  }
  
  /* ====================================================================== */
  
  protected function checkFilePermissions($path, $expected) {
    if ($expected === "write") {
      return is_writable($path);
    }
    if ($expected === "read") {
      return is_readable($path);
    }
    if ($expected === "read,write") {
      return is_readable($path) && is_writable($path);
    }
    return false;
  }
  
  protected function fixDistAppSymlink($updateID) {
    $this->writeLogMessage($updateID, "fixing dist app symlink");
    $appPath = $this->getUpdatePath($updateID, "privmx/app");
    $files = scandir($appPath);
    foreach ($files as $file) {
      $filePath = self::joinPaths($appPath, $file);
      if (is_file($filePath) && filesize($filePath) == 1) {
        $content = file_get_contents($filePath);
        if ($content === ".") {
          $this->writeLogMessage($updateID, "fixing $filePath");
          if (unlink($filePath) && symlink(".", $filePath)) {
            return true;
          }
          $error = $this->getError(UpdateError::ERROR_CANT_FIX_DIST_APP_SYMLINK, array("path" => $filePath));
          return $error;
        }
      }
    }
    $this->writeLogMessage($updateID, "nothing to fix");
    return null;
  }
  
  protected function getUpdatesPath() {
    return $this->getRootUpdatePath("/.updates");
  }

  protected function getUpdatePath($updateID, $path = "") {
    $updatePath = self::joinPaths($this->getUpdatesPath(), $updateID);
    return self::joinPaths($updatePath, $path);
  }
  
  protected function getRootSourcePath($path = "") {
    return self::joinPaths($this->sourceRootPath, $path);
  }
  
  protected function getRootUpdatePath($path = "") {
    return self::joinPaths($this->updateRootPath, $path);
  }
  
  protected function getRootDestPath($path = "") {
    return self::joinPaths($this->destRootPath, $path);
  }
  
  protected function changeStepStatus($updateID, $name, $status, $error = null) {
    $steps = $this->loadSteps($updateID);
    foreach ($steps as &$step) {
      if ($step['name'] === $name) {
        $step['status'] = $status;
        $step['error'] = $error;
        return $this->dumpSteps($updateID, $steps);
      }
    }
    return null;
  }
  
  protected function resetStepStatus($updateID) {
    $steps = $this->loadSteps($updateID);
    foreach ($steps as &$step) {
      $step['status'] = null;
      unset($step['error']);
    }
    return $this->dumpSteps($updateID, $steps);
  }
  
  protected function getError($error, $data = null) {
    return new UpdateError($error, $data);
  }
  
  protected function getErrorResponse($error, $errorData = null) {
    return array(
      "error" => ($error instanceof UpdateError) ? $error : $this->getError($error, $errorData)
    );
  }
  
  protected function isError($thing) {
    return $thing instanceof UpdateError;
  }

  protected function writeLogMessage($updateID, $message) {
    $path = $this->getUpdatePath($updateID, "log.txt");
    return file_put_contents($path, $this->getLogMessage($message), FILE_APPEND);
  }

  protected function getLogMessage($message) {
    $date = date("[Y-m-d H:i:s]");
    return $date . " - " . $message . PHP_EOL;
  }
  
  protected function downloadFile($url, $dest, $auth = false) {
    $file = fopen($dest, "w");
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FAILONERROR, true);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_AUTOREFERER, true);
    curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    if ($this->config->updateIgnoreCertificate) {
      curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0); 
    }
    curl_setopt($ch, CURLOPT_FILE, $file);
    if ($auth) {
      curl_setopt($ch, CURLOPT_USERPWD, $auth); 
    }
    $result = curl_exec($ch);
    if (!$result) {
      return curl_error($ch);
    }
    curl_close($ch);
    return true;
  }
  
  protected function loadSteps($updateID) {
    $path = $this->getUpdatePath($updateID, "steps.json");
    return json_decode(file_get_contents($path), true);
  }
  
  protected function dumpSteps($updateID, $steps) {
    $path = $this->getUpdatePath($updateID, "steps.json");
    $result = file_put_contents($path, json_encode($steps, JSON_PRETTY_PRINT));
    if ($result === false) {
      return new UpdateError(UpdateError::ERROR_DUMP_STEPS, array("path" => $path));
    }
    return null;
  }
  
  protected function fsRemove($path) {
    if (is_link($path)) {
      return unlink($path);
    }
    if (is_dir($path)) {
      $files = scandir($path);
      foreach ($files as $file) {
        if ($file !=  "." && $file != "..") {
          $this->fsRemove(self::joinPaths($path, $file));
        }
      }
      return rmdir($path);
    }
    if (file_exists($path)) {
      return unlink($path);
    }
    return true;
  }
  
  protected function fsCopy($src, $dest) {
    if (file_exists($dest)) {
      $removed = $this->fsRemove($dest);
      if (!$removed) {
        return false;
      }
    }
    if (is_link($src)) {
      return symlink(readlink($src), $dest);
    }
    if (is_dir($src)) {
      if (mkdir($dest)) {
        $files = scandir($src);
        foreach($files as $file) {
          if ($file !=  "." && $file != "..") {
            if (! $this->fsCopy(self::joinPaths($src, $file), self::joinPaths($dest, $file))) {
              return false;
            }
          }
        }
        return true;
      } else {
        return false;
      }
    }
    return copy($src, $dest);
  }

  protected function fsMergeDirs($src, $dest) {
    if (!is_dir($src)) {
      return false;
    }
    if (!file_exists($dest)) {
      $created = mkdir($dest, 0755, true);
      if (!$created) {
        return false;
      }
    } elseif (!is_dir($dest)) {
      return false;
    }
    $files = scandir($src);
    foreach($files as $file) {
      if ($file !=  "." && $file != "..") {
        $srcFilePath = self::joinPaths($src, $file);
        $destFilePath = self::joinPaths($dest, $file);
        if (is_dir($srcFilePath)) {
          if (! $this->fsMergeDirs($srcFilePath, $destFilePath)) {
            return false;
          }
        }
        if (! copy($srcFilePath, $destFilePath)) {
          return false;
        }
      }
    }
    return true;
  }

  protected function fsMergeDirs2($src, $dest) {
    if (!is_dir($src)) {
      return false;
    }
    if (!file_exists($dest)) {
      $created = mkdir($dest, 0755, true);
      if (!$created) {
        return false;
      }
    } elseif (!is_dir($dest)) {
      return false;
    }
    $files = scandir($src);
    foreach($files as $file) {
      if ($file !=  "." && $file != "..") {
        $srcFilePath = self::joinPaths($src, $file);
        $destFilePath = self::joinPaths($dest, $file);
        if (!file_exists($destFilePath)) {
            if (! $this->fsCopy($srcFilePath, $destFilePath)) {
              return false;
            }
        }
      }
    }
    return true;
  }
  
  static function startsWith($haystack, $needle) {
    return $needle === "" || strpos($haystack, $needle) === 0;
  }
  
  static function joinPaths($base, $path) {
    return rtrim($base, '/') . '/' . ltrim($path, '/');
  }
   
}
