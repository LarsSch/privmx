<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace PSON;

use Exception;

class ByteBuffer implements \ArrayAccess, \JsonSerializable {
    public  $littleEndian = false;
    private $buffer;
    private $offset = 0;
    private $markedOffset = -1;
    private $limit = 0;

    const LITTLE_ENDIAN = true;
    const BIG_ENDIAN = false;

    const DEFAULT_CAPACITY = 16;
    const DEFAULT_ENDIAN   = ByteBuffer::BIG_ENDIAN;

    public function __construct($capacity = ByteBuffer::DEFAULT_CAPACITY, $littleEndian = ByteBuffer::DEFAULT_ENDIAN) {
        $this->buffer = str_repeat(chr(0), $capacity);
        $this->offset = 0;
        $this->limit  = $capacity;
        $this->littleEndian = $littleEndian;
    }

    public function isByteBuffer($bb) {
        return is_object($bb) && ($bb instanceof ByteBuffer);
    }

    public function order($littleEndian) {
        $this->littleEndian = $littleEndian;
        return $this;
    }

    public function LE($littleEndian = true) {
        $this->littleEndian = $littleEndian;
        return $this;
    }

    public function BE($littleEndian = false) {
        $this->littleEndian = $littleEndian;
        return $this;
    }

    public static function concat($bufferrs, $encoding = null, $littleEndian = ByteBuffer::DEFAULT_ENDIAN) {
        throw new Exception("Not implemented");
    }

    public static function wrap($buffer, $encoding = null, $littleEndian = ByteBuffer::DEFAULT_ENDIAN) {
        if (is_string($buffer)) {
            switch($encoding) {
            case "base64": return static::fromBase64($buffer, $littleEndian);
            case "hex":    return static::fromHex($buffer, $littleEndian);
            }
            return static::fromBinary($buffer, $littleEndian);
        }
        if (is_array($buffer)) {
            return static::fromArray($buffer, $littleEndian);
        }
        if ($buffer instanceof ByteBuffer) {
            $b = new ByteBuffer();
            $b->LE($littleEndian);
            $b->buffer = $buffer->buffer;
            $b->offset = $buffer->offset;
            $b->limit = $buffer->limit;
            return $b;
        }
        throw new \Exception("Unknown buffer format");
    }

    public static function fromBase64($buffer, $littleEndian = ByteBuffer::DEFAULT_ENDIAN) {
        $b = new ByteBuffer();
        $b->LE($littleEndian);
        $b->buffer = base64_decode($buffer);
        $b->limit  = strlen($b->buffer);
        return $b;
    }

    public static function fromHex($buffer, $littleEndian = ByteBuffer::DEFAULT_ENDIAN) {
        $b = new ByteBuffer();
        $b->LE($littleEndian);
        $b->buffer = hex2bin($buffer);
        $b->limit  = strlen($b->buffer);
        return $b;
    }

    public static function fromBinary($buffer, $littleEndian = ByteBuffer::DEFAULT_ENDIAN) {
        $b = new ByteBuffer();
        $b->LE($littleEndian);
        $b->buffer = $buffer;
        $b->limit  = strlen($b->buffer);
        return $b;
    }

    public static function fromArray($array, $littleEndian = ByteBuffer::DEFAULT_ENDIAN) {
        $binary = call_user_func_array("pack", array_merge(["C*"], $array)); 
        return static::fromBinary($binary, $littleEndian);
    }

    public static function zigZagEncode($value) {
        if ($value < 0)
            return (-$value) * 2 - 1;
        return $value * 2;
    }

    public static function zigZagDecode($value) {
        if (PHP_INT_SIZE == 4 && is_integer($value) && $value < 0) {
            $value = ($value & 0x7fffffff) + pow(2,31);
        }
        if ($value % 2 != 0) {
            return -($value + 1) / 2;
        }
        return $value / 2;
    }

    public static function calculateVarint32($value) {
        if (PHP_INT_SIZE == 4 && $value < 0)
            return 5;
        if ($value < (1 << 7))
            return 1;
        if ($value < (1 << 14))
            return 2;
        if ($value < (1 << 21))
            return 3;
        if ($value < (1 << 28))
            return 4;
        return 5;
    }

