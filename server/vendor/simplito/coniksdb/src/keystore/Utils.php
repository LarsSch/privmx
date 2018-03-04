<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\keystore;

use \Exception;

// deprecated
class Utils {
    static function crc24($data) {
        $crc = 0x00b704ce;
        
        for ($i = 0; $i < strlen($data); $i++) {
            $crc ^= (ord($data[$i]) & 255) << 16;
            for ($j = 0; $j < 8; $j++) {
                $crc <<= 1;
                if ($crc & 0x01000000) {
                    $crc ^= 0x01864cfb;
                }
            }
        }
        
        return $crc & 0x00ffffff;
    }

    static function bitlength($data) {
        return (strlen($data) - 1) * 8 + (int)floor(log(ord($data[0]), 2)) + 1;
    }

    static function readMpiLength($input) {
        $length = reset(unpack('n', $input));
        $length = (int) floor(($length + 7) / 8);
        return $length;
    }

    static function parseLength($input){
        $header_start = 0;
        $len = ord($input[0]);
        if($len < 192) { // One octet length
            return array(1, $len, false);
        }

        if($len > 191 && $len < 224) { // Two octet length
            return array(2, (($len - 192) << 8) + ord($input[$header_start + 1]) + 192, false);
        }
        if($len == 255) { // Five octet length
            $unpacked = unpack('N', substr($input, $header_start + 1, 4));
            return array(5, reset($unpacked), false);
        }

        // Partial body lengths
        return array(1, 1 << ($len & 0x1f), true);
    }

    static function parseHeader($input){
        if(ord($input[0]) & 64){
            $header_start = 0;
            $tag = ord($input[0]) & 63;
            $len = ord($input[$header_start + 1]);
            if($len < 192) { // One octet length
                return array($tag, 2, $len, false);
            }

            if($len > 191 && $len < 224) { // Two octet length
                return array($tag, 3, (($len - 192) << 8) + ord($input[$header_start + 2]) + 192, false);
            }
            if($len == 255) { // Five octet length
                $unpacked = unpack('N', substr($input, $header_start + 2, 4));
                return array($tag, 6, reset($unpacked), false);
            }

            // Partial body lengths
            return array($tag, 2, 1 << ($len & 0x1f), true);
        }

        $len = ($tag = ord($input[0])) & 3;
        $tag = ($tag >> 2) & 15;
        
        $head_length = 0;
        $data_length = 0;

        switch ($len) {
            case 0: // The packet has a one-octet length. The header is 2 octets long.
                $head_length = 2;
                $data_length = ord($input[1]);
                break;
            case 1: // The packet has a two-octet length. The header is 3 octets long.
                $head_length = 3;
                $data_length = unpack('n', substr($input, 1, 2));
                $data_length = $data_length[1];
                break;
            case 2: // The packet has a four-octet length. The header is 5 octets long.
                $head_length = 5;
                $data_length = unpack('N', substr($input, 1, 4));
                $data_length = $data_length[1];
                break;
            case 3: // The packet is of indeterminate length. The header is 1 octet long.
                $head_length = 1;
                $data_length = strlen($input) - $head_length;
                break;
        }

        return array($tag, $head_length, $data_length, false);  
    }

    public static function decodeKey($id){
        if(!in_array($id, array_keys(self::$algorithms))){
            throw new Exception("Not implemented");
        }

        return self::$algorithms[$id];
    }

    public static $algorithms = [
        Algorithm::ECDH => "ConiksDb\Ecc\EccPublicKey",
        Algorithm::ECDSA => "ConiksDb\Ecc\EccPublicKey"
    ];

    public static function encodeLength($length)
    {
        if( $length < 192 )
            return chr($length);

        if( $length > 8382 )
            return chr(0xFF).pack("N", $length);

        $length -= 192;
        return chr((($length >> 8) & 0xFF) + 192) . chr($length & 0xFF);
    }
    
    public static function hashAlgorithmName($hashAlgorithm) {
        switch($hashAlgorithm) {
            case Algorithm::MD5:
                return "md5";
            case Algorithm::SHA1:
                return "sha1";
            case Algorithm::RIPEMD160:
                return "ripemd160";
            case Algorithm::SHA256:
                return "sha256";
            case Algorithm::SHA384:
                return "sha384";
            case Algorithm::SHA512:
                return "sha512";
            case Algorithm::SHA224:
                return "sha224";
            default:
                throw new Exception("Unimplemented hash algorithm ". $hashAlgorithm);
        }
    }
    
    public static function hashLength($hashAlgorithm) {
        switch($hashAlgorithm) {
            case Algorithm::MD5:
                return 16;
            case Algorithm::SHA1:
                return 20;
            case Algorithm::RIPEMD160:
                return 20;
            case Algorithm::SHA256:
                return 32;
            case Algorithm::SHA384:
                return 48;
            case Algorithm::SHA512:
                return 64;
            case Algorithm::SHA224:
                return 28;
            default:
                throw new Exception("Unimplemented hash algorithm ". $hashAlgorithm);
        }
    }
    
    public static function hashWithAlgorithm($data, $hashAlgorithm) {
        return hash(Utils::hashAlgorithmName($hashAlgorithm), $data, true);
    }
}
