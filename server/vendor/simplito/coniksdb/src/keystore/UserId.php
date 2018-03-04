<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\keystore;

use Exception;

class UserId extends Packet {
    private $parent;
    public $name;
    public $signatures;

    function __construct($parent, $name)
    {
        $this->name = $name;
        $this->parent = $parent;
        $this->signatures = [];
    }

    protected function getTag()
    {
        return Packet::USER_ID;
    }

    protected function getBody()
    {
        return $this->name;
    }

    public function bind($flags)
    {
        $signature = Signature::create(array(
            "type" => SignatureType::POSITIVE_PK_USER_ID_CERTIFICATION,
            "data" => array(
                "key" => $this->parent,
                "userId" => $this->name
            ),
            "hashed" => array(new KeyFlagsSubpacket($flags))
        ), $this->parent);

        array_push($this->signatures, $signature);
    }

    protected function encodeRaw()
    {
        return parent::encodeRaw() . Packet::concat($this->signatures);
    }

    public function getBindSignature()
    {
        $result = null;
        foreach($this->signatures as $signature)
        {
            switch($signature->type)
            {
                case SignatureType::GENERIC_PK_USER_ID_CERTIFICATION:
                case SignatureType::PERSONA_PK_USER_ID_CERTIFICATION:
                case SignatureType::CASUAL_PK_USER_ID_CERTIFICATION:
                case SignatureType::POSITIVE_PK_USER_ID_CERTIFICATION:
                    if( $result === null || $result->getTimestamp() < $signature->getTimestamp() )
                        $result = $signature;
            }
        }

        return $result;
    }

    public function validate()
    {
        $signature = $this->getBindSignature();
        if( $signature === null )
            return false;

        return $signature->verify(array(
            "key" => $this->parent,
            "userId" => $this->name
        ), $this->parent);
    }

    public static function decode($parent, $data)
    {
        list($tag, $body, $additional) = Packet::decodePacket($data);

        if( $tag !== Packet::USER_ID )
            throw new Exception("Incorrect tag for UserId packet {$tag}");

        $result = new UserId($parent, $body);
        while( strlen($additional) > 0 && Packet::isTag(ord($additional[0]), Packet::SIGNATURE) )
        {
            list($signature, $additional) = Signature::decode($additional);
            array_push($result->signatures, $signature);
        }

        return array($result, $additional);
    }
}