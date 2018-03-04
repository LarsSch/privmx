<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\keystore;

use Exception;

class LiteralDataPacket extends Packet {
    
    const FORMAT_BINARY          = 0x62;
    const FORMAT_TEXT            = 0x74;
    const FORMAT_UTF8            = 0x75;
    
    public $format;
    public $fileName;
    public $timestamp;
    public $data;
    
    public static function createBinary($fileName, $data) {
        $result = new LiteralDataPacket();
        $result->format = static::FORMAT_BINARY;
        $result->fileName = $fileName;
        $result->timestamp = time();
        $result->data = $data;
        return $result;
    }
    
    public static function createUtf8($fileName, $text) {
        $result = new LiteralDataPacket();
        $result->format = static::FORMAT_UTF8;
        $result->fileName = $fileName;
        $result->date = time();
        $result->data = $text;
        return $result;
    }
    
    protected function getTag() {
        return Packet::LITERAL_DATA;
    }
    
    protected function getBody() {
        return chr($this->format) . chr(strlen($this->fileName)) . $this->fileName . pack("N", $this->timestamp) . $this->data;
    }
    
    public function validate() {
        return true;
    }
    
    public static function decode($data) {
        list($tag, $body, $additional) = Packet::decodePacket($data);
        if ($tag !== Packet::LITERAL_DATA) {
            throw new Exception("Incorrect tag for LiteralData packet {$tag}");
        }
        $result = new LiteralDataPacket();
        $result->format = ord($body[0]);
        $fileNameLength = ord($body[1]);
        $result->fileName = substr($body, 2, $fileNameLength);
        $result->timestamp = unpack("Nuint32", substr($body, 2 + $fileNameLength, 4))["uint32"];
        $result->data = substr($body, 6 + $fileNameLength);
        return array($result, $additional);
    }
}