<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

use io\privfs\config\Config;

class Cache {
    
    private $name;
    private $dbManager;
    private $config;
    
    public function __construct($name, DbManager $dbManager, Config $config) {
        $this->name = $name;
        $this->dbManager = $dbManager;
        $this->config = $config;
    }
    
    public function open($mode) {
        return new JsonDatabase($this->dbManager->getDbFilePath($this->name), $mode, hex2bin($this->config->getSymmetric()));
    }
    
    public function set($key, $value) {
        $db = $this->open("w");
        $db->set($key, $value);
        $db->close();
    }
    
    public function get($key) {
        $db = $this->open("r");
        $value = $db->get($key);
        $db->close();
        return $value;
    }
    
    public function remove($key) {
        $db = $this->open("w");
        $db->remove($key);
        $db->close();
    }
}
