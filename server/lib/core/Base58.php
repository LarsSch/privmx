<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

use BI\BigInteger;

class Base58 {
    
    private static $base58chars = "123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz";
    
    public static function decodeToHex($base58) {
        if (!is_string($base58)) {
            return false;
        }
        $origbase58 = $base58;
        $return = new BigInteger("0");
        for ($i = 0; $i < strlen($base58); $i++) {
            $pos = strpos(self::$base58chars, $base58[$i]);
            if ($pos === false) {
                return false;
            }
            $return = $return->mul(58)->add($pos);
        }
        $return = $return->toHex();
        if( $return === "0" )
            $return = "";
        for ($i = 0; $i < strlen($origbase58) && $origbase58[$i] == "1"; $i++) {
            $return = "00" . $return;
        }
        if (strlen($return) % 2 != 0) {
            $return = "0" . $return;
        }
        return $return;
    }
    
    public static function decodeWithChecksumToHex($base58) {
        $hex = self::decodeToHex($base58);
        if ($hex === false || strlen($hex) < 8) {
            return false;
        }
        $msg = substr($hex, 0, -8);
        $checksum = substr($hex, strlen($hex) - 8);
        $myChecksumAll = hash("sha256", hash("sha256", hex2bin($msg), true));
        $myChecksum = substr($myChecksumAll, 0, 8);
        if ($myChecksum != $checksum) {
            return false;
        }
        return $msg;
    }
    
    public static function decode($base58) {
        if ($base58 == "")
            return "";
        if ($base58 == "1")
            return "\0";
        $hex = self::decodeToHex($base58);
        return $hex === false ? false : hex2bin($hex);
    }
    
    public static function decodeWithChecksum($base58) {
        $hex = self::decodeWithChecksumToHex($base58);
        return $hex === false ? false : hex2bin($hex);
    }
    
    public static function encodeHex($hex) {
        if (!is_string($hex) || (strlen($hex) % 2) != 0) {
            return false;
        }
        if (strlen($hex) == 0) {
            return "";
        }
        $num = BigInteger::createSafe($hex, 16);
        if ($num === false) {
            return false;
        }
        $num = $num->toBase(58);
        if ($num != '0') {
            $num = strtr(
                $num,
                '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuv',
                self::$base58chars
            );
        }
        else {
            $num = '';
        }
        $pad = '';
        $n = 0;
        while (substr($hex, $n, 2) == '00') {
            $pad .= '1';
            $n += 2;
        }
        return $pad . $num;
    }
    
    public static function checksum($bin) {
        $checksumAll = hash("sha256", hash("sha256", $bin, true));
        return substr($checksumAll, 0, 8);
    }
    
    public static function encodeHexWithChecksum($hex) {
        $bin = Utils::hex2binS($hex);
        if ($bin === false) {
            return false;
        }
        return self::encodeHex($hex . self::checksum($bin));
    }
    
    public static function encode($bin) {
        if (!is_string($bin)) {
            return false;
        }
        if ($bin == "") {
            return "";
        }
        return self::encodeHex(bin2hex($bin));
    }
    
    public static function encodeWithChecksum($bin) {
        if (!is_string($bin)) {
            return false;
        }
        return self::encodeHex(bin2hex($bin) . self::checksum($bin));
    }
}
