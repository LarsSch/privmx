<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
namespace privmx\pki;

use \JsonSerializable;
use \privmx\pki\messages\AuthPathNodeMessage;

class AuthPathNode implements JsonSerializable {
    protected $node;
    protected $hash;
    protected $match;

    function __construct(Node $node, $hash, $match = true) {
        $this->node = $node;
        $this->hash = $hash;
        $this->match = $match;
    }

    function node() {
        return $this->node;
    }

    function index() {
        return $this->node->index();
    }

    function hash() {
        return $this->hash;
    }

    function jsonSerialize() {
        $arr = array();
        $arr["hash"] = base64_encode($this->hash());
        if ($this->match) {
            $arr["prefix"] = $this->index()->length();
        } else {
            $arr["index"] = base64_encode($this->index()->encode());
        }
        return $arr;
    }

    function __toString() {
        return json_encode($this->jsonSerialize());
    }

    public function toMessage()
    {
        $auth = $this->index();
        if( $this->match )
            $auth = $auth->length();
        return new AuthPathNodeMessage($this->hash(), $auth);
    }
}
