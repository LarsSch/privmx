<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\plugin\updater;
use io\privfs\core\Utils;
use io\privfs\core\Settings;

class UpdateService {
  const STATUS_COMPLETED = "COMPLETED";
  const STATUS_PENDING = "PENDING";
  const STATUS_FAILED = "FAILED";
  const LAST_UPDATE_SETTINGS_KEY = "updaterLastUpdate";
  const LAST_SEEN_SETTINGS_KEY = "updaterLastSeen";
  const LAST_USED_CHANNEL_URL = "updaterLastUsedChannelUrl";
  
  protected $config;
  protected $settings;
  protected $rootPath;
  protected $auth = false;
  protected $sourceRootPath;
  protected $updateRootPath;
  protected $destRootPath;
  
  public function __construct($config, Settings $settings) {
    $this->config = $config;
    $this->settings = $settings;
    $this->sourceRootPath = realpath(__DIR__ . "/../../..");
    $this->updateRootPath = $this->sourceRootPath;
    $this->destRootPath = $this->sourceRootPath;
  }
  
  /* ====================================================================== */
  
  public function getUserConfig() {
    $this->checkChannelUrl();
    $pack = $this->getInstalledPackInfo();
    $lastUpdate = $this->settings->getSetting(self::LAST_UPDATE_SETTINGS_KEY);
    $lastSeenUpdate = $this->settings->getSetting(self::LAST_SEEN_SETTINGS_KEY);
    return array(
      "pack" => $pack,
      "lastSeenUpdate" => is_null($lastSeenUpdate) ? $pack['version'] : $lastSeenUpdate,
      "lastUpdate" => is_null($lastUpdate) ? null : json_decode($lastUpdate, true)
    );
  }
  
  public function setLastSeenUpdate($version) {
    $this->settings->setSetting(self::LAST_SEEN_SETTINGS_KEY, $version);
    return "OK";
  }
  
  public function checkChannelUrl() {
    if ($this->isChannelUrlChanged()) {
      $this->clearLastUpdate();
    }
  }
  
  public function isChannelUrlChanged() {
    return $this->config->updatesChannel != $this->settings->getSetting(self::LAST_USED_CHANNEL_URL);
  }
  
  public function clearLastUpdate() {
    $this->settings->setSetting(self::LAST_USED_CHANNEL_URL, $this->config->updatesChannel);
    return $this->settings->deleteSetting(self::LAST_UPDATE_SETTINGS_KEY);
  }
  
  public function checkPackVersionStatus($setLastSeen) {
    $this->checkChannelUrl();
    $info = $this->getInstalledPackInfo();
    $result = array(
      "pack" => $info
    );
    if ($info) {
      $url = $this->getCheckUrl($info['version']);
      $data = $this->fetchUrl($url, $this->auth);
      if ($data === false) {
        $result['error'] = $this->getError(UpdateError::ERROR_FETCH_URL, array("url" => $url));
      } else {
        $rawData = $data;
        $data = json_decode($rawData, true);
        if (is_array($data)) {
          $result['update'] = $data;
          if (isset($data['availableUpdate']) && $data['availableUpdate']) {
            if ($setLastSeen) {
              $this->settings->setSetting(self::LAST_SEEN_SETTINGS_KEY, $result['availableUpdate']['version']);
            }
          }
          else {
            if ($setLastSeen) {
              $this->settings->setSetting(self::LAST_SEEN_SETTINGS_KEY, $info['version']);
            }
          }
          $this->settings->setSetting(self::LAST_UPDATE_SETTINGS_KEY, $rawData);
        } else {
          $result['error'] = $this->getError(UpdateError::ERROR_REMOTE_CHECK_VERSION);
        }  
      }
    }
    return $result;
  }
  
  public function getUpdateVersionDetails($version) {
    $url = $this->getInfoUrl($version);
    $data = $this->fetchUrl($url, $this->auth);
    if ($data === false) {
      return $this->getErrorResponse(UpdateError::ERROR_FETCH_URL, array("url" => $url));
    }
    return array(
      "current" => $this->getInstalledPackInfo(),
      "update" => json_decode($data, true)
    );
  }
  
