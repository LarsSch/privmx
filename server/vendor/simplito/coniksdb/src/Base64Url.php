<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
namespace privmx\pki;

/**
 * Utility class for handling Base64url encoded data.
 */
class Base64Url 
{
    /**
     * Encodes data with Base64url encoding
     *
     * @param string $input The data to encode
     * @return string Base64url encoded data
     */
    static public function encode($input) 
    {
        $b64 = base64_encode($input);
        return str_replace(array("+", "/", "="), array("-", "_", ""), $b64);
    }
    
    /**
     * Decodes data encoded with Base64url encoding
     *
     * @param string $input The encoded data
     * @return string Decoded data
     */
    static public function decode($input) 
    {
        $b64 = str_replace(array("-", "_"), array("+", "/"), $input);
        $padlen = strlen($b64) % 3;
        if ($padlen > 1)
                $b64 .= "=";
        if ($padlen > 0)
                $b64 .= "=";
        return base64_decode($b64);
    }
}
