<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

use io\privfs\config\Config;

class DatabaseFactory {
    
    private $name;
    private $manager;
    private $encrypt;
    
    public function __construct($name, $manager, $encrypt = true) {
        $this->name = $name;
        $this->manager = $manager;
        $this->encrypt = $encrypt;
    }
    
    public function open($mode) {
        if ($mode[0] == "r") {
            return $this->manager->getDbForRead($this->name, $this->encrypt);
        }
        return $this->manager->getDbForWrite($this->name, $this->encrypt);
    }
    
    public function deleteDbFile() {
        return $this->manager->removeDbFile($this->name);
    }
}

class DbManager {
    
    private $config;
    private $dbs;
    private $lockFlag;
    private $key;
    
    public function __construct(Config $config, $lockFlag = "l") {
        $this->config = $config;
        $this->dbs = array();
        $this->lockFlag = $lockFlag == "l" || $lockFlag == "d" ? $lockFlag : "l";
        $this->key = hex2bin($config->getSymmetric());
    }
    
    public function getFactory($name, $encrypt = true) {
        return new DatabaseFactory($name, $this, $encrypt);
    }
    
    public function getDbFilePath($name) {
        $dataDir = $this->config->getDataDirectory();
        $dbFile = $name . ".db";
        return Utils::joinPaths($dataDir, $dbFile);
    }
    
    public function getDbaReadOnlyMode() {
        return "r" . $this->lockFlag;
    }
    
    public function getDbaReadWriteMode() {
        return "c" . $this->lockFlag;
    }
    
    private function getDbForReadCore($db, $path, $encrypt = true) {
        if (is_null($db) || !$db->isOpened()) {
            if (file_exists($path)) {
                $engine = $this->config->getDatabaseEngine();
                $res = $engine == "ldba"
                    ? new LdbaDatabase($path, $this->getDbaReadOnlyMode())
                    : new DbaDatabase($path, $this->getDbaReadOnlyMode(), $engine);
                return $encrypt && $this->key ? new EncryptedDatabase($res, $this->key) : $res;
            }
            else {
                return $this->getDbForWriteCore($db, $path, $encrypt);
            }
        }
        else {
            $db->increaseReferenceCount();
        }
        return $db;
    }
    
    public function getDbForRead($name, $encrypt = true) {
        $this->dbs[$name] = $this->getDbForReadCore(@$this->dbs[$name], $this->getDbFilePath($name), $encrypt);
        return $this->dbs[$name];
    }
    
    private function getDbForWriteCore($db, $path, $encrypt = true) {
        if (is_null($db) || !$db->isOpened()) {
            $engine = $this->config->getDatabaseEngine();
            $res = $engine == "ldba"
                ? new LdbaDatabase($path, $this->getDbaReadWriteMode())
                : new DbaDatabase($path, $this->getDbaReadWriteMode(), $engine);
            return $encrypt && $this->key ? new EncryptedDatabase($res, $this->key) : $res;
        }
        else {
            if ($db->isReadOnly()) {
                $db->switchToWriteMode();
                $db->increaseReferenceCount();
                return $db;
            }
            else {
                $db->increaseReferenceCount();
            }
        }
        return $db;
    }
    
    public function getDbForWrite($name, $encrypt = true) {
        $this->dbs[$name] = $this->getDbForWriteCore(@$this->dbs[$name], $this->getDbFilePath($name), $encrypt);
        return $this->dbs[$name];
    }
    
    public function closeDb($db) {
        if ($db->close()) {
            foreach ($this->dbs as $n => $d) {
                if ($db === $d) {
                    $name = $n;
                    break;
                }
            }
            if (isset($name)) {
                unset($this->dbs[$name]);
            }
        }
    }
    
    public function closeDbByName($name) {
        if (isset($this->dbs[$name]) && $this->dbs[$name]->close()) {
            unset($this->dbs[$name]);
        }
    }
    
    public function close() {
        foreach ($this->dbs as $i => $db) {
            $db->forceClose();
        }
        $this->dbs = array();
    }
    
    public function removeDbFile($name) {
        $path = $this->getDbFilePath($name);
        if (file_exists($path)) {
            return unlink($path);
        }
        return true;
    }
    
    public function copyDbFile($name, $dstPath) {
        $path = $this->getDbFilePath($name);
        copy($path, $dstPath);
    }
}