  public function init($version) {
    $checkResult = $this->checkInstalledFilesPermissions();
    if ($checkResult && $this->isError($checkResult)) {
      return $this->getErrorResponse($checkResult);
    }
    $url = $this->getInfoUrl($version);
    $infoData = $this->fetchUrl($url, $this->auth);
    if ($infoData === false) {
      return $this->getErrorResponse(UpdateError::ERROR_FETCH_URL, array("url" => $url));
    }
    $info = json_decode($infoData, true);
    if ($info === false || !is_array($info) || !isset($info["info"]) || !is_string($info["info"]) || !isset($info["hash"]) || !is_string($info["hash"])) {
      return $this->getErrorResponse(UpdateError::ERROR_INVALID_INFO, array("url" => $url, "info" => $infoData));
    }
    $updateID = $this->generateUpdateID($version);
    $path = $this->getUpdatePath($updateID);
    if (! is_dir($path)) {
      if (! mkdir($path, 0755, true)) {
        return $this->getErrorResponse(UpdateError::ERROR_CREATE_UPDATE_DIR, array("path" => $path));
      }
    }
    $htaccessPath = $this->getUpdatePath($updateID, ".htaccess");
    if (! file_put_contents($htaccessPath, self::getUpdateHtaccessContent())) {
      return $this->getErrorResponse(UpdateError::ERROR_WRITE_HTACCESS_FILE, array("path" => $htaccessPath));
    }
    /*$versionFilePath = $this->getUpdatePath($updateID, "version.txt");
    if (! file_put_contents($versionFilePath, $version)) {
      return $this->getErrorResponse(UpdateError::ERROR_WRITE_VERSION_FILE, array("path" => $versionFilePath));
    }*/
    $logFilePath = $this->getUpdatePath($updateID, "log.txt");
    if (! file_put_contents($logFilePath, $this->getLogMessage("init"))) {
      return $this->getErrorResponse(UpdateError::ERROR_WRITE_LOG_FILE, array("path" => $logFilePath));
    }
    $dataFilePath = $this->getUpdatePath($updateID, "data.php");
    $token = bin2hex(openssl_random_pseudo_bytes(16));
    $data = array(
      "token" => $token,
      "version" => $version,
      "displayVersion" => $info["displayVersion"],
      "majorVersion" => $info["majorVersion"],
      "buildId" => $info["buildId"],
      "downloadUrl" => $this->getDownloadUrl($version),
      "info" => $info["info"],
      "hash" => $info["hash"],
      "loginUrl" => $this->config->getClientUrl(),
      "updateId" => $updateID,
      "updateIgnoreCertificate" => $this->config->updateIgnoreCertificate,
      "dataDirectory" => $this->config->getDataDirectory(),
      "keys" => $this->config->getKeys(),
      "maintenanceLockPath" => $this->getRootDestPath("maintenance.lock"),
      "sourceRootPath" => $this->sourceRootPath,
      "updateRootPath" => $this->updateRootPath,
      "destRootPath" => $this->destRootPath
    );
    if (! file_put_contents($dataFilePath, Utils::dumpPhpFile($data))) {
      return $this->getErrorResponse(UpdateError::ERROR_WRITE_DATA_FILE, array("path" => $dataFilePath));
    }
    $srcApp = $this->getRootSourcePath("server/plugins/updater/app");
    $destApp = $this->getUpdatePath($updateID, "app");
    if (!$this->fsCopy($srcApp, $destApp)) {
      return $this->getErrorResponse(UpdateError::ERROR_COPY_UPDATE_APP, array("src" => $srcApp, "dest" => $destApp));
    }
    $prepareStepsResult = $this->prepareSteps($updateID);
    if ($this->isError($prepareStepsResult)) {
      return $this->getErrorResponse($prepareStepsResult);
    }
    $this->changeStepStatus($updateID, "init", self::STATUS_PENDING);
    $status = $this->getUpdateStatus($updateID);
    return array(
      "updateID" => $updateID,
      "token" => $token,
      "version" => $version,
      "status" => $status,
      "updaterUrl" => $this->getUpdaterUrl($updateID, $token)
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
  
  public function getInstalledPackInfo() {
    $path = $this->getRootSourcePath("pack.json");
    if (file_exists($path)) {
      $data = file_get_contents($path);
      if ($data !== FALSE) {
        $decoded = json_decode($data, true);
        $splittedVersion = explode(".", $decoded["version"]);
        $majorVersion = implode(".", array_slice($splittedVersion, 0, 3));
        if (!isset($decoded["displayVersion"])) {
            $decoded["displayVersion"] = $majorVersion;
        }
        $decoded["majorVersion"] = $majorVersion;
        $decoded["buildId"] = $splittedVersion[3];
        $decoded["customUpdatesChannel"] = $this->config->customUpdatesChannel;
        $decoded["updatesChannel"] = $this->config->updatesChannel;
        return $decoded;
      }
    }
    return null;
  }
  
  /* ====================================================================== */
  
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

  protected function checkInstalledFilesPermissions() {
    $srcPath = $this->getRootSourcePath();
    $destPath = $this->getRootDestPath();
    $updatePath = $this->getRootUpdatePath();
    
    $toCheck = array();
    $this->addPath($toCheck, $srcPath, "dir", true, true, false);
    $this->addPath($toCheck, $destPath, "dir", true, true, true);
    $this->addPath($toCheck, $updatePath, "dir", true, true, true);
    $this->addPath($toCheck, $this->getUpdatesPath(), "dir", false, true, true);
    
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
  
  protected function getCheckUrl($version) {
    $query = http_build_query(array(
      "v" => $version
    ));
    return self::joinPaths($this->config->updatesChannel, "/check?" . $query);
  }
  
  protected function getDownloadUrl($version) {
    $query = http_build_query(array(
      "v" => $version
    ));
    return self::joinPaths($this->config->updatesChannel, "/download?" . $query);
  }

  protected function getInfoUrl($version) {
    $query = http_build_query(array(
      "v" => $version
    ));
    return self::joinPaths($this->config->updatesChannel, "/info?" . $query);
  }
  
  protected function generateUpdateID($version) {
    return date("YmdHis") . "_" . $version;
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
  
  protected function prepareSteps($updateID) {
    $steps = array(
      $this->createStep("init"),
      $this->createStep("download-zip"),
      $this->createStep("validate-zip"),
      $this->createStep("extract-files"),
      $this->createStep("validate-files"),
      $this->createStep("enter-maintenance"),
      $this->createStep("make-backup"),
      $this->createStep("copy-files"),
      $this->createStep("exit-maintenance")
    );
    return $this->dumpSteps($updateID, $steps);
  }
  
  protected function createStep($name) {
    return array(
      "name" => $name,
      "status" => null
    );
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

  protected function getLogMessage($message) {
    $date = date("[Y-m-d H:i:s]");
    return $date . " - " . $message . PHP_EOL;
  }
  
  protected function fetchUrl($url, $auth = false) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,$url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    if ($this->config->updateIgnoreCertificate) {
      curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    }
    if ($auth) {
      curl_setopt($ch, CURLOPT_USERPWD, $auth); 
    }
    $result = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) {
      return false;
    }
    return $result;
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
  
  protected function getUpdaterUrl($updateID, $token) {
    $serverUrl = $this->config->getInstanceUrl();
    $splitted = explode("/", $serverUrl);
    $splitted = array_slice($splitted, 0, $splitted[count($splitted) - 1] == "" ? -2 : -1);
    $baseUrl = implode("/", $splitted);
    return Utils::concatUrl($baseUrl, "/.updates/$updateID/app/?token=" . $token);
  }
  
  static function startsWith($haystack, $needle) {
    return Utils::startsWith($haystack, $needle);
  }
  
  static function joinPaths($base, $path) {
    return Utils::joinPaths($base, $path);
  }
  
  static function getUpdateHtaccessContent() {
    return 'Deny from all';
  }
   
}
