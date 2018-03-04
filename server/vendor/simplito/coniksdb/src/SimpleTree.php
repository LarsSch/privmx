<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
namespace privmx\pki;

use \privmx\pki\messages\MessageBase;

class SimpleTree {
    
    public $db;
    public $data;
    public $timestamp;
    
    function __construct($db, $data) {
        $this->db = $db;
        $this->data = $data;
        $this->fixData();
    }
    
    public function fixData() {
        $this->timestamp = is_numeric($this->data["t"]) ? $this->data["t"] : MessageBase::decodeUint64($this->data["t"]);
        $this->data["t"] = $this->timestamp;
    }
    
    public function checkoutPrev() {
        $this->db->setNamespace("");
        $this->data = $this->db->fetch($this->data["prev_tree"]);
        if ($this->data === false) {
            throw new \Exception("Missing tree for revision " . bin2hex($this->data["prev_tree"]));
        }
        $this->fixData();
    }

    public function getSerializedTreeMessage() {
        $result = array(
            "hash" => MessageBase::toByteBuffer($this->data["hash"]),
            "version" => $this->data["version"],
            "prevTree" => MessageBase::toByteBuffer($this->data["prev_tree"]),
            "root" => MessageBase::toByteBuffer($this->data["root_t"]),
            "timestamp" => MessageBase::encodeUint64($this->data["t"], true),
            "signature" => MessageBase::toByteBuffer($this->data["signature"]),
            "seq" => $this->data["seq"]
        );
        if (isset($this->data["nonce"])) {
            $result["nonce"] = MessageBase::toByteBuffer($this->data["nonce"]);
        }
        return $result;
    }
}
