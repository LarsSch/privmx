<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki;

class DatabaseFactory {
    
    private $path;
    private $handler;
    private $lockFlag = "l";

    public function __construct($path, $engine = "db4", $lockFlag = "l") {
        $this->path = $path;
        $this->engine = $engine;
        $this->lockFlag = $lockFlag == "l" || $lockFlag == "d" ? $lockFlag : "l";
    }
    
    private function getMode($mode) {
        if (!is_string($mode)) {
            return "r" . $this->lock_flag; // readonly
        }
        $mode = $mode[0];
        if ($mode != "c" && $mode != "w" && $mode != "r") {
            $mode = "r";
        }
        return $mode . $this->lock_flag;
    }
    
    public function open($mode) {
        return new DbaDatabase($this->path, $this->engine, $this->getMode($mode));
    }
    
    public function deleteDbFile() {
        return unlink($this->path);
    }
}