    public static function calculateVarint64($value) {
        if (PHP_INT_SIZE == 4) {
            if ($value < 0) 
                return 10;
            if ($value < (1 << 7))
                return 1;
            if ($value < (1 << 14))
                return 2;
            if ($value < (1 << 21))
                return 3;
            if ($value < (1 << 28))
                return 4;
            if ($value < pow(2,35))
                return 5;
            if ($value < pow(2,42))
                return 6;
            if ($value < pow(2,49))
                return 7;
            if ($value < pow(2,56))
                return 8;
            return 9;
        } else {
            if ($value & 0x8000000000000000) {
                return 10;
            }
            if ($value < (1 << 7))
                return 1;
            if ($value < (1 << 14))
                return 2;
            if ($value < (1 << 21))
                return 3;
            if ($value < (1 << 28))
                return 4;
            if ($value < (1 << 35))
                return 5;
            if ($value < (1 << 42))
                return 6;
            if ($value < (1 << 49))
                return 7;
            if ($value < (1 << 56))
                return 8;
            return 9;
        }
    }

    public function capacity() {
        return strlen($this->buffer);
    }

    public function remaining() {
        return $this->limit - $this->offset;
    }

    public function skip($length) {
        $this->offset += $length;
        return $this;
    }

    public function flip() {
        $this->limit = $this->offset;
        $this->offset = 0;
        return $this;
    }

    public function mark() {
        $this->markedOffset = $this->offset;
        return $this;
    }

    public function reset() {
        if ($this->markedOffset >= 0) {
            $this->offset = $this->markedOffset;
            $this->markedOffset = -1;
        } else {
            $this->offset = 0;
        }
        return $this;
    }

    public function prepend($source, $encoding = null, $offset = null) {
        if (!is_string($encoding)) {
            $offset = $encoding;
            $encoding = null;
        }
        $relative = ($offset === null);
        if ($relative)
            $offset = $this->offset;
        if (is_string($source) && $encoding == null) {
            $str = $source;
        } else {
            if (!($source instanceof ByteBuffer))
                $source = ByteBuffer::wrap($source, $encoding);
            $str = $source->toBinary();
        }
        $length = strlen($str);
        $suffix = substr($this->buffer, $offset);
        if ($length < $offset) {
            $prefix = substr($this->buffer, 0, $offset - $length);
            $this->buffer = $prefix . $str . $suffix;
        } else {
            $this->buffer = $str . $suffix;
            $diff = $length - $offset;
            $this->offset += $diff;
            $this->limit  += $diff;
            if ($this->markedOffset >= 0) {
                $this->markedOffset += $diff;
            }
        }
        if ($relative) 
            $this->offset -= $length;
        return $this;
    }

    public function append($source, $encoding = null, $offset = null) {
        if (is_integer($encoding)) {
            $offset = $encoding;
            $encoding = null;
        }
        $relative = ($offset === null);
        if ($relative)
            $offset = $this->offset;
        if (is_string($source) && $encoding == null) {
            $str = $source;
        } else {
            if (!($source instanceof ByteBuffer))
                $source = ByteBuffer::wrap($source, $encoding);
            $str = $source->toBinary();
        }
        $length = strlen($str);
        
        $this->ensureCapacity($offset + $length);
        for ($i = 0; $i < $length; $i++) {
            $this->buffer[$offset + $i] = $str[$i];
        }
        
        $offset += $length;
        if ($relative)
            $this->offset = $offset;
        return $this;
    }

    public function resize($capacity) {
        $length = strlen($this->buffer);
        if ($length < $capacity) {
            $this->buffer[$capacity] = chr(0);
        }
        return $this;
    }

    public function ensureCapacity($capacity) {
        $length = strlen($this->buffer);
        if ($length < $capacity) {
            if ($length * 2 > $capacity) {
                $capacity = 2 * $length;
            } 
            $this->buffer[$capacity] = chr(0);
        }
        return $this;
    }

    public function writeUint8($value, $offset = null) {
        $relative = ($offset === null);
        if ($relative)
            $offset = $this->offset;
        $this->ensureCapacity($offset + 1);
        $this->buffer[$offset] = chr($value);
        if ($relative)
            $this->offset = $offset + 1;
        return $this;
    }

