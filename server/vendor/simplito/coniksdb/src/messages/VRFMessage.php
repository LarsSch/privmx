<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\messages;

use BN\BN;
use Elliptic\EC;
use privmx\pki\VRF;
use privmx\pki\BitString;

class VRFMessage extends MessageBase
{
    private $value = null;
    private $s = null;
    private $t = null;

    /**
     * @param BitString value, required
     * @param BN s, required
     * @param BN t, required
     */
    public function __construct($value = null, $s = null, $t = null)
    {
        if( MessageBase::isEmptyConstructor(array($value, $s, $t)) )
            return;
        $this->setValue($value)->setProof($s, $t);
    }

    public function setValue($index)
    {
        $index = MessageBase::fromByteBuffer($index);
        if( is_string($index) )
            $index = BitString::decode($index);

        if( !($index instanceof BitString) )
            $this->incorrectFieldType("value");

        $this->value = $index;
        return $this;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function setProof($s, $t)
    {
        $s = MessageBase::fromByteBuffer($s);
        if( is_string($s) )
            $s = new BN(bin2hex($s), 16);

        if( !($s instanceof BN) )
            $this->incorrectFieldType("proof - s");

        $this->s = $s;

        $t = MessageBase::fromByteBuffer($t);
        if( is_string($t) )
            $t = new BN(bin2hex($t), 16);

        if( !($t instanceof BN) )
            $this->incorrectFieldType("proof - t");

        $this->t = $t;
        return $this;
    }

    public function getProof()
    {
        if( $this->s === null || $this->t === null )
            return null;
        return array($this->s, $this->t);
    }

    public function validate()
    {
        if( $this->getValue() === null )
            $this->missingField("value");
        if( $this->getProof() === null )
            $this->missingField("proof (s,t)");
    }

    private static function hex2bin($hex)
    {
        if( strlen($hex) % 2 )
            $hex = "0" . $hex;
        return hex2bin($hex);
    }

    public function psonSerialize()
    {
        $this->validate();
        list($s, $t) = $this->getProof();
        $result = array(
            "value" => MessageBase::toByteBuffer(
                $this->getValue()->encode()
            ),
            "s" => MessageBase::toByteBuffer(
                self::hex2bin($s->toString("hex"))
            ),
            "t" => MessageBase::toByteBuffer(
                self::hex2bin($t->toString("hex"))
            )
        );
        return $result;
    }

    public function psonUnserialize($pson)
    {
        $pson = (array)$pson;
        if( isset($pson["value"]) )
            $this->setValue($pson["value"]);

        if( isset($pson["s"]) && isset($pson["t"]) )
            $this->setProof($pson["s"], $pson["t"]);

        $this->validate();
    }

    public function verify($name, $keystore)
    {
        $key = $keystore->getPrimaryKey();
        $ctx = new VRF($key->keyPair);
        $ec = new EC("secp256k1");
        $value = $this->getValue();
        $value = $ec->keyFromPublic(bin2hex($value->bytes()), "hex")->getPublic();
        $proof = $this->getProof();
        return $ctx->verify($name, $value, $proof);
    }
}

?>
