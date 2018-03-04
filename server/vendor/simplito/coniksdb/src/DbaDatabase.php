<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki;

class DbaDatabase {
    
    public function __construct($path, $mode, $engine) {
        $this->handler = dba_open($this->path, $this->mode, $this->engine);
        if ($this->handler === false) {
            throw new \Exception("Cannot open database " . $this->path);
        }
    }
    
    public function firstKey() {
        return dba_firstkey($this->handler);
    }
    
    public function nextKey() {
        return dba_nextkey($this->handler);
    }
    
    public function fetch($key) {
        return dba_fetch($key, $this->handler);
    }
    
    public function insert($key, $data) {
        return dba_insert($key, $data, $this->handler);
    }
    
    public function replace($key, $data) {
        return dba_replace($key, $data, $this->handler);
    }
    
    public function exists($key) {
        return dba_exists($key, $this->handler);
    }
    
    public function delete($key) {
        return dba_delete($key, $this->handler);
    }
    
    public function close() {
        dba_close($this->handler);
    }
}