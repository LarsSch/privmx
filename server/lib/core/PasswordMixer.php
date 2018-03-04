<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

use \Exception;

class PasswordMixer 
{
    private function get($arr, $key, $default)
    {
        return isset($arr[$key]) ? $arr[$key] : $default;
    }

    public function mix($password, $data)
    {
        $algorithm = $this->get($data, "algorithm", "none");
        if( $algorithm !== "PBKDF2" )
            throw new Exception("Unsupported algorithm '{$algorithm}'");

        $hash = $this->get($data, "hash", "none");
        $version = $this->get($data, "version", -1);
        if( $hash !== "SHA512" )
            throw new Exception("Unsupported hash alrgorithm '{$hash}'");

        if( $version !== 1 )
            throw new Exception("Unsupported version {$version}");

        $salt = base64_decode($this->get($data, "salt", ""));
        $length = $this->get($data, "length", -1);
        if( strlen($salt) !== 16 || $length !== 16 )
        {
            $salt = base64_encode($salt);
            throw new Exception("Incorrect params - salt: {$salt}, length: {$length}");
        }

        $rounds = $this->get($data, "rounds", -1);

        return hash_pbkdf2($hash, $password, $salt, $rounds, $length, true);
    }
};

?>
