<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\keystore;

use Exception;

class AttachmentPointerPacket extends Packet {
    
    public $fileName;
    public $timestamp;
    public $algorithm;
    public $hash;
    
    public static function create($fileName, $hash, $algorithm) {
        $result = new AttachmentPointerPacket();
        $result->fileName = $fileName;
        $result->timestamp = time();
        $result->algorithm = $algorithm;
        $result->hash = $hash;
        return $result;
    }
    
    protected function getTag() {
        return Packet::ATTACHMENT_POINTER;
    }
    
    protected function getBody() {
        return chr(strlen($this->fileName)) . $this->fileName . pack("N", $this->timestamp) . chr($this->algorithm) . $this->hash;
    }
    
    public function validate() {
        return true;
    }
    
    public static function decode($data) {
        list($tag, $body, $additional) = Packet::decodePacket($data);
        if ($tag !== Packet::ATTACHMENT_POINTER) {
            throw new Exception("Incorrect tag for AttachmentPointer packet {$tag}");
        }
        $result = new AttachmentPointerPacket();
        $fileNameLength = ord($body[0]);
        $result->fileName = substr($body, 1, $fileNameLength);
        $result->timestamp = unpack("Nuint32", substr($body, 1 + $fileNameLength, 4))["uint32"];
        $result->algorithm = ord($body[5 + $fileNameLength]);
        $result->hash = substr($body, 6 + $fileNameLength);
        return array($result, $additional);
    }
}
