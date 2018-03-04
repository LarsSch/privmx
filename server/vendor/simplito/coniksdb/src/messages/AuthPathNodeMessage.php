<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\messages;

use privmx\pki\BitString;
use privmx\pki\Utils;

class AuthPathNodeMessage extends MessageBase
{
    private $hash = null;
    private $auth = null;

    /**
     * @param bytes hash, required
     * @param BitString | uint32 auth(index|prefix), required
     */
    public function __construct($hash = null, $auth = null)
    {
        if( MessageBase::isEmptyConstructor(array($hash, $auth)) )
            return;
        $this->setHash($hash)->setAuth($auth);
    }

    public function setHash($hash)
    {
        $hash = MessageBase::fromByteBuffer($hash);
        if( !is_string($hash) )
            $this->incorrectFieldType("hash");

        $this->hash = $hash;
        return $this;
    }

    public function getHash()
    {
        return $this->hash;
    }

    public function setAuth($auth)
    {
        // index else prefix
        if( !is_int($auth) )
        {
            $auth = MessageBase::fromByteBuffer($auth);
            if( is_string($auth) )
                $auth = BitString::decode($auth);

            if( !($auth instanceof BitString) )
                $this->incorrectFieldType("auth");
        }

        $this->auth = $auth;
        return $this;
    }

    public function getAuth()
    {
        return $this->auth;
    }

    public function validate()
    {
        if( $this->getHash() === null )
            $this->missingField("hash");
        if( $this->getAuth() === null )
            $this->missingField("auth");
    }

    public function psonSerialize()
    {
        $this->validate();
        $auth = $this->getAuth();
        $result = array(
            "hash" => MessageBase::toByteBuffer($this->getHash()),
            "auth" => is_int($auth) ? $auth : MessageBase::toByteBuffer($auth->encode())
        );
        return $result;
    }

    public function psonUnserialize($pson)
    {
        $pson = (array)$pson;
        if( isset($pson["hash"]) )
            $this->setHash($pson["hash"]);

        if( isset($pson["auth"]) )
            $this->setAuth($pson["auth"]);

        $this->validate();
    }

    public function authindex(BitString $index)
    {
        $auth = $this->getAuth();
        if( $auth instanceof BitString )
            return $auth;
        return $index->prefix($auth);
    }

    public function hash(BitString $authindex, $hash = null)
    {
        if( $hash === null )
            $hash = $this->getHash();

        return Utils::hash($authindex->encode() . $hash);
    }

    public function interhash($prev, $dir)
    {
        $hash = $this->getHash();
        $bin = $dir ? $hash . $prev : $prev . $hash;
        return Utils::hash($bin);
    }
}

?>
