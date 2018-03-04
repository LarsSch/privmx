<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki;

use Exception;
use Doctrine\Common\Cache\CacheProvider;

class KVStore extends CacheProvider {
    
    const DATA_FIELD = "value";
    const EXPIRATION_FIELD = "expires";
    
    private $dbFactory;
    private $referenceCount;

    public function __construct($dbFactory) {
        $this->dbFactory = $dbFactory;
        $this->referenceCount = 0;
        $this->create();
    }
    
    private function create() {
        $this->open("c");
        $this->close();
    }
    
    private function open($mode = "r") {
        if ($this->db === null) {
            $this->db = $this->dbFactory->open($mode);
            $this->referenceCount = 1;
        }
        else {
            $this->referenceCount++;
        }
    }
    
    private function close() {
        if ($this->referenceCount > 0) {
            $this->referenceCount--;
        }
        if ($this->referenceCount == 0 && $this->db !== null) {
            $this->db->close();
            $this->db = null;
        }
    }
    
    private function forceClose() {
        $this->referenceCount = 0;
        if ($this->db !== null) {
            $this->db->close();
            $this->db = null;
        }
    }
    
    private static function isExpired($value) {
        return (
            isset($value[self::EXPIRATION_FIELD]) &&
            $value[self::EXPIRATION_FIELD] < time()
        );
    }
    
    private function load($id) {
        $this->open("r");
        $arr = $this->db->fetch($id);
        $this->close();
        if (!$arr) {
            return false;
        }
        $arr = unserialize($arr);
        return $arr;
    }
    
    protected function doFetch($id) {
        $arr = $this->load($id);
        if (!$arr || self::isExpired($arr)) {
            return false;
        }
        return $arr[self::DATA_FIELD];
    }
    
    protected function doFetchMultiple(array $keys) {
        return $this->withContext(function() use ($keys) {
            $result = array();
            foreach($keys as $key) {
                $item = $this->doFetch($key);
                if ($item !== false || $this->doContains($key)) {
                    $result[$key] = $item;
                }
            }
            return $result;
        });
    }
    
    protected function doContains($id) {
        $this->open("r");
        $result = $this->db->exists($id);
        $this->close();
        return $result;
    }
    
    protected function doSave($id, $data, $lifetime = 0) {
        $arr = array(
            self::DATA_FIELD => $data,
            self::EXPIRATION_FIELD => $lifetime === 0 ? null : time() + $lifetime
        );
        $arr = serialize($arr);
        
        $this->open("w");
        $result = $this->db->replace($id, $arr);
        $this->close();
        return $result;
    }
    
    protected function doSaveMultiple(array $keysAndValues, $lifetime = 0) {
        return $this->withContext(function() use ($keysAndValues, $lifetime) {
            $success = true;
            foreach($keysAndValues as $key => $value) {
                if (!$this->doSave($key, $value, $lifetime)) {
                    $success = false;
                }
            }
            return $success;
        }, "w");
    }
    
    protected function doDelete($id) {
        $this->open("w");
        $result = $this->db->delete($id);
        $this->close();
        return $result;
    }
    
    protected function doFlush() {
        $this->forceClose();
        if (!$this->dbFactory->deleteDbFile()) {
            return false;
        }
        $this->create();
        return true;
    }
    
    protected function doGetStats() {
        return null;
    }
    
    public function withContext($funct, $mode = "r") {
        $res = null;
        $ex = null;
        $this->open($mode);
        try {
            $res = $funct($this);
        }
        catch (Exception $e) {
            $ex = $e;
        }
        $this->close();
        if (!is_null($ex)) {
            throw $ex;
        }
        return $res;
    }
    
    public function enumerate($funct, $mode = "r") {
        $ex = null;
        $this->open($mode);
        try {
            $key = $this->db->firstKey();
            while ($key !== false) {
                $entry = $this->doFetch($key);
                if ($entry !== false) {
                    $res = $funct($entry);
                    if ($res === false) {
                        break;
                    }
                }
                $key = $this->db->nextKey();
            }
        }
        catch (Exception $e) {
            $ex = $e;
        }
        $this->close();
        if (!is_null($ex)) {
            throw $ex;
        }
    }
    
    public function flushExpired() {
        return $this->withContext(function() {
            $expired = array();
            $key = $this->db->firstKey();
            while ($key !== false) {
                $entry = $this->load($key);
                if (self::isExpired($entry)) {
                    array_push($expired, $key);
                }
                $key = $this->db->nextKey();
            }
            array_map(function($key) { $this->doDelete($key); }, $expired);
            return count($expired);
        }, "w");
    }
}

?>
