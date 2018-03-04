<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\messages;

use privmx\pki\keystore\Signature;

class TreeSignaturesMessage extends MessageBase
{
    private $domain = null;
    private $hash = null;
    private $signatures = null;

    public $warnings = null;

    /**
     * @param string domain, required
     * @param bytes hash, required
     * @param map<string, Signature> signatures, optional
     */
    public function __construct($domain = null, $hash = null, $signatures = null)
    {
        if( MessageBase::isEmptyConstructor(array($domain, $hash, $signatures)) )
            return;

        $this->setDomain($domain)->setHash($hash);
        if( $signatures !== null )
            $this->setSignatures($signatures);
    }

    public function setDomain($domain)
    {
        if( !is_string($domain) )
            $this->incorrectFieldType("domain");

        $this->domain = $domain;
        return $this;
    }

    public function getDomain()
    {
        return $this->domain;
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

    public function setSignatures($signatures)
    {
        $signatures = (array)$signatures;
        foreach($signatures as $domain => $signature)
            $this->addSignature($domain, $signature);

        return $this;
    }

    public function getSignatures()
    {
        return $this->signatures;
    }

    public function addSignature($domain, $signature)
    {
        if( !is_string($domain) )
            $this->incorrectFieldType("signatures (Domain)");
        
        $sigStr = MessageBase::fromByteBuffer($signature);
        $s = is_string($sigStr) ? Signature::decode($sigStr)[0] : null;
        $info = "";
        
        if (is_null($s)) {
            if( is_object($signature) && $signature instanceof \stdClass ) {
                $signature = (array)$signature;
            }
            if( !is_array($signature) ) {
                $this->incorrectFieldType("signature array " . gettype($signature) . " " . get_class($signature));
            }
            if( !is_string($signature["info"]) ) {
                $this->incorrectFieldType("signature[info]");
            }
            $info = $signature["info"];
            if ($signature["signature"]) {
                if( !is_string($signature["signature"]) ) {
                    $this->incorrectFieldType("signature[signature type]");
                }
                
                $s = Signature::decode($signature["signature"])[0];
                
                if( !($s instanceof Signature) ) {
                    $this->incorrectFieldType("signature[signature]");
                }
            }
        }
        else {
            if( !($s instanceof Signature) ) {
                $this->incorrectFieldType("signature");
            }
        }
        
        if( $this->signatures === null )
            $this->signatures = array();
        
        $res = array(
            "signature" => $s,
            "info" => $info
        );
        $this->signatures[$domain] = $res;
        return $res;
    }

    public function validate()
    {
        if( $this->getDomain() === null )
            $this->missingField("domain");

        if( $this->getHash() === null )
            $this->missingField("hash");
    }

    public function psonSerialize()
    {
        $this->validate();
        $result = array(
            "domain" => $this->getDomain(),
            "hash" => MessageBase::toByteBuffer(
                $this->getHash()
            )
        );

        $tmp = $this->getSignatures();
        if( $tmp !== null && count($tmp) > 0 )
        {
            $result["signatures"] = array();
            foreach($tmp as $domain => $signature)
            {
                $result["signatures"][$domain] = array(
                    "signature" => $signature["signature"] ? $signature["signature"]->encode("base64") : null,
                    "info" => $signature["info"]
                );
            }
        }

        return $result;
    }

    public function psonUnserialize($pson)
    {
        $pson = (array)$pson;
        if( isset($pson["domain"]) )
            $this->setDomain($pson["domain"]);

        if( isset($pson["hash"]) )
            $this->setHash($pson["hash"]);

        if( isset($pson["signatures"]) )
            $this->setSignatures($pson["signatures"]);

        $this->validate();
    }

    public function verify($cosigners, $domain = null, $hash = null)
    {
        if( ($domain !== null && $domain !== $this->getDomain()) ||
            ($hash !== null && $hash !== $this->getHash()) )
        {
            return false;
        }

        $count = count($cosigners);
        $confirmed = 0;
        $this->warnings = array();
        $has_invalid = false;

        if( $count === 0 )
            return true;

        $signatures = $this->getSignatures();
        if( $signatures === null )
            return false;

        $data = array("msg" => $this->hash);
        foreach($cosigners as $domain => $keystore)
        {
            if( !isset($signatures[$domain]) || !$signatures[$domain]["signature"])
            {
                array_push($this->warnings, array(
                    "type" => "missing",
                    "domain" => $domain,
                    "message" => $this,
                    "cosigners" => $cosigners
                ));
                continue;
            }

            $signature = $signatures[$domain]["signature"];
            $key = $keystore->getKeyById($signature->getIssuerId());
            if( $key === null || !$signature->verify($data, $key) )
            {
                array_push($this->warnings, array(
                    "type" => "invalid",
                    "domain" => $domain,
                    "message" => $this,
                    "cosigners" => $cosigners
                ));
                $has_invalid = true;
                continue;
            }

            $confirmed += $domain === $this->getDomain() ? 0.6 : 1;
        }

        if( $has_invalid )
            return false;

        if( $confirmed * 2 > $count )
            return true;

        array_push($this->warnings, array(
            "type" => "no_quorum",
            "message" => $this,
            "cosigners" => $cosigners
        ));

        return false;
    }
}
