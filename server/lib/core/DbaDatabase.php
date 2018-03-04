<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

use Exception;

class DbaDatabase {
    
    private $path;
    private $mode;
    private $engine;
    private $handler;
    private $readOnly;
    private $referenceCount;
    
    public function __construct($path, $mode, $engine) {
        $this->path = $path;
        $this->mode = $mode;
        $this->engine = $engine;
        $this->handler = dba_open($this->path, $this->mode, $this->engine);
        if ($this->handler === false) {
            throw new \Exception("Cannot open database " . $this->path);
        }
        $this->readOnly = $this->mode[0] == "r";
        $this->referenceCount = 1;
    }
    
    public function switchToWriteMode() {
        if (!$this->readOnly) {
            return;
        }
        $this->mode = "c" . substr($this->mode, 1);
        $this->readOnly = false;
        dba_close($this->handler);
        $this->handler = dba_open($this->path, $this->mode, $this->engine);
        if ($this->handler === false) {
            throw new \Exception("Cannot open database " . $this->path);
        }
    }
    
    public function increaseReferenceCount() {
        $this->referenceCount++;
    }
    
    public function isOpened() {
        return $this->referenceCount > 0;
    }
    
    public function isReadOnly() {
        return $this->readOnly;
    }
    
    public function isReadWrite() {
        return !$this->readOnly;
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
    
    public function getOrDefault($key, $default) {
        if ($this->exists($key)) {
            return $this->fetch($key);
        }
        return $default;
    }
    
    public function insert($key, $data) {
        return dba_insert($key, $data, $this->handler);
    }
    
    public function replace($key, $data) {
        return dba_replace($key, $data, $this->handler);
    }
    
    public function update($key, $data) {
        if ($this->exists($key)) {
            $this->replace($key, $data);
        }
        else {
            $this->insert($key, $data);
        }
    }
    
    public function exists($key) {
        return dba_exists($key, $this->handler);
    }
    
    public function delete($key) {
        return dba_delete($key, $this->handler);
    }
    
    public function close() {
        if ($this->referenceCount > 0) {
            $this->referenceCount--;
        }
        if ($this->referenceCount == 0) {
            if ($this->handler !== null) {
                dba_close($this->handler);
                $this->handler = null;
            }
            return true;
        }
        return false;
    }
    
    public function forceClose() {
        if ($this->handler === null) {
            return;
        }
        dba_close($this->handler);
        $this->handler = null;
        $this->referenceCount = 0;
    }
}