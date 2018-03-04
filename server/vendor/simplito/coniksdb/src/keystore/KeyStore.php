<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\keystore;

use Exception;

class KeyStore extends Packet implements IPkiData
{
    public static function decode($data) {
        list($pkiDataType, $data) = PkiDataTypePacket::decode($data);
        if ($pkiDataType->type != Packet::TYPE_KEYSTORE) {
            throw new Exception("Expected type to be KEYSTORE but get {$pkiDataType->type}");
        }
        $result = new KeyStore();
        while (strlen($data) > 0) {
            if (Packet::isTag(ord($data[0]), Packet::SECRET_KEY) || Packet::isTag(ord($data[0]), Packet::PUBLIC_KEY)) {
                list($key, $data) = KeyPair::decode($data);
                $result->addKey($key);
            }
            else if (Packet::isTag(ord($data[0]), Packet::ATTACHMENT_POINTER)) {
                list($pointer, $data) = AttachmentPointerPacket::decode($data);
                array_push($result->attachmentPointerList, $pointer);
            }
            else if (Packet::isTag(ord($data[0]), Packet::ATTACHMENT_DATA)) {
                list($aData, $data) = AttachmentDataPacket::decode($data);
                array_push($result->attachmentDataList, $aData);
            }
            else {
                break;
            }
        }
        if (strlen($data) > 0) {
            throw new Exception("Unexpected packets");
        }
        return $result;
    }

    private $keys = [];
    public $attachmentPointerList;
    public $attachmentDataList;

    public function __construct($userId = null, KeyPair $key = null)
    {
        $this->attachmentPointerList = array();
        $this->attachmentDataList = array();
        if( $userId !== null )
        {
            $key = $this->addKey($key);
            $key->addUserId($userId);
        }
    }

    protected function getArmor()
    {
        $key = $this->getPrimaryKey();
        if( $key === null )
            return parent::getArmor();

        return $key->getArmor();
    }

    protected function getTag() { return 0; }
    protected function getBody()
    {
        $pkiDataType = new PkiDataTypePacket(Packet::TYPE_KEYSTORE);
        return $pkiDataType->encode("binary") .
            Packet::concat($this->keys) .
            Packet::concat($this->attachmentPointerList) .
            Packet::concat($this->attachmentDataList);
    }

    protected function encodeRaw()
    {
        return $this->getBody();
    }
    
    public function addAttachment($fileName, $data) {
        AttachmentsStorage::addAttachment($this, $fileName, $data);
    }
    
    public function getAttachment($fileName) {
        return AttachmentsStorage::getAttachment($this, $fileName);
    }

    public function getPublicView()
    {
        $clone = self::decode($this->encode("binary"));
        foreach($clone->keys as $key)
            $key->removePrivate();

        return $clone;
    }
    
    public function getAttachmentView($includeAttachments) {
        $clone = self::decode($this->encode("binary"));
        $clone->attachmentDataList = AttachmentsStorage::filterData($this, $includeAttachments);
        return $clone;
    }
    
    public function getPublicAttachmentView($includeAttachments) {
        $clone = self::decode($this->encode("binary"));
        foreach($clone->keys as $key) {
            $key->removePrivate();
        }
        $clone->attachmentDataList = AttachmentsStorage::filterData($this, $includeAttachments);
        return $clone;
    }
    
    public function hasAnyPrivate() {
        foreach($this->keys as $key) {
            if ($key->hasAnyPrivate()) {
                return true;
            }
        }
        return false;
    }

    public function addKey(KeyPair $key = null)
    {
        if( $key === null )
            $key = new KeyPair();
        array_push($this->keys, $key);
        return $key;
    }

    public function getPrimaryKey()
    {
        if( count($this->keys) === 0 )
            return null;
        return $this->keys[0];
    }

    public function getSubkeys()
    {
        $key = $this->getPrimaryKey();
        return $key === null ? array() : $key->subkeys;
    }

    public function getPrimaryUserId()
    {
        $key = $this->getPrimaryKey();
        return $key === null ? "" : $key->getPrimaryUserId();
    }

    public function getKeyById($id)
    {
        $id = strtoupper($id);
        foreach($this->keys as $key)
        {
            $found = $key->getKeyById($id);
            if( $found !== null )
                return $found;
        }
        return null;
    }

    public function validate()
    {
        foreach($this->keys as $key)
        {
            if( !$key->validate() )
                return false;
        }
        return AttachmentsStorage::validate($this);
    }
    
    public function isValidToSave() {
        foreach($this->keys as $key)
        {
            if( !$key->validate() )
                return false;
        }
        return AttachmentsStorage::isValidToSave($this);
    }

    public function generateKis($hash, $id = null)
    {
        $primary = $this->getPrimaryKey();
        $primaryId = $primary->getKeyId();
        $key = $id !== null ? $this->getKeyById($id) : $primary;

        if( $key === null || ($key->isRevoked() && $key->getKeyId() !== $primaryId) || !($key->getFlags() & KeyFlag::SIGNING) )
            throw new Exception("Cannot generate KIS");

        $keystore = $this->getPublicAttachmentView(false);
        $data = $keystore->encode("binary");

        return Signature::create(array(
            "data" => array("msg" => $data),
            "hashed" => array(new ConiksDbIdSubpacket($hash, time()))
        ), $key);
    }
    
    public function verifyKis(Signature $kis) {
        $key = $this->getKeyById($kis->getIssuerId());
        if ($key === null || $key->isRevoked() || !($key->getFlags() & KeyFlag::SIGNING)) {
            return false;
        }
        return $kis->verify(array("msg" => $this->getPublicAttachmentView(false)->encode("binary")), $key);
    }
    
    public function isCompatibleWithPrevious(Signature $kis, $prev) {
        //Kis is signed by authorized key
        $key = $prev->getKeyById($kis->getIssuerId());
        if ($key === null || $key->isRevoked() || !($key->getFlags() & KeyFlag::SIGNING)) {
            return false;
        }
        if (!$kis->verify(array("msg" => $this->getPublicAttachmentView(false)->encode("binary")), $key)) {
            return false;
        }
        //Packet still contains signing key
        if ($this->getKeyById($key->getKeyId()) === null) {
            return false;
        }
        //Key heritage and revocation
        foreach($prev->keys as $prevkey) {
            $key = $this->getKeyById($prevkey->getKeyId());
            if ($key === null || ($prevkey->isRevoked() && !$key->isRevoked())) {
                return false;
            }
            foreach($prevkey->subkeys as $prevsub) {
                $subkey = $this->getKeyById($prevsub->getKeyId());
                if ($subkey === null || ($prevsub->isRevoked() && !$subkey->isRevoked())) {
                    return false;
                }
            }
        }
        return true;
    }
}
