<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\keystore;

class ConiksDbSignature extends Signature{
    public $id;
    public $timestamp;
    public $privateKey;
    public $parent;
    

    public function __construct($id=null, $privateKey=null, $timestamp=null){
        parent::__construct();

        if($id != null && $privateKey != null){
            $this->privateKey = $privateKey;
            $this->id = $id;
            if($timestamp == null){
                $timestamp = time();
            }
            $this->timestamp = $timestamp;

            $this->hashedPackets[] = new ConiksDbIdSubpacket($id, $this->timestamp);

            $this->unhashedPackets[] = new IssuerSubpacket($this->privateKey->getKeyId());
        }
         
        $this->type = 0x02;
    }

    function generateDataToSign(){
        $key = $this->parent;
        
        $data = 
            implode('', $key->encode()) 
            ;
        
        $hashedData = $this->getHashedPacketsBytes();
        $trailer =
            chr(0x04) . //version
            chr($this->type) .
            chr($this->keyAlgorithm) .
            chr($this->hashAlgorithm) .
            pack('n', strlen($hashedData)) . 
            $hashedData;

        $trailer2 = 
            chr(4).
            chr(0xff).
            pack('N', strlen($trailer));

        return $data . $trailer . $trailer2;
    }

    function fillWithDataFromSubpacket(){
        foreach($this->hashedPackets as $packet){
            if($packet instanceof ConiksDbIdSubpacket){
                $this->id = $packet->data;
                $this->timestamp = $packet->timestamp;
            }
        }
    }
}