<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

class Crypto {
    /**
     * Pseudo random bytes
     */
    public static function randomBytes($length) {
        return openssl_random_pseudo_bytes($length);
    }
    
    /**
     * HMAC-SHA-256
     */
    public static function hmacSha256($key, $data) {
        return hash_hmac("sha256", $data, $key, true);
    }
    
    /**
     * SHA-256 (32 bytes long)
     */
    public static function sha256($data) {
        return hash("sha256", $data, true);
    }
    
    /**
     * SHA-512 (64 bytes long)
     */
    public static function sha512($data) {
        return hash("sha512", $data, true);
    }
    
    /**
     * HASH-160 (RIPEMD-160 at SHA-256)
     */
    public static function hash160($data) {
        return hash("ripemd160", hash("sha256", $data, true), true);
    }
    
    /**
     * Add PKCS7 padding
     */
    public static function pkcs7Pad($data, $pad) {
        $padding = $pad - (strlen($data) % $pad);
        return $data . str_repeat(chr($padding), $padding);
    }
    
    /**
     * Add PKCS7 padding
     */
    public static function pkcs7Unpad($data) {
        $padding = ord($data[strlen($data) - 1]);
        return substr($data, 0, -$padding);
    }
    
    /**
     * AES-256-CBC with PKCS7 padding encryption
     */
    public static function aes256CbcPkcs7Encrypt($data, $key, $iv) {
        return openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    }
    
    /**
     * AES-256-CBC with PKCS7 padding decryption
     */
    public static function aes256CbcPkcs7Decrypt($data, $key, $iv) {
        return openssl_decrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    }
    
    /**
     * AES-256-ECB encryption
     */
    public static function aes256EcbEncrypt($data, $key) {
        return openssl_encrypt($data, 'AES-256-ECB', $key, OPENSSL_RAW_DATA|OPENSSL_ZERO_PADDING);
    }
    
    /**
     * AES-256-ECB decryption
     */
    public static function aes256EcbDecrypt($data, $key) {
        return openssl_decrypt($data, 'AES-256-ECB', $key, OPENSSL_RAW_DATA|OPENSSL_ZERO_PADDING);
    }
    
    /**
     * Key Derivation Function
     * See: http://nvlpubs.nist.gov/nistpubs/Legacy/SP/nistspecialpublication800-108.pdf
     */ 
    public static function kdf($algo, $length, $key, $options = array()) {
        if (is_string($options)) {
            $options = array("label" => $options);
        }
        $counters = isset($options["counters"]) ? $options["counters"] : true;
        $feedback = isset($options["feedback"]) ? $options["feedback"] : true;
        if (isset($options["seed"])) {
            $seed = $options["seed"];
        } else {
            $label   = isset($options["label"]) ? $options["label"] : "";
            $context = isset($options["context"]) ? $options["context"] : "";
            $seed = $label . chr(0) . $context . pack('N', $length);
        }
        $k = isset($options["iv"]) ? $options["iv"] : "";
        $result = "";
        $i = 1;
        while(strlen($result) < $length) {
            $input = "";
            if ($feedback) {
                $input .= $k;
            }
            if ($counters) {
                $input .= pack('N', $i++);
            }
            $input .= $seed;
            $k = hash_hmac($algo, $input, $key, true);
            $result .= $k;
        }
        return substr($result, 0, $length);
    }

    /**
     * TLS 1.2 key derivation function
     */
    public static function prf_tls12($key, $seed, $length) {
        $a = $seed;
        $result = "";
        while (strlen($result) < $length) {
            $a = hash_hmac("sha256", $a, $key, true);
            $result .= hash_hmac("sha256", $a . $seed, $key, true);
        }
        return substr($result, 0, $length);
    }

    /**
     * Derives encryption and authentication keys from a given secret key
     */
    public static function getKEM($algo, $key, $kelen = 32, $kmlen = 32) {
        $kEM = static::kdf($algo, $kelen + $kmlen, $key, "key expansion");
        $kE = substr($kEM, 0, $kelen);
        $kM = substr($kEM, $kelen);
        return array($kE, $kM);
    }

    /**
     * AES-256-CBC with PKCS7 padding and SHA-256 HMAC with NIST compatible KDF.
     */
    public static function aes256CbcHmac256Encrypt($data, $key32, $deterministic = false, $taglen = 16) {
        list($kE, $kM) = static::getKEM("sha256", $key32);


        if ($deterministic) {
            $iv = substr( hash_hmac("sha256", $data, $key32, true), 0, 16 );
        } else {
            $iv = openssl_random_pseudo_bytes(16);
        }
        // We prefix data with block of zeroes - and so from our IV we obtain E(IV) in first block.
        $data = str_repeat(chr(0), 16) . $data;
        $cipher = static::aes256CbcPkcs7Encrypt($data, $kE, $iv);
        $tag = substr( hash_hmac("sha256", $cipher, $kM, true), 0, $taglen );
        return $cipher . $tag;
    }

    /**
     * AES-256-CBC with PKCS7 padding and SHA-256 HMAC with NIST compatible KDF.
     */
    public static function aes256CbcHmac256Decrypt($data, $key32, $taglen = 16) {
        list($kE, $kM) = static::getKEM("sha256", $key32);

        $len = strlen($data);
        $tag  = substr($data, $len - $taglen);
        $data = substr($data, 0, $len - $taglen);
        $rtag = substr( hash_hmac("sha256", $data, $kM, true), 0, $taglen );
        if ($tag != $rtag) {
            throw new \Exception("Wrong message security tag");
        }
        $iv = substr($data, 0, 16);
        $data = substr($data, 16);
        return static::aes256CbcPkcs7Decrypt($data, $kE, $iv);
    }
}
