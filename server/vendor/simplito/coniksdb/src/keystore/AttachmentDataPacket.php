<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\keystore;

use Exception;

class AttachmentDataPacket extends Packet {
    
    public $hash;
    public $data;
    
    public static function create($hash, $data) {
        $result = new AttachmentDataPacket();
        $result->hash = $hash;
        $result->data = $data;
        return $result;
    }
    
    protected function getTag() {
        return Packet::ATTACHMENT_DATA;
    }
    
    protected function getBody() {
        return chr(strlen($this->hash)) . $this->hash . $this->data;
    }
    
    public function validate() {
        return true;
    }
    
    public static function decode($data) {
        list($tag, $body, $additional) = Packet::decodePacket($data);
        if ($tag !== Packet::ATTACHMENT_DATA) {
            throw new Exception("Incorrect tag for AttachmentData packet {$tag}");
        }
        $result = new AttachmentDataPacket();
        $hashLength = ord($body[0]);
        $result->hash = substr($body, 1, $hashLength);
        $result->data = substr($body, 1 + $hashLength);
        return array($result, $additional);
    }
}
