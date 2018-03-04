<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
namespace privmx\pki;

use \Exception;
use \JsonSerializable;

/** Immutable class representing a bit string of arbitrary lenght.  */
class BitString implements JsonSerializable {
    protected $value;
    protected $length;

    function __construct($str = "", $length = -1) {
        if ($str instanceof BitString) {
            $str = $str->value;
        }
        if ($length == -1) {
            $length = 8*strlen($str);
        } elseif ($length > 8*strlen($str)) {
            throw new Exception("aaa");
        }

        $this->value = $str;
        $this->length = $length;
    }

    function length() {
        return $this->length;
    }

    function lcp(BitString $other) {
        if ($this === $other)
            return $this;
        $l = min($other->length, $this->length);
        $ll = $l >> 3;
        $i = 0;
        while($ll - $i > 0) {
            if ($this->value[$i] !== $other->value[$i])
                break;
            ++$i;
        }
        $i *= 8;
        while($i < $l) {
            if ($this->bit($i) !== $other->bit($i))
                break;
            ++$i;
        }
        return $this->prefix($i);
    }

    /**
     * 
     */
    function bytes() {
        // To use as db key see s_encode()
        $bytes = 1 + (($this->length-1) >> 3);
        if ($bytes == strlen($this->value)) {
            $result = $this->value;
        } else {
            $result = substr($this->value, 0, $bytes);
        }
        if ($this->length & 7) {
            $mask = 255 ^ (255 >> ($this->length & 7));
            $result[$bytes-1] = chr(ord($result[$bytes - 1]) & $mask);
        }
        return $result;
    }

    function encodeBase64() {
        return base64_encode( $this->encode() );
    }

    function encode() {
        $result = $this->bytes();
        $result .= chr( (8 - ($this->length & 7)) & 7 );
        return $result;
    }

    static function decodeBase64($str) {
        return BitString::decode( base64_decode($str) );
    }

    static function decode($str) {
        $l = strlen($str) - 1;
        if ($l == 0) {
            return new BitString("", 0);
        }
        $b = ord($str[$l]);
        $len = 8 * $l - $b;
        return new BitString($str, $len);
    }

    function v_encode() {
        $l = $this->length;
        $result = '';
        while($l >= 0x80) {
            $result .= chr(0x80 | ($l & 0x7f));
            $l = $l >> 7;
        }
        $result .= chr($l);
        $result .= $this->bytes();
        return $result;
    }

    static function v_decode($str) {
        $len = 0;
        $shift = 0;
        while(true) {
            $c = ord($str[0]);
            $len = $len | (($c & 0x7f) << $shift);
            $shift += 7;
            $str = substr($str, 1);
            if ($c < 0x80)
                break;
        }
        return new BitString($str, $len);
    }

    function s_encode() {
        $result = '';
        $offset = 0;
        while($offset < $this->length) {
            $value = 0;
            for($i = 0; $i < 7; ++$i) {
                $value = ($value << 1);
                if ($offset < $this->length) {
                    $value |= $this->bit($offset) ? 1 : 0;
                    $offset++;
                }
            }
            $value = ($value << 1);
            if ($offset < $this->length) {
                $value |= 1;
            }
            $result .= chr($value);
        }
        $result .= chr($this->length & 7);
        return $result; 
    }

    function bit($i) {
        if ($i >= $this->length)
            throw new Exception("aaa");
        $offset  = $i >> 3;            
        $bitmask = 1 << (7 - ($i & 7));
        return (ord($this->value[$offset]) & $bitmask) != 0;
    }

    function add($bit) {
        $i = $this->length;
        $offset  = $i >> 3;
        $bitmask = 1 << ($i & 7);

        if ($offset >= strlen($this->value)) {
            $copy = $this->value . chr($bitmask);
        } else {
            $byte = ord($this->value[$offset]);
            $vbit = ($byte & $bitmask) != 0;
            if ($bit == $vbit) {
                return new BitString($this->value, $i + 1);
            }
            $byte = $byte & ~$bitmask;
            if ($bit)
                $byte |= $bitmask;
            $copy = substr($this->value, 0, $offset + 1);
            $copy[$offset] = chr($byte);
        }
        return new BitString($copy, $i + 1);
    }

    function prefix($length) {
        if ($length == $this->length)
            return $this;
        assert($length <= $this->length);
        return new BitString($this->value, $length);
    }

    function equals(BitString $other) {
        if ($other === $this)
            return true;
        if ($other->length != $this->length)
            return false;
        for($i = 0; $i < $this->length; ++$i)
            if ($this->bit($i) != $other->bit($i))
                return false;
        return true;
    }

    function isPrefixOf(BitString $other) {
        if ($this === $other)
            return true;
        if ($other->length < $this->length)
            return false;

        $l = $this->length;
        $ll = $l >> 3;
        $i = 0;
        while($i < $ll) {
            if ($this->value[$i] != $other->value[$i])
                return false;
            ++$i;
        }
        $i *= 8;
        while($i < $l) {
            if ($this->bit($i) != $other->bit($i))
                return false;
            ++$i;
        }
        return true;
    }

    function compare(BitString $other) {
        $a = $this;
        $b = $other;
        $l = min($a->length, $b->length);
        $ll = $l >> 3;
        $i = 0;
        while($i < $ll) {
            $av = $a->value[$i];
            $bv = $b->value[$i];
            if ($av < $bv) {
                return -1;
            }
            if ($av > $bv) {
                return 1;
            }
            ++$i;
        }
        $i *= 8;
        while($i < $l) {
            $av = $a->bit($i);
            $bv = $b->bit($i);
            if ($av < $bv) {
                return -1;
            }
            if ($av > $bv) {
                return 1;
            }
            ++$i;
        }
        return $a->length - $b->length;
    }

    function __toString() {
        $result = "BitString(";
        for($i = 0; $i < $this->length; ++$i) {
            if ($this->bit($i))
                $result = $result . '1';
            else
                $result = $result . '0';
        }
        $result .= ':' . $this->length . ')';
        return $result;
    }

    function jsonSerialize()
    {
        return $this->encodeBase64();
    }
}
