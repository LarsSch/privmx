<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

class CryptoPrivFs {
    
    const AES_256_CBC_PKC7_NO_IV = 1;
    const AES_256_CBC_PKC7_WITH_IV = 2;
    const AES_256_CBC_PKC7_WITH_IV_AND_HMAC_SHA256 = 4;
    
    /**
     * Generate IV from index for AES (16 bytes long)
     */
    public static function generateIv($key, $idx) {
        return substr(Crypto::hmacSha256($key, "iv" . $idx), 0, 16);
    }
    
    /**
     * Reduct 32-bytes long key to 16-bytes long by SHA-256 and take first 16 bytes
     */
    public static function reductKey($key) {
        return substr(Crypto::sha256($key), 0, 16);
    }
    
    /**
     * AES-256-CBC with PKCS7 padding encryption without attached IV
     */
    public static function aesEncryptWithDetachedIv($data, $key, $iv) {
        return chr(static::AES_256_CBC_PKC7_NO_IV) . Crypto::aes256CbcPkcs7Encrypt($data, $key, $iv);
    }
    
    /**
      * AES-256-CBC with PKCS7 padding encryption with attached IV
     */
    public static function aesEncryptWithAttachedIv($data, $key, $iv) {
        return chr(static::AES_256_CBC_PKC7_WITH_IV) . $iv . Crypto::aes256CbcPkcs7Encrypt($data, $key, $iv);
    }
    
    /**
     * AES-256-CBC with PKCS7 padding encryption with attached random IV
     */
    public static function aesEncryptWithAttachedRandomIv($data, $key) {
        return static::aesEncryptWithAttachedIv($data, $key, openssl_random_pseudo_bytes(16));
    }
    
    public static function aes256CbcHmac256Encrypt($data, $key32, $deterministic = false, $taglen = 16) {
        $encrypted = Crypto::aes256CbcHmac256Encrypt($data, $key32, $deterministic, $taglen);
        return chr(static::AES_256_CBC_PKC7_WITH_IV_AND_HMAC_SHA256) . $encrypted;
    }

    public static function decrypt($data, $key32 = null, $iv16 = null) {
        $type = ord($data[0]);
        if ($type == static::AES_256_CBC_PKC7_NO_IV) {
            return Crypto::aes256CbcPkcs7Decrypt(substr($data, 1), $key32, $iv16);
        }
        if ($type == static::AES_256_CBC_PKC7_WITH_IV) {
            return Crypto::aes256CbcPkcs7Decrypt(substr($data, 17), $key32, substr($data, 1, 16));
        }
        if ($type == static::AES_256_CBC_PKC7_WITH_IV_AND_HMAC_SHA256) {
            return Crypto::aes256CbcHmac256Decrypt(substr($data, 1), $key32);
        }
        throw new \Exception("Unknown decryption type " . $type);
    }
}
