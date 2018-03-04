<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\keystore;

use Exception;

class PkiDataTypePacket extends Packet {
    
    public $type;
    
    function __construct($type) {
        $this->type = $type;
    }
    
    protected function getTag() {
        return Packet::PKI_DATA_TYPE;
    }
    
    protected function getBody() {
        return chr($this->type);
    }
    
    public function validate() {
        return true;
    }
    
    public static function decode($data) {
        list($tag, $body, $additional) = Packet::decodePacket($data);
        if ($tag !== Packet::PKI_DATA_TYPE) {
            throw new Exception("Incorrect tag for PkiDataType packet {$tag}");
        }
        $result = new PkiDataTypePacket(ord($body));
        return array($result, $additional);
    }
}