<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

class Lock {
    
    private $fp;
    
    public function __construct(DbManager $dbManager) {
        $this->fp = fopen($dbManager->getDbFilePath("flock"), "w+");
        if ($this->fp === false) {
            throw new \Exception("Cannot open lock file");
        }
    }
    
    public function reader() {
        if (flock($this->fp, LOCK_SH) === false) {
            throw new \Exception("Cannot lock for read");
        }
    }
    
    public function writer() {
        if (flock($this->fp, LOCK_EX) === false) {
            throw new \Exception("Cannot lock for write");
        }
    }
    
    public function try_writer() {
        return flock($this->fp, LOCK_EX | LOCK_NB);
    }
    
    public function release() {
        if (flock($this->fp, LOCK_UN) === false) {
            throw new \Exception("Cannot release lock");
        }
    }
}