    public function readUint8($offset = null) {
        $relative = ($offset === null);
        if ($relative)
            $offset = $this->offset;
        $value = ord($this->buffer[$offset]);
        if ($relative)
            $this->offset = $offset + 1;
        return $value;
    }

    public function writeInt8($value, $offset = null) {
        return $this->writeUint8($value, $offset);
    }

    public function readInt8($offset = null) {
        $value = $this->readUint8($offset);
        if ($value & 0x80) {
            $value = -(0xff - $value + 1);
        }
        return $value;
    }

    public function writeVarint32($value, $offset = null) {
        $relative = ($offset === null);
        if ($relative)
            $offset = $this->offset;
        $size = static::calculateVarint32($value);
        $this->ensureCapacity($offset + $size);
        if (PHP_INT_SIZE == 4 && is_float($value)) {
            for($i = 0; $i < $size - 1; ++$i) {
                $b = (int)floor(fmod($value * pow(2, -7 * $i), 256.0)) | 0x80;
                $this->buffer[$offset++] = chr($b);
            }
            if ($i < 9) {
                $b = (int)floor(fmod($value * pow(2, -7 * $i), 256.0)) & 0x7f;
            } else {
                $b = 1;
            }
            $this->buffer[$offset++] = chr($b);
        } else {
            while(abs($value) >= 0x80) {
                $b = ($value & 0x7f) | 0x80;
                $this->buffer[$offset++] = chr($b);
                $value >>= 7;
            }
            $this->buffer[$offset++] = chr($value);
        }
        if ($relative) {
            $this->offset = $offset;
            return $this;
        }
        return $size;
    }

    public function readVarint32($offset = null) {
        $relative = ($offset === null);
        if ($relative)
            $offset = $this->offset;
        $value = 0;
        $c = 0;
        do {
            $b = ord($this->buffer[$offset++]);
            $value |= ($b & 0x7f) << $c;
            $c += 7;
        } while(($b & 0x80) != 0);
        if ($relative)
            $this->offset = $offset;
        return $value;

    }

    public function writeVarint32ZigZag($value, $offset = null) {
        $value = static::zigZagEncode($value);
        return $this->writeVarint32($value, $offset);
    }

    public function readVarint32ZigZag($offset = null) {
        $value = $this->readVarint32($offset);
        $value = (int)static::zigZagDecode($value);
        return $value;
    }

    public function writeVarint64($value, $offset = null) {
        $relative = ($offset === null);
        if ($relative)
            $offset = $this->offset;
        $size = static::calculateVarint64($value);
        $this->ensureCapacity($offset + $size);
        if (PHP_INT_SIZE == 4) {
            if (is_integer($value)) {
                $value = floor($value);
            }
            for($i = 0; $i < $size - 1; ++$i) {
                $b = (int)floor(fmod($value * pow(2, -7 * $i), 256.0)) | 0x80;
                $this->buffer[$offset++] = chr($b);
            }
            if ($i < 9) {
                $b = (int)floor(fmod($value * pow(2, -7 * $i), 256.0)) & 0x7f;
            } else {
                $b = 1;
            }
            $this->buffer[$offset++] = chr($b);
        } else {
            if($value & 0x8000000000000000) {
                $value = $value ^ 0x8000000000000000;
                $b = ($value & 0x7f) | 0x80;
                $this->buffer[$offset++] = chr($b);
                $value >>= 7;
                $value |= 0x0100000000000000;
            }
            while($value >= 0x80) {
                $b = ($value & 0x7f) | 0x80;
                $this->buffer[$offset++] = chr($b);
                $value >>= 7;
            }
            $this->buffer[$offset++] = chr($value);
        }
        if ($relative) {
            $this->offset = $offset;
            return $this;
        }
        return $size;
    }

    public function readVarint64($offset = null) {
        $relative = ($offset === null);
        if ($relative)
            $offset = $this->offset;
        $value = 0;
        $c = 0;
        if (PHP_INT_SIZE == 4) {
            do {
                $b = ord($this->buffer[$offset++]);
                $value += ($b & 0x7f) * pow(2, $c);
                $c += 7;
            } while(($b & 0x80) != 0);
        } else {
            do {
                $b = ord($this->buffer[$offset++]);
                $value |= ($b & 0x7f) << $c;
                $c += 7;
            } while(($b & 0x80) != 0);
        }
        if ($relative)
            $this->offset = $offset;
        return $value;
    }

