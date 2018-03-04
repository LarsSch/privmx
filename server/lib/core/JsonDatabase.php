<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

class JsonDatabase {
    
    private $path;
    private $mode;
    private $key;
    private $handle;
    public $data;
    
    public function __construct($path, $mode, $key = null) {
        if ($mode != "r" && $mode != "w") {
            throw new \Exception("Invalid mode");
        }
        $this->path = $path;
        $this->mode = $mode;
        $this->key = $key;
        $this->handle = fopen($this->path, "c+");
        if ($this->handle === false) {
            throw new \Exception("Cannot open file " . $this->path . " with mode " . $this->mode);
        }
        if (flock($this->handle, $this->mode == "r" ? LOCK_SH : LOCK_EX) === false) {
            throw new \Exception("Cannot lock file " . $this->path . " with mode " . $this->mode);
        }
        $content = stream_get_contents($this->handle);
        if ($content === false) {
            throw new \Exception("Cannot read file " . $this->path . " with mode " . $this->mode);
        }
        if ($content != "" && $this->key) {
            $iv = substr($content, 0, 16);
            $cipher = substr($content, 16);
            $content = Crypto::aes256CbcPkcs7Decrypt($cipher, $this->key, $iv);
            if ($content === false) {
                throw new \Exception("Cannot decrypt file " . $this->path . " with mode " . $this->mode);
            }
        }
        $this->data = $content == "" ? array() : json_decode($content, true);
        if ($this->data === false || $this->data === null || !is_array($this->data)) {
            throw new \Exception("Cannot decode json from file " . $this->path . " with mode " . $this->mode);
        }
    }
    
    public function has($key) {
        return isset($this->data[$key]);
    }
    
    public function get($key) {
        return isset($this->data[$key]) ? $this->data[$key] : null;
    }
    
    public function set($key, $value) {
        $this->data[$key] = $value;
    }
    
    public function remove($key) {
        if (isset($this->data[$key])) {
            unset($this->data[$key]);
        }
    }
    
    public function flush() {
        if ($this->mode != "w") {
            throw new \Exception("Cannot flush file " . $this->path . " with mode " . $this->mode);
        }
        $content = json_encode($this->data);
        if ($content === false) {
            throw new \Exception("Cannot encode data as json for file " . $this->path . " with mode " . $this->mode);
        }
        if ($this->key) {
            $iv = Crypto::randomBytes(16);
            $cipher = Crypto::aes256CbcPkcs7Encrypt($content, $this->key, $iv);
            if ($cipher === false) {
                throw new \Exception("Cannot encrypt data for file " . $this->path . " with mode " . $this->mode);
            }
            $content = $iv . $cipher;
        }
        if (fseek($this->handle, 0) !== 0) {
            throw new \Exception("Cannot seek file " . $this->path . " with mode " . $this->mode);
        }
        if (ftruncate($this->handle, strlen($content)) === false) {
            throw new \Exception("Cannot truncate file " . $this->path . " with mode " . $this->mode);
        }
        if (fwrite($this->handle, $content) === false) {
            throw new \Exception("Cannot write file " . $this->path . " with mode " . $this->mode);
        }
    }
    
    public function close() {
        if ($this->mode == "w") {
            $this->flush();
        }
        if (!fclose($this->handle)) {
            throw new \Exception("Cannot close file " . $this->path . " with mode " . $this->mode);
        }
    }
}
