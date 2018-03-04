<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

use Exception;

class EncryptedDatabase {

    private $db;
    private $key;
    private $cache; // encrypted keys cache

    public function __construct($db, $key) {
        $this->db = $db;
        $this->key = $key;
        $this->cache = array();
    }

    private function encrypt($value, $cache = false) {
        if ($value === false) {
            return $value;
        }
        if ($cache === true) {
            if (isset($this->cache[$value])) {
                return $this->cache[$value];
            }
            $iv = substr(Crypto::sha256($value), 0, 16);
        }
        else {
            $iv = Crypto::randomBytes(16);
        }
        $cipher = $iv . Crypto::aes256CbcPkcs7Encrypt($value, $this->key, $iv);
        if ($cache === true) {
            $this->cache[$value] = $cipher;
        }
        return $cipher;
    }

    private function decrypt($value, $cache = false) {
        if ($value === false) {
            return $value;
        }
        if ($cache === true) {
            foreach($this->cache as $plain => $cipher) {
                if ($cipher === $value) {
                    return $plain;
                }
            }
        }
        $iv = substr($value, 0, 16);
        $cipher = substr($value, 16);
        $result = Crypto::aes256CbcPkcs7Decrypt($cipher, $this->key, $iv);
        if ($cache === true) {
            $this->cache[$result] = $value;
        }
        return $result;
    }
    
    public function switchToWriteMode() {
        return $this->db->switchToWriteMode();
    }
    
    public function increaseReferenceCount() {
        $this->db->increaseReferenceCount();
    }
    
    public function isOpened() {
        return $this->db->isOpened();
    }
    
    public function isReadOnly() {
        return $this->db->isReadOnly();
    }
    
    public function isReadWrite() {
        return $this->db->isReadWrite();
    }
    
    public function firstKey() {
        return $this->decrypt($this->db->firstKey(), true);
    }
    
    public function nextKey() {
        return $this->decrypt($this->db->nextKey(), true);
    }
    
    public function fetch($key) {
        return $this->decrypt($this->db->fetch($this->encrypt($key, true)));
    }
    
    public function getOrDefault($key, $default) {
        if ($this->exists($key)) {
            return $this->fetch($key);
        }
        return $default;
    }
    
    public function insert($key, $data) {
        return $this->db->insert($this->encrypt($key, true), $this->encrypt($data));
    }
    
    public function replace($key, $data) {
        return $this->db->replace($this->encrypt($key, true), $this->encrypt($data));
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
        return $this->db->exists($this->encrypt($key, true));
    }
    
    public function delete($key) {
        return $this->db->delete($this->encrypt($key, true));
    }
    
    public function close() {
        return $this->db->close();
    }
    
    public function forceClose() {
        return $this->db->forceClose();
    }
}