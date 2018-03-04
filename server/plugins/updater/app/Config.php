<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

class Config {
    
    public $updateIgnoreCertificate;
    public $dataDirectory;
    public $keys;
    public $maintenanceLockPath;
    public $sourceRootPath;
    public $updateRootPath;
    public $destRootPath;
    public $updateId;
    public $zipHash;
    public $version;
    public $displayVersion;
    public $majorVersion;
    public $buildId;
    public $downloadUrl;
    
    public function __construct($data) {
        $this->updateIgnoreCertificate = $data["updateIgnoreCertificate"];
        $this->dataDirectory = $data["dataDirectory"];
        $this->keys = $data["keys"];
        $this->maintenanceLockPath = $data["maintenanceLockPath"];
        $this->sourceRootPath = $data["sourceRootPath"];
        $this->updateRootPath = $data["updateRootPath"];
        $this->destRootPath = $data["destRootPath"];
        $this->updateId = $data["updateId"];
        $this->zipHash = $data["hash"];
        $this->version = $data["version"];
        $this->displayVersion = $data["displayVersion"];
        $this->majorVersion = $data["majorVersion"];
        $this->buildId = $data["buildId"];
        $this->downloadUrl = $data["downloadUrl"];
    }
    
    public function getDataDirectory() {
        return $this->dataDirectory;
    }
    
    public function getKeys() {
        return $this->keys;
    }
    
    protected function getMaintenanceLockPath() {
      return $this->maintenanceLockPath;
    }
    
    public function isMaintenanceModeEnabled() {
        $path = $this->getMaintenanceLockPath();
        return file_exists($path);
    }
    
    public function enterMaintenanceMode() {
      $path = $this->getMaintenanceLockPath();
      if (!file_exists($path)) {
        return false !== file_put_contents($path, "1");
      }
      return true;
    }
    
    public function exitMaintenanceMode() {
      $path = $this->getMaintenanceLockPath();
      if (file_exists($path)) {
        return false !== unlink($path);
      }
      return true;
    }
}