    public function writeVarint64ZigZag($value, $offset = null) {
        $value = static::zigZagEncode($value);
        return $this->writeVarint64($value, $offset);
    }

    public function readVarint64ZigZag($offset = null) {
        $value = $this->readVarint64($offset);
        $value = static::zigZagDecode($value);
        return $value;
    }

    public function writeVString($val, $offset = null) {
        $relative = ($offset === null);
        if ($relative)
            $offset = $this->offset;
        $start = $offset;
        $len = strlen($val);
        $offset += $this->writeVarint32($len, $offset);
        $this->append($val, $offset);
        $offset += $len;
        if ($relative) {
            $this->offset = $offset;
            return $this;
        }
        return $offset - $start;
    }

    public function readVString($offset = null) {
        $relative = ($offset === null);
        if ($relative)
            $offset = $this->offset;
        $len = $this->readVarint32($offset);
        $offset += static::calculateVarint32($len);
        $val = $this->readBytes($len, $offset);
        $offset += $len;
        if ($relative)
            $this->offset = $offset;
        return $val;
    }

    public function writeIString($val, $offset = null) {
        $relative = ($offset === null);
        if ($relative)
            $offset = $this->offset;
        $start = $offset;
        $len = strlen($val);
        $this->writeUint32($len, $offset);
        $offset += 4;
        $this->append($val, $offset);
        $offset += $len;
        if ($relative) {
            $this->offset = $offset;
            return $this;
        }
        return $offset - $start;
    }

    public function readIString($offset = null) {
        $relative = ($offset === null);
        if ($relative)
            $offset = $this->offset;
        $len = $this->readUint32($offset);
        $offset += 4;
        $val = $this->readBytes($len, $offset);
        $offset += $len;
        if ($relative)
            $this->offset = $offset;
        return $val;
    }


    public function writeFloat32($val, $offset = null) {
        $relative = ($offset === null);
        if ($relative)
            $offset = $this->offset;
        $d = unpack("L", pack("f", $val));
        $d = pack($this->littleEndian ? "V" : "N", $d[1]);
        $this->append($d, $offset);
        $offset += 4;
        if ($relative)
            $this->offset = $offset;
    }

    public function readFloat32($offset = null) {
        $relative = ($offset === null);
        if ($relative)
            $offset = $this->offset;
        $val = unpack($this->littleEndian ? "V" : "N", substr($this->buffer, $offset, 4));
        $val = unpack("f", pack("L", $val[1]));
        $offset += 4;
        if ($relative)
            $this->offset = $offset;
        return $val[1];
    }

    public function writeFloat64($val, $offset = null) {
        $relative = ($offset === null);
        if ($relative)
            $offset = $this->offset;
        if (version_compare(PHP_VERSION, "5.6.3") >= 0) {
            $d = unpack("Q", pack("d", $val));
            $d = pack($this->littleEndian ? "P" : "J", $d["1"]);
            $this->append($d, $offset);
        } else {
            if ($val < 0) {
                $sign_bit = 1;
                $val = -$val;
            } else {
                $sign_bit = 0;
            }
            $exp = floor(log($val, 2));
            $man = $val * pow(2, -$exp) - 1.0;

            $exp = (int)$exp + 1023;
            $man = $man * pow(2, 20);
            $hi = ($sign_bit ? 0x80000000 : 0) | ($exp << 20) | (int)($man);
            $man = fmod($man, 1.0) * pow(2, 32);;
            $lo = (int)$man;
            if ($this->littleEndian) {
                $this->writeUint32($lo, $offset);
                $this->writeUint32($hi, $offset + 4);
            } else {
                $this->writeUint32($lo, $offset + 4);
                $this->writeUint32($hi, $offset);
            }
        }
        $offset += 8;
        if ($relative)
            $this->offset = $offset;
    }

