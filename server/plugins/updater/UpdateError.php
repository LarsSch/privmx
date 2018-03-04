<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\plugin\updater;

class UpdateError {
  
  const ERROR_FILE_PERMISSIONS = 'ERROR_FILE_PERMISSIONS';
  const ERROR_CREATE_UPDATE_DIR = 'ERROR_CREATE_UPDATE_DIR';
  const ERROR_UNKNOWN_UPDATE_ID = 'ERROR_UNKNOWN_UPDATE_ID';
  const ERROR_WRITE_HTACCESS_FILE = 'ERROR_WRITE_HTACCESS_FILE';
  const ERROR_WRITE_VERSION_FILE = 'ERROR_WRITE_VERSION_FILE';
  const ERROR_WRITE_LOG_FILE = 'ERROR_WRITE_LOG_FILE';
  const ERROR_WRITE_DATA_FILE = 'ERROR_WRITE_DATA_FILE';
  const ERROR_VERSION_NOT_FOUND = 'ERROR_VERSION_NOT_FOUND';
  const ERROR_CANT_DOWNLOAD_ZIP = 'ERROR_CANT_DOWNLOAD_ZIP';
  const ERROR_INVALID_CHECKSUM = 'ERROR_INVALID_CHECKSUM';
  const ERROR_MISSING_CHECKSUM = 'ERROR_MISSING_CHECKSUM';
  const ERROR_CANT_OPEN_ZIP = 'ERROR_CANT_OPEN_ZIP';
  const ERROR_CANT_UNZIP = 'ERROR_CANT_UNZIP';
  const ERROR_CANT_CLOSE_ZIP = 'ERROR_CANT_CLOSE_ZIP';
  const ERROR_DIST_NOT_FOUND = 'ERROR_DIST_NOT_FOUND';
  const ERROR_MISSING_SOME_DIST_FILES = 'ERROR_MISSING_SOME_DIST_FILES';
  const ERROR_CREATE_STEPS_FILE = 'ERROR_CREATE_STEPS_FILE';
  const ERROR_ENTERING_MAINTENANCE_MODE = 'ERROR_ENTERING_MAINTENANCE_MODE';
  const ERROR_EXITING_MAINTENANCE_MODE = 'ERROR_EXITING_MAINTENANCE_MODE';
  const ERROR_REMOVING_EXISTING_BACKUP_DIR = 'ERROR_REMOVING_EXISTING_BACKUP_DIR';
  const ERROR_CREATING_BACKUP_DIR = 'ERROR_CREATING_BACKUP_DIR';
  const ERROR_BACKUP_FILES = 'ERROR_BACKUP_FILES';
  const ERROR_BACKUP_DATA = 'ERROR_BACKUP_DATA';
  const ERROR_COPY_FILES = 'ERROR_COPY_FILES';
  const ERROR_COPY_DATA = 'ERROR_COPY_DATA';
  const ERROR_COPY_APP_LIB = 'ERROR_COPY_APP_LIB';
  const ERROR_REMOTE_CHECK_VERSION = 'ERROR_REMOTE_CHECK_VERSION';
  const ERROR_FETCH_URL = 'ERROR_FETCH_URL';
  const ERROR_CANT_FIX_DIST_APP_SYMLINK = 'ERROR_CANT_FIX_DIST_APP_SYMLINK';
  const ERROR_CANT_REMOVE_OLD_DIST = 'ERROR_CANT_REMOVE_OLD_DIST';
  const ERROR_BACKUP_CONFIG = 'ERROR_BACKUP_CONFIG';
  const ERROR_COPY_CONFIG = 'ERROR_COPY_CONFIG';
  const ERROR_BACKUP_KEYS = 'ERROR_BACKUP_KEYS';
  const ERROR_COPY_KEYS = 'ERROR_COPY_KEYS';
  const ERROR_COPY_CALLBACKS = 'ERROR_COPY_CALLBACKS';
  const ERROR_ZIPARCHIVE_CLASS_NOT_FOUND = 'ERROR_ZIPARCHIVE_CLASS_NOT_FOUND';
  const ERROR_UPDATE_FAILED = 'ERROR_UPDATE_FAILED';
  const ERROR_DUMP_STEPS = 'ERROR_DUMP_STEPS';
  const ERROR_PRE_SCRIPT = 'ERROR_PRE_SCRIPT';
  const ERROR_POST_SCRIPT = 'ERROR_POST_SCRIPT';
  const ERROR_INVALID_INFO = 'ERROR_INVALID_INFO';
  const ERROR_COPY_UPDATE_APP = 'ERROR_COPY_UPDATE_APP';
  
  public $name;
  public $data;
  public $lastPhpError;
  
  public function __construct($name, $data) {
    $this->name = $name;
    $this->data = $data;
    $this->lastPhpError = self::getLastPhpError();
  }
  
  public static function clearLastPhpError() {
    if (function_exists("error_clear_last")) {
      error_clear_last();
    } else {
      set_error_handler("var_dump", 0);
      @trigger_error("error_clear_last_polyfill");
      restore_error_handler();
    }
  }
  
  public static function getLastPhpError() {
    $error = error_get_last();
    if (is_array($error) && $error["message"] != "error_clear_last_polyfill") {
      $error["type"] = self::getFriendlyErrorType($error["type"]);
      return $error;
    }
    return null;
  }
  
  public static function getFriendlyErrorType($type) {
    $x = get_defined_constants(true)['Core'];
    $errors = array();
    foreach ($x as $k => $v) {
      if (strpos($k, "E_") === 0) {
        $errors[$v] = $k;
      }
    }
    return isset($errors[$type]) ? $errors[$type] : $type;
  }
  
}
