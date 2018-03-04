<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\messages;

use privmx\pki\BitString;
use privmx\pki\keystore\PkiData;
use privmx\pki\keystore\KeyStore;
use privmx\pki\keystore\Signature;
use privmx\pki\keystore\DocumentsPacket;

class LeafMessage extends MessageBase
{
    private $hash = null;
    private $index = null;
    private $name = null;
    private $datahash = null;
    private $nonce = null;
    private $revision = null;
    private $keystore = null;
    private $kis = null;
    private $prevRevision = null;

    /**
     * @param bytes hash, required
     * @param BitString index, required
     * @param string name, required
     * @param bytes datahash, required
     * @param bytes revision, required
     * @param KeyStore keystore, required
     * @param Signature kis, required
     * @param bytes nonce, optional
     * @param bytes prevRevision, optional
     */
    public function __construct(
        $hash = null, $index = null, $name = null, $datahash = null, $revision = null,
        $keystore = null, $kis = null, $nonce = null, $prevRevision = null
    )
    {
        if( MessageBase::isEmptyConstructor(array($hash, $index, $name, $datahash, $revision, $keystore, $kis, $prevRevision)) )
            return;

        $this->setHash($hash)->setIndex($index)->setName($name);
        $this->setDatahash($datahash)->setRevision($revision);
        $this->setKeyStore($keystore)->setKis($kis);

        if($nonce !== null)
            $this->setNonce($nonce);
        if($prevRevision !== null)
            $this->setPrevRevision($prevRevision);
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

    public function setIndex($index)
    {
        $index = MessageBase::fromByteBuffer($index);
        if( is_string($index) )
            $index = BitString::decode($index);

        if( !($index instanceof BitString) )
            $this->incorrectFieldType("index");

        $this->index = $index;
        return $this;
    }

    public function getIndex()
    {
        return $this->index;
    }

    public function setName($name)
    {
        if( !is_string($name) )
            $this->incorrectFieldType("name");

        $this->name = $name;
        return $this;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setDatahash($datahash)
    {
        $datahash = MessageBase::fromByteBuffer($datahash);
        if( !is_string($datahash) )
            $this->incorrectFieldType("datahash");

        $this->datahash = $datahash;
        return $this;
    }

    public function getDatahash()
    {
        return $this->datahash;
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

    public function setRevision($revision)
    {
        $revision = MessageBase::fromByteBuffer($revision);
        if( !is_string($revision) )
            $this->incorrectFieldType("revision");

        $this->revision = $revision;
        return $this;
    }

    public function getRevision()
    {
        return $this->revision;
    }

    public function setKeyStore($keystore)
    {
        $keystore = MessageBase::fromByteBuffer($keystore);
        if( is_string($keystore) )
            $keystore = PkiData::decode($keystore);

        if( !($keystore instanceof KeyStore) && !($keystore instanceof DocumentsPacket) )
            $this->incorrectFieldType("keystore");

        $this->keystore = $keystore;
        return $this;
    }

    public function getKeyStore()
    {
        return $this->keystore;
    }

    public function setKis($kis)
    {
        $kis = MessageBase::fromByteBuffer($kis);
        if( is_string($kis) )
            list($kis) = Signature::decode($kis);

        if( !($kis instanceof Signature) )
            $this->incorrectFieldType("kis");

        $this->kis = $kis;
        return $this;
    }

    public function getKis()
    {
        return $this->kis;
    }

    public function setPrevRevision($prevRevision)
    {
        $prevRevision = MessageBase::fromByteBuffer($prevRevision);
        if( !is_string($prevRevision) )
            $this->incorrectFieldType("prevRevision");

        $this->prevRevision = $prevRevision;
        return $this;
    }

    public function getPrevRevision()
    {
        return $this->prevRevision;
    }

    public function validate()
    {
        if( $this->getHash() === null )
            $this->missingField("hash");

        if( $this->getIndex() === null )
            $this->missingField("index");

        if( $this->getName() === null )
            $this->missingField("name");

        if( $this->getDatahash() === null )
            $this->missingField("datahash");

        if( $this->getRevision() === null )
            $this->missingField("revision");

        if( $this->getKeyStore() === null )
            $this->missingField("keystore");

        if( $this->getKis() === null )
            $this->missingField("kis");
    }

    public function psonSerialize()
    {
        $this->validate();
        $result = array(
            "hash" => MessageBase::toByteBuffer($this->getHash()),
            "index" => MessageBase::toByteBuffer(
                $this->getIndex()->encode()
            ),
            "name" => $this->getName(),
            "datahash" => MessageBase::toByteBuffer($this->getDatahash()),
            "revision" => MessageBase::toByteBuffer($this->getRevision()),
            "keystore" => MessageBase::toByteBuffer(
                $this->getKeyStore()->encode("binary")
            ),
            "kis" => MessageBase::toByteBuffer(
                $this->getKis()->encode("binary")
            )
        );

        $tmp = $this->getNonce();
        if( $tmp !== null )
            $result["nonce"] = MessageBase::toByteBuffer($tmp);

        $tmp = $this->getPrevRevision();
        if( $tmp !== null )
            $result["prevRevision"] = MessageBase::toByteBuffer($tmp);

        return $result;
    }

    public function psonUnserialize($pson)
    {
        $pson = (array)$pson;
        if( isset($pson["hash"]) )
            $this->setHash($pson["hash"]);

        if( isset($pson["index"]) )
            $this->setIndex($pson["index"]);

        if( isset($pson["name"]) )
            $this->setName($pson["name"]);

        if( isset($pson["datahash"]) )
            $this->setDatahash($pson["datahash"]);

        if( isset($pson["revision"]) )
            $this->setRevision($pson["revision"]);

        if( isset($pson["keystore"]) )
            $this->setKeyStore($pson["keystore"]);

        if( isset($pson["kis"]) )
            $this->setKis($pson["kis"]);

        if( isset($pson["nonce"]) )
            $this->setNonce($pson["nonce"]);

        if( isset($pson["prevRevision"]) )
            $this->setPrevRevision($pson["prevRevision"]);

        $this->validate();
    }

    public function verify()
    {
        $keystore = $this->getKeyStore();
        if( !$keystore->verifyKis($this->getKis()) )
            return false;

        return $keystore->validate();
    }
}

?>
