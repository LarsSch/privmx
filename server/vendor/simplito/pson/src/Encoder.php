<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
namespace PSON;

use Exception;

require_once "Consts.php";

class Encoder {
    private $dict = array();
    private $next = 0;
    private $progressive = false;
    private $options = array();

    public function __construct($dict, $progressive, $options) {
        if (is_array($dict)) {
            while($this->next < count($dict)) {
                $this->dict[$dict[$this->next]] = $this->next++;
            }
        }
        $this->progressive = !!$progressive;
        $this->options = empty($options) ? array() : $options;
    }

    public function encode($json, $buf = null) {
        $doFlip = false;
        if ($buf == null) {
            $buf = new ByteBuffer();
            $doFlip = true;
        }
        $le = $buf->littleEndian;
        try {
            $this->encodeValue($json, $buf->LE());
            $buf->littleEndian = $le;
            return $doFlip ? $buf->flip() : $buf;
        } catch(Exception $e) {
            $buf->littleEndian = $le;
            throw $e;
        }
    }

    protected function encodeValue($val, $buf, $excluded = false){
        $type = gettype($val);
        if ($type == "array") {
            $keys = array_keys($val);
            for($i = 0; $i < count($keys); ++$i) {
                if ($keys[$i] !== $i) {
                    $type = "object";
                    break;
                }
            }
        } else if ($type == "string") {
            if (!mb_detect_encoding($val, 'UTF-8', true)) {
                $type = "binary";
            }
        } else if ($type == "object" && $val instanceof ByteBuffer) {
            $val = $val->toBinary();
            $type = "binary";
        } else if ($type == "double" && fmod($val, 1.0) == 0.0 && abs($val) <= pow(2,52)) {
            $type = "integer";
        }
        switch($type) {
        case "NULL":
            $buf->writeUint8(T_NULL);
            break;
        case "string":
            if (strlen($val) == 0) {
                $buf->writeUint8(T_ESTRING);
            } else {
                if (isset($this->dict[$val])) {
                    $buf->writeUint8(T_STRING_GET);
                    $buf->writeVarint32($this->dict[$val]);
                } else {
                    $buf->writeUint8(T_STRING);
                    $buf->writeVString($val);
                }
            }
            break;
        case "binary":
            $buf->writeUint8(T_BINARY);
            $buf->writeVarint32(strlen($val));
            $buf->append($val);
            break;
        case "boolean":
            $buf->writeUint8($val ? T_TRUE : T_FALSE);
            break;
        case "array":
            $length = count($val);
            if ($length == 0) {
                $buf->writeUint8(T_EARRAY);
            } else {
                $buf->writeUint8(T_ARRAY);
                $buf->writeVarint32($length);
                for($i = 0; $i < $length; ++$i) {
                    $this->encodeValue($val[$i], $buf);
                }
            }
            break;
        case "object":
            $arr = (array)$val;
            $length = count($arr);
            if ($length == 0) {
                $buf->writeUint8(T_EOBJECT);
            } else {
                $buf->writeUint8(T_OBJECT);
                $buf->writeVarint32($length);
                if (!$excluded && isset($arr["_PSON_EXCL_"]))
                    $excluded = true;
                foreach($arr as $key => $value) {
                    if ($key == "_PSON_EXCL_")
                        continue;
                    if (isset($this->dict[$key])) {
                        $buf->writeUint8(T_STRING_GET);
                        $buf->writeVarint32($this->dict[$key]);
                    } else {
                        if ($this->progressive && !$excluded) {
                            $this->dict[$key] = $this->next++;
                            $buf->writeUint8(T_STRING_ADD);
                        } else {
                            $buf->writeUint8(T_STRING);
                        }
                        $buf->writeVString($key);
                    }
                    $this->encodeValue($value, $buf);
                }
            }
            break; 
        case "integer":
            $zzval = ByteBuffer::zigZagEncode($val);
            if ($zzval >= 0 && $zzval <= T_MAX) {
                $buf->writeUint8($zzval);
            } else if (abs($zzval) < pow(2,32)) {
                $buf->writeUint8(T_INTEGER);
                $buf->writeVarint32($zzval);
            } else {
                $buf->writeUint8(T_LONG);
                $buf->writeVarint64ZigZag($val);
            }
            break;
        case "double":
            $fbuf = new ByteBuffer();
            $fbuf->writeFloat32($val);
            if ($val == $fbuf->readFloat32(0)) {
                $buf->writeUint8(T_FLOAT);
                $buf->writeFloat32($val);
            } else {
                $buf->writeUint8(T_DOUBLE);
                $buf->writeFloat64($val);
            }
            break;
        }
    }
}
