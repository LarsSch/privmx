<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\messages;

use privmx\pki\keystore\Signature;
use privmx\pki\Utils;

function is_uint64($value) {
    if (PHP_INT_SIZE == 4)
        return (is_int($value) || (is_float($value) && fmod($value, 1.0) == 0.0)) && ($value >= 0);
    return is_int($value) && ($value >= 0);
}

class TreeMessage extends MessageBase
{
    private $hash = null;
    private $version = null;
    private $prevTree = null;
    private $root = null;
    private $timestamp = null;
    private $nonce = null;
    private $signature = null;
    private $seq = null;

    /**
     * @param bytes hash, required
     * @param uint32 version, required
     * @param bytes prevTree, required
     * @param bytes root, required
     * @param uint64 timestamp, required
     * @param Signature signature, required
     * @param uin64 seq, required
     * @param bytes nonce, optional
     */
    public function __construct(
        $hash = null, $version = null, $prevTree = null, $root = null,
        $timestamp = null, $signature = null, $seq = null, $nonce = null
    )
    {
        if( MessageBase::isEmptyConstructor(array($hash, $version, $prevTree, $root, $timestamp, $signature, $seq, $nonce)) )
            return;

        $this->setHash($hash)->setVersion($version)->setPrevTree($prevTree)->setRoot($root);
        $this->setTimestamp($timestamp)->setSignature($signature)->setSeq($seq);
        if( $nonce !== null )
            $this->setNonce($nonce);
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

    public function setVersion($version)
    {
        if( !is_int($version) )
            $this->incorrectFieldType("version");

        $this->version = $version;
        return $this;
    }

    public function getVersion()
    {
        return $this->version;
    }

    public function setPrevTree($prevTree)
    {
        $prevTree = MessageBase::fromByteBuffer($prevTree);
        if( !is_string($prevTree) )
            $this->incorrectFieldType("prevTree");

        $this->prevTree = $prevTree;
        return $this;
    }

    public function getPrevTree()
    {
        return $this->prevTree;
    }

    public function setRoot($root)
    {
        $root = MessageBase::fromByteBuffer($root);
        if( !is_string($root) )
            $this->incorrectFieldType("root");
        $this->root = $root;
        return $this;
    }

    public function getRoot()
    {
        return $this->root;
    }

    public function setTimestamp($timestamp)
    {
        if (!is_numeric($timestamp))
            $timestamp = MessageBase::decodeUint64($timestamp);
        if( !is_numeric($timestamp) )
            $this->incorrectFieldType("timestamp");

        $this->timestamp = $timestamp;
        return $this;
    }

    public function getTimestamp()
    {
        return $this->timestamp;
    }

    public function setNonce($nonce)
    {
        $nonce = MessageBase::fromByteBuffer($nonce);
        if( !is_string($nonce) )
            $this->incorrectFieldType("nonce");

        $this->nonce = $nonce;
        return $this;
    }

    public function getNonce()
    {
        return $this->nonce;
    }

    public function setSignature($signature)
    {
        $signature = MessageBase::fromByteBuffer($signature);
        if( is_string($signature) )
            list($signature) = Signature::decode($signature);

        if( !($signature instanceof Signature) )
            $this->incorrectFieldType("signature");

        $this->signature = $signature;
        return $this;
    }

    public function getSignature()
    {
        return $this->signature;
    }

    public function setSeq($seq)
    {
        if( !is_uint64($seq) )
            $this->incorrectFieldType("seq");

        $this->seq = $seq;
        return $this;
    }

    public function getSeq()
    {
        return $this->seq;
    }

    public function validate()
    {
        if( $this->getHash() === null )
            $this->missingField("hash");

        if( $this->getVersion() === null )
            $this->missingField("version");

        if( $this->getPrevTree() === null )
            $this->missingField("prevTree");

        if( $this->getPrevTree() === null )
            $this->missingField("root");

        if( $this->getTimestamp() === null )
            $this->missingField("timestamp");

        if( $this->getSignature() === null )
            $this->missingField("signature");

        if( $this->getSeq() === null )
            $this->missingField("seq");
    }

    public function psonSerialize()
    {
        $this->validate();
        $result = array(
            "hash" => MessageBase::toByteBuffer($this->getHash()),
            "version" => $this->getVersion(),
            "prevTree" => MessageBase::toByteBuffer($this->getPrevTree()),
            "root" => MessageBase::toByteBuffer($this->getRoot()),
            "timestamp" => MessageBase::encodeUint64($this->getTimestamp(), true),
            "signature" => MessageBase::toByteBuffer(
                $this->getSignature()->encode("binary")
            ),
            "seq" => $this->getSeq()
        );

        $nonce = $this->getNonce();
        if( $nonce !== null )
            $result["nonce"] = MessageBase::toByteBuffer($nonce);

        return $result;
    }

    public function psonUnserialize($pson)
    {
        $pson = (array)$pson;
        if( isset($pson["hash"]) )
            $this->setHash($pson["hash"]);

        if( isset($pson["version"]) )
            $this->setVersion($pson["version"]);

        if( isset($pson["prevTree"]) )
            $this->setPrevTree($pson["prevTree"]);

        if( isset($pson["root"]) )
            $this->setRoot($pson["root"]);

        if( isset($pson["timestamp"]) )
            $this->setTimestamp($pson["timestamp"]);

        if( isset($pson["signature"]) )
            $this->setSignature($pson["signature"]);

        if( isset($pson["seq"]) )
            $this->setSeq($pson["seq"]);

        if( isset($pson["nonce"]) )
            $this->setNonce($pson["nonce"]);

        $this->validate();
    }

    public function verify($keystore, $skipSignature = false)
    {
        $signature = $this->getSignature();
        $key = $keystore->getKeyById($signature->getIssuerId());
        if ($key === null) {
            return false;
        }
        $nonce = $this->getNonce();
        if ($nonce === null) {
            $nonce = "";
        }
        $bin = pack('N', $this->getVersion()) .
            $this->getPrevTree() .
            $this->getRoot() .
            MessageBase::encodeUint64($this->getTimestamp())->toBinary().
            $nonce .
            MessageBase::encodeUint64($this->getSeq())->toBinary();
        
        $hash = Utils::hash($signature->encode("binary") . $bin);
        if ($this->getHash() !== $hash) {
            return false;
        }
        return $skipSignature ? true : $key->verify($bin, $signature);
    }
}

?>
