<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
namespace PSON;

require_once "Consts.php";

class Decoder {
    private $dict = array();
    private $next = 0;
    private $progressive = false;
    private $options = array();

    public function __construct($dict, $progressive, $options) {
        if (is_array($dict)) {
            while($this->next < count($dict)) {
                $this->dict[$this->next] = $dict[$this->next];
                ++$this->next;
            }
        }
        $this->progressive = !!$progressive;
        $this->options = empty($options) ? array() : $options;
    }

    public function decode($buf) {
        if (!($buf instanceof ByteBuffer)) {
            $buf = ByteBuffer::wrap($buf);
        }
        $le = $buf->littleEndian;
        try {
            $val = $this->decodeValue($buf->LE());
            $buf->littleEndian = $le;
            return $val;
        } catch(Exception $e) {
            $buf->littleEndian = $le;
            throw($e);
        }
    }

    public function decodeValue($buf) {
        $t = $buf->readUint8();
        if ($t <= T_MAX) {
            return ByteBuffer::zigZagDecode($t);
        } 
        switch ($t) {
        case T_NULL: return null;
        case T_TRUE: return true;
        case T_FALSE: return false;
        case T_EOBJECT: return (object)array();
        case T_EARRAY: return [];
        case T_ESTRING: return "";
        case T_OBJECT:
            $t = $buf->readVarint32(); // #keys
            $obj = array();
            while (--$t >= 0) {
                $obj[$this->decodeValue($buf)] = $this->decodeValue($buf);
            }
            return (object)$obj;
        case T_ARRAY:
            $t = $buf->readVarint32(); // #items
            $arr = [];
            while(--$t >= 0) {
                array_push($arr, $this->decodeValue($buf));
            }
            return $arr;
        case T_INTEGER: return $buf->readVarint32ZigZag();
        case T_LONG:   return $buf->readVarint64ZigZag();
        case T_FLOAT:  return $buf->readFloat32();
        case T_DOUBLE: return $buf->readFloat64();
        case T_STRING: return $buf->readVString();
        case T_STRING_ADD:
            $str = $buf->readVString();
            array_push($this->dict, $str);
            return $str;
        case T_STRING_GET:
            return $this->dict[$buf->readVarint32()];
        case T_BINARY:
            $t = $buf->readVarint32();
            return ByteBuffer::fromBinary($buf->readBytes($t));
        default:
            throw new \Exception("Illegal type at " . $buf->offset . ": " . $t);
        }
    }
}