    public function readFloat64($offset = null) {
        $relative = ($offset === null);
        if ($relative)
            $offset = $this->offset;
        if (version_compare(PHP_VERSION, "5.6.3") >= 0) {
            $val = unpack($this->littleEndian ? "P" : "J", substr($this->buffer, $offset, 8));
            $val = unpack("d", pack("Q", $val[1]));
            $value = $val[1];
        } else {
            if ($this->littleEndian) {
                $lo = $this->readUint32($offset);
                $hi = $this->readUint32($offset + 4);
            } else {
                $lo = $this->readUint32($offset + 4);
                $hi = $this->readUint32($offset);
            }
            if ($hi & 0x80000000) {
                $sign = -1;
                $hi = $hi & 0x7fffffff;
            } else {
                $sign = 1;
            }
            if ($lo & 0x80000000) {
                $lo = pow(2, 31) + ($lo & 0x7fffffff);
            }
            $exp = ($hi & 0x7ff00000) >> 20;
            $man = (($hi & 0x000fffff) * pow(2, 32) + $lo) * pow(2, -52);
            $value = $sign * (1.0 + $man) * pow(2, $exp - 1023);
        }
        $offset += 8;
        if ($relative)
            $this->offset = $offset;
        return $value;
    }

    public function writeUint16($value, $offset = null) {
        $relative = ($offset === null);
        if ($relative)
            $offset = $this->offset;
        $bytes = pack( $this->littleEndian ? "v" : "n", $value );
        $this->buffer[$offset  ] = $bytes[0];
        $this->buffer[$offset+1] = $bytes[1];
        $offset += 2;
        if ($relative)
            $this->offset = $offset;
        return $this;
    }
    
    public function readUint16($offset = null) {
        $relative = ($offset === null);
        if ($relative)
            $offset = $this->offset;
        $bytes  = substr($this->buffer, $offset, 2);
        $values = unpack( $this->littleEndian ? "v" : "n", $bytes );
        $offset += 2;
        if ($relative)
            $this->offset = $offset;
        return $values[1];
    }

    public function writeInt16($value, $offset = null) {
        return $this->writeUint16($value, $offset);
    }

    public function readInt16($offset = null) {
        $value = $this->readUint16($offset);
        if ($value & 0x8000)
            $value = -(0xffff - $value + 1);
        return $value;
    }

    public function writeUint32($value, $offset = null) {
        $relative = ($offset === null);
        if ($relative)
            $offset = $this->offset;
        $bytes = pack( $this->littleEndian ? "V" : "N", $value );
        $this->buffer[$offset  ] = $bytes[0];
        $this->buffer[$offset+1] = $bytes[1];
        $this->buffer[$offset+2] = $bytes[2];
        $this->buffer[$offset+3] = $bytes[3];
        $offset += 4;
        if ($relative)
            $this->offset = $offset;
        return $this;
    }
    
    public function readUint32($offset = null) {
        $relative = ($offset === null);
        if ($relative)
            $offset = $this->offset;
        $bytes = substr($this->buffer, $offset, 4);
        $values = unpack( $this->littleEndian ? "V" : "N", $bytes );
        $offset += 4;
        if ($relative)
            $this->offset = $offset;
        return $values[1];
    }

    public function writeInt32($value, $offset = null) {
        return $this->writeUint32($value, $offset);
    }

    public function readInt32($offset = null) {
        $value = $this->readUint32($offset);
        if ($value & 0x80000000)
            $value = -(0xffffffff - $value + 1);
        return $value;
    }

    public function writeUint64($value, $offset = null) {
        $relative = ($offset === null);
        if ($relative)
            $offset = $this->offset;
        if (PHP_INT_SIZE == 4) {
            $p  = pow(2, 32);
            $hi = floor($value / $p);
            $lo = floor($value - $hi * $p);
        } else {
            $hi = $value >> 32;
            $lo = $value & 0xffffffff;
        }
        if ($this->littleEndian) {
            $this->writeUint32((int)$lo, $offset);
            $this->writeUint32((int)$hi, $offset + 4);
        } else {
            $this->writeUint32((int)$lo, $offset + 4);
            $this->writeUint32((int)$hi, $offset);
        }
        $offset += 8;
        if ($relative)
            $this->offset = $offset;
        return $this;
    }
    
