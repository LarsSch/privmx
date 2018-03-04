<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\keystore;

use Exception;

class DocumentsPacket extends Packet implements IPkiData {
    
    public $primaryKey;
    public $keys;
    public $attachmentPointerList;
    public $attachmentDataList;
    
    public function __construct() {
        $this->keys = array();
        $this->attachmentPointerList = array();
        $this->attachmentDataList = array();
    }
    
    protected function getTag() {
        return 0;
    }
    
    protected function getBody() {
        $pkiDataType = new PkiDataTypePacket(Packet::TYPE_DOCUMENT);
        return $pkiDataType->encode("binary") .
            Packet::concat($this->keys) .
            Packet::concat($this->attachmentPointerList) .
            Packet::concat($this->attachmentDataList);
    }
    
    protected function encodeRaw() {
        return $this->getBody();
    }
    
    public function validate() {
        return count($this->keys) > 0 && AttachmentsStorage::validate($this);
    }
    
    public function isValidToSave() {
        return count($this->keys) > 0 && AttachmentsStorage::isValidToSave($this);
    }
    
    public static function decode($data) {
        list($pkiDataType, $data) = PkiDataTypePacket::decode($data);
        if ($pkiDataType->type != Packet::TYPE_DOCUMENT) {
            throw new Exception("Expected type to be DOCUMENT but get {$pkiDataType->type}");
        }
        $result = new DocumentsPacket();
        while (strlen($data) > 0) {
            if (Packet::isTag(ord($data[0]), Packet::PUBLIC_KEY)) {
                list($key, $data) = KeyPair::decode($data);
                array_push($result->keys, $key);
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
    
    public function addAttachment($fileName, $data) {
        AttachmentsStorage::addAttachment($this, $fileName, $data);
    }
    
    public function getAttachment($fileName) {
        return AttachmentsStorage::getAttachment($this, $fileName);
    }
    
    public function getPublicView() {
        $clone = self::decode($this->encode("binary"));
        foreach($clone->keys as $key) {
            $key->removePrivate();
        }
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
    
    public function getKeyById($id) {
        $id = strtoupper($id);
        foreach ($this->keys as $key) {
            $found = $key->getKeyById($id);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }
    
    public function generateKis($hash) {
        if( $this->primaryKey == null || $this->getKeyById($this->primaryKey->getKeyId()) == null) {
            throw new Exception("Invalid signing key");
        }
        $keystore = $this->getPublicAttachmentView(false);
        $data = $keystore->encode("binary");
        return Signature::create(array(
            "data" => array("msg" => $data),
            "hashed" => array(new ConiksDbIdSubpacket($hash, time()))
        ), $this->primaryKey);
    }
    
    public function verifyKis(Signature $kis) {
        $key = $this->getKeyById($kis->getIssuerId());
        if ($key === null) {
            return false;
        }
        return $kis->verify(array("msg" => $this->getPublicAttachmentView(false)->encode("binary")), $key);
    }
    
    public function isCompatibleWithPrevious(Signature $kis, $prev) {
        //Kis is signed by authorized key
        $key = $prev->getKeyById($kis->getIssuerId());
        if ($key === null) {
            return false;
        }
        if (!$kis->verify(array("msg" => $this->getPublicAttachmentView(false)->encode("binary")), $key)) {
            return false;
        }
        //Packet still contains signing key
        if ($this->getKeyById($key->getKeyId()) === null) {
            return false;
        }
        return true;
    }
}
