<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

class LogDb {
    
    const INDEX_KEY = "index";
    
    private $name;
    private $dbManager;
    
    public function __construct($name, DbManager $dbManager) {
        $this->name = $name;
        $this->dbManager = $dbManager;
    }
    
    public function init($readOnly) {
        $db = $readOnly ? $this->dbManager->getDbForRead($this->name) : $this->dbManager->getDbForWrite($this->name);
        if (!$db->exists(static::INDEX_KEY)) {
            $db->insert(static::INDEX_KEY, "0");
        }
        return $db;
    }
    
    public function close() {
        $this->dbManager->closeDbByName($this->name);
    }
    
    public function add($entry) {
        $db = $this->init(false);
        $index = intval($db->fetch(static::INDEX_KEY));
        $db->insert($index, json_encode($entry));
        $index++;
        $db->replace(static::INDEX_KEY, $index);
        $this->close();
    }
    
    public function getLast($count) {
        $db = $this->init(true);
        $index = intval($db->fetch(static::INDEX_KEY));
        $beg = $count < 0 || $count > $index ? 0 : $index - $count;
        return $this->fetch($db, $beg, $index, $index);
    }
    
    public function getPage($beg, $end) {
        $db = $this->init(true);
        $index = intval($db->fetch(static::INDEX_KEY));
        if ($beg < 0) {
            $beg = 0;
        }
        if ($end < 0) {
            $end = 0;
        }
        if ($end < $beg) {
            $end = $beg;
        }
        if ($beg > $index) {
            $beg = $index;
        }
        if ($end > $index) {
            $end = $index;
        }
        return $this->fetch($db, $beg, $end, $index);
    }
    
    private function fetch($db, $beg, $end, $count) {
        $result = array("count" => $count, "entries" => array());
        for ($i = $beg; $i < $end; $i++) {
            array_push($result["entries"], json_decode($db->fetch($i), true));
        }
        $this->close();
        return $result;
    }
}