    public function readUint64($offset = null) {
        $relative = ($offset === null);
        if ($relative)
            $offset = $this->offset;
        if ($this->littleEndian) {
            $lo = $this->readUint32($offset);
            $hi = $this->readUint32($offset + 4);
        } else {
            $lo = $this->readUint32($offset + 4);
            $hi = $this->readUint32($offset);
        }
        if (PHP_INT_SIZE == 4) {
            $p = pow(2, 32);
            if ($hi & 0x80000000) {
              $sign = -1;
              $hi = ~($hi);
              $lo = ~($lo);
              if ($lo & 0x80000000) {
                $lo = pow(2,31) + ($lo & 0x7fffffff);
              }
              $lo = $lo + 1;
            } else {
              $sign = 1;
              if ($lo & 0x80000000) {
                $lo = pow(2,31) + ($lo & 0x7fffffff);
              }
            }
            $value = $sign * ($p * $hi + $lo);
        } else {
            $value = ($hi << 32) | $lo;
        }
        $offset += 8;
        if ($relative)
            $this->offset = $offset;
        return $value;
    }

    public function writeInt64($value, $offset = null) {
        return $this->writeUint64($value, $offset);
    }

    public function readInt64($offset = null) {
        // There is no uint64 in php so don't need a conversion
        return $this->readUint64($offset);
    }

    public function writeCString($value, $offset = null) {
        $relative = ($offset === null);
        if ($relative)
            $offset = $this->offset;

        $start = $offset;

        $this->append($value, $offset);
        $offset += strlen($value);
        $this->buffer[$offset++] = chr(0);

        if ($relative) {
            $this->offset = $offset;
            return $this;
        }
        return $offset - $start;
    }

    public function readCString($offset = null) {
        $relative = ($offset === null);
        if ($relative)
            $offset = $this->offset;

        $end = strpos($this->buffer, chr(0), $offset);
        $value = $this->toBinary($offset, $end);
        $offset = $end + 1;

        if ($relative)
            $this->offset = $offset;
        return $value;
    }

    public function writeBytes($bytes, $offset = null) {
        return $this->append($bytes, $offset);
    }

    public function readBytes($length, $offset = null) {
        $relative = ($offset === null);
        if ($relative)
            $offset = $this->offset;
        $val = substr($this->buffer, $offset, $length);
        $offset += $length;
        if ($relative)
            $this->offset = $offset;
        return $val;
    }

    public function toBinary($begin = null, $end = null) {
        if ($begin === null)
            $begin = $this->offset;
        if ($end === null)
            $end = $this->limit;
        $capacity = strlen($this->buffer);
        if ($begin < 0 || $end > $capacity || $begin > $end) {
            throw new \Exception("RangeError begin, end");
        }
        if ($begin == 0 && $end == $capacity)
            return $this->buffer;
        return substr($this->buffer, $begin, $end - $begin);
    }

    public function toBase64($begin = null, $end = null) {
        return base64_encode($this->toBinary($begin, $end));
    }

    public function toHex($begin = null, $end = null) {
        return bin2hex($this->toBinary($begin, $end));
    }

    public function toArray($begin = null, $end = null) {
        $str = $this->toBinary($begin, $end);
        return array_slice(unpack("C*", $str), 0);
    }

    //
    // ArrayAccess implementation
    //
    
    public function offsetSet($offset, $value) {
        $offset += $this->offset;
        $this->buffer[$offset] = $value;
    }
    
    public function offsetExists($offset) {
        return ($offset >= 0) && ($offset + $this->offset < $this->limit);
    }

    public function offsetGet($offset) {
        $offset += $this->offset;
        return $this->buffer[$offset];
    }

    public function offsetUnset($offset) {
        throw new \Exception("Not implemented");
    }

    //
    // Allow using buffer as binary string
    //
    
    public function __toString() {
        return $this->toBinary();
    }

    public function __debugInfo() {
        $hex = bin2hex($this->buffer);
        $hex = substr_replace($hex, '<', 2*$this->offset, 0);
        $hex = substr_replace($hex, '>', 2*$this->limit + 1, 0);
        return array(
            "buffer" => $hex,
            "offset" => $this->offset,
            "limit"  => $this->limit,
            "markedOffset" => $this->markedOffset,
            "littleEndian" => $this->littleEndian
        );
    }
    
    public function jsonSerialize() {
        $hex = $this->toHex();
        return "ByteBuffer(" . (strlen($hex) / 2) . ")" . $hex;
    }
}
