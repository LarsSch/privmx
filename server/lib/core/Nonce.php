<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

use io\privfs\config\Config;
use BI\BigInteger;

class Nonce {

    private $config;
    private $dbManager;
    private $logger;
    
    public function __construct(Config $config, DbManager $dbManager) {
        $this->config = $config;
        $this->dbManager = $dbManager;
        $this->logger = \io\privfs\log\LoggerFactory::get($this);
    }
    
    public function validateTimestamp($timestamp) {
        return $this->validateTimestampBi($timestamp["bi"]);
    }
    
    public function validateTimestampStr($timestamp) {
        return $this->validateTimestampBi(BigInteger::createSafe($timestamp));
    }
    
    public function validateTimestampBi($timestampBi) {
        $time = Utils::timeMili();
        return $timestampBi !== false &&
            $timestampBi->cmp($time->sub($this->config->getMaxTimestampDifference())) >= 0 &&
            $timestampBi->cmp($time->add($this->config->getMaxTimestampDifference())) <= 0;
    }
    
    public function simpleNonceCheck($nonce, $timestamp) {
        $this->simpleNonceCheckBi($nonce, BigInteger::createSafe($timestamp));
    }
    
    public function simpleNonceCheckBi($nonce, $timestampBi) {
        if (!$this->validateTimestampBi($timestampBi)) {
            throw new JsonRpcException("INVALID_TIMESTAMP");
        }
        if (!is_string($nonce) || strlen($nonce) < 32 || strlen($nonce) > 64 || !$this->nonceIsUnique($nonce, $timestampBi)) {
            throw new JsonRpcException("INVALID_NONCE");
        }
    }
    
    public function nonceCheck($data, $key, $nonce, $timestamp, $signature) {
        $this->simpleNonceCheckBi($nonce, $timestamp["bi"]);
        $data = hash("sha256", $data . " " . $nonce . " " . $timestamp["dec"], true);
        if (!ECUtils::verifySignature($key["ecc"], $signature["bin"], $data)) {
            throw new JsonRpcException("INVALID_SIGNATURE");
        }
    }
    
    public function nonceIsUnique($nonce, $timestamp) {
        $db = $this->dbManager->getDbForWrite("nonce");
        $value = true;
        if ($db->exists($nonce)) {
            $value = false;
        }
        else {
            $db->insert($nonce, Utils::biTo64bit($timestamp));
        }
        $this->dbManager->closeDbByName("nonce");
        return $value;
    }
    
    public function cleanNonceDb() {
        $db = $this->dbManager->getDbForWrite("nonce");
        $key = $db->firstkey();
        $diff = new BigInteger($this->config->getMaxTimestampDifference());
        while ($key !== false) {
            $value = $db->fetch($key);
            $timestamp = Utils::biFrom64bit($value);
            $elapsedTime = Utils::timeMili()->sub($timestamp);
            if ($elapsedTime->cmp($diff) > 0) {
                $this->logger->debug(
                    "Nonce " . $key . " removed " . $elapsedTime->toDec() . "/" . $diff->toDec()
                );
                $db->delete($key);
            }
            $key = $db->nextkey();
        }
        $this->dbManager->closeDbByName("nonce");
    }
}
