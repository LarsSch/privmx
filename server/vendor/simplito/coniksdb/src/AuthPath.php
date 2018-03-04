<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
namespace privmx\pki;

use \Exception;
use \ArrayAccess;
use \JsonSerializable;

class AuthPath implements JsonSerializable, ArrayAccess {
    private $path = array();

    public function __construct() {
        $this->path = array();
    }

    function jsonSerialize() {
        $arr = array();
        foreach($this->path as $node) {
            $arr[] = $node->jsonSerialize();
        }
        return $arr;
    }

    // ArrayAccess methods below

    public function offsetSet($offset, $value) {
        if ( !($value instanceof AuthPathNode) ) {
            throw new Exception("notallowed");
        }
        if (is_null($offset)) {
            $this->path[] = $value;
        } else {
            throw new Exception("notallowed");
        }
    }

    public function offsetExists($offset) {
        return isset($this->path[$offset]);
    }

    public function offsetUnset($offset) {
        throw new Exception("notallowed");
    }

    public function offsetGet($offset) {
        return isset($this->path[$offset]) ? $this->path[$offset] : null;
    }

    public function toMessage()
    {
        return array_map(function($node) {
            return $node->toMessage();
        }, $this->path);
    }
}
