<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\data;

use io\privfs\config\Config;
use io\privfs\core\DbManager;
use io\privfs\core\ECUtils;
use io\privfs\core\JsonRpcException;
use io\privfs\core\Utils;
use io\privfs\core\Base58;
use PSON\ByteBuffer;
use BI\BigInteger;

class Descriptor extends Base {

    private $config;
    private $block;
    private $accessService;
    
    public function __construct(Config $config, DbManager $dbManager, Block $block, AccessService $accessService) {
        parent::__construct($dbManager);
        $this->config = $config;
        $this->block = $block;
        $this->accessService = $accessService;
    }
    
    private function checkLock($descriptor, $lockId, $time = null, $lockerPub58 = null) {
        $currentTime = is_null($time) ? Utils::timeMili() : $time;
        if (isset($descriptor["lock"])) {
            $lockExpiry = new BigInteger($descriptor["lock"]["expiry"]);
            if ($currentTime->cmp($lockExpiry->add($this->config->getLockTimeout())) <= 0 &&
            $descriptor["lock"]["id"] != $lockId) {
                if (is_null($lockerPub58) || $descriptor["lock"]["lockerPub58"] != $lockerPub58["base58"]) {
                    throw new JsonRpcException("DESCRIPTOR_LOCKED", array(
                        "lockerPub58" => $descriptor["lock"]["lockerPub58"]
                    ));
                }
            }
        }
    }
    
    private function readDescriptorData($did, $data) {
        if (count($data["blocks"]) == 0 && strlen($data["extra"]["bin"]) == 0) {
            throw new JsonRpcException("INVALID_JSON_PARAMETERS");
        }
        if ($did != ECUtils::toBase58Address($data["dpub58"]["ecc"], "00")) {
            throw new JsonRpcException("DPUB58_DOESNT_MATCH_TO_DID");
        }
        $blocks = array();
        $versionData = "";
        foreach ($data["blocks"] as $block) {
            if (!$this->block->blockExists($block)) {
                throw new JsonRpcException("BLOCK_DOESNT_EXIST");
            }
            $versionData .= $block["base58"];
            array_push($blocks, $block["base58"]);
        }
        $versionData .= base64_encode(hash("sha256", $data["extra"]["bin"], true));
        $version = hash("sha256", $versionData, true);
        if (!ECUtils::verifySignature($data["dpub58"]["ecc"], $data["signature"]["bin"], $version)) {
            throw new JsonRpcException("INVALID_SIGNATURE");
        }
        
        $serverDate = Utils::timeMili()->toDec();
        return array("serverDate" => $serverDate, "data" => array(
            "blocks" => $blocks,
            "extra" => $data["extra"]["base64"],
            "signature" => $data["signature"]["base64"],
            "dpub58" => $data["dpub58"]["base58"],
            "serverDate" => $serverDate
        ));
    }
    
    public function descriptorCreateInit($username) {
        if ($this->accessService->canCreateDescriptor($username) === false) {
            throw new JsonRpcException("ACCESS_DENIED");
        }
        $trasferSessionId = $this->block->createTransferSession(array(
            "type" => "descriptorCreate",
            "blocks" => array(),
            "username" => $username
        ));
        return $trasferSessionId;
    }
    
    public function descriptorCreate($username, $did, $data, $transferId) {
        if ($this->accessService->canCreateDescriptor($username) === false) {
            throw new JsonRpcException("ACCESS_DENIED");
        }
        $this->block->validateTransferBlocks($transferId, $data["blocks"], array(
            "type" => "descriptorCreate",
            "username" => $username
        ));
        $result = $this->readDescriptorData($did, $data);
        $result["data"]["owner"] = $username;
        $db = $this->getDescriptorDbForWrite();
        if ($db->exists($did)) {
            $this->closeDescriptorDb();
            throw new JsonRpcException("DESCRIPTOR_ALREADY_EXISTS");
        }
        $db->insert($did, json_encode($result["data"]));
        $this->closeDescriptorDb();
        return array("serverDate" => $result["serverDate"]);
    }
    
    public function descriptorUpdateInit($did, $signature) {
        $this->block->verifyInitBlockTransferSignature("descriptorUpdate" . $did, $signature);
        $this->block->verifyInitBlockTransferHashmail($signature);
        $this->descriptorCheckExistance($did);
        $trasferSessionId = $this->block->createTransferSession(array(
            "type" => "descriptorUpdate",
            "blocks" => array(),
            "did" => $did,
            "hashmail" => $signature["hashmail"]
        ));
        return $trasferSessionId;
    }
    
    public function descriptorUpdate($did, $data, $transferId, $signature, $lockId, $releaseLock) {
        $this->block->validateTransferBlocks($transferId, $data["blocks"], array(
            "type" => "descriptorUpdate",
            "did" => $did
        ));
        $result = $this->readDescriptorData($did, $data);
        $db = $this->getDescriptorDbForWrite();
        if (!$db->exists($did)) {
            $this->closeDescriptorDb();
            throw new JsonRpcException("DESCRIPTOR_DOESNT_EXIST");
        }
        $oldJson = json_decode($db->fetch($did), true);
        if ($oldJson["dpub58"] != $data["dpub58"]["base58"]) {
            $this->closeDescriptorDb();
            throw new JsonRpcException("PUBLIC_KEY_CANNOT_BE_CHANGED");
        }
        if ($signature["base64"] != $oldJson["signature"]) {
            $this->closeDescriptorDb();
            throw new JsonRpcException("OLD_SIGNATURE_DOESNT_MATCH");
        }
        $this->checkLock($oldJson, $lockId);
        if (!$releaseLock && isset($oldJson["lock"])) {
            $result["data"]["lock"] = $oldJson["lock"];
        }
        $result["data"]["owner"] = $oldJson["owner"];
        $db->replace($did, json_encode($result["data"]));
        $this->closeDescriptorDb();
        return array("serverDate" => $result["serverDate"]);
    }
    
    public function descriptorDelete($did, $signature, $lockId) {
        $db = $this->getDescriptorDbForWrite();
        if (!$db->exists($did)) {
            $this->closeDescriptorDb();
            throw new JsonRpcException("DESCRIPTOR_DOESNT_EXIST");
        }
        $descriptor = json_decode($db->fetch($did), true);
        $data = hash("sha256", "delete" . $did, true);
        $dpub = ECUtils::publicFromBase58DER($descriptor["dpub58"]);
        if (!ECUtils::verifySignature($dpub, $signature["bin"], $data)) {
            $this->closeDescriptorDb();
            throw new JsonRpcException("INVALID_SIGNATURE");
        }
        $this->checkLock($descriptor, $lockId);
        $db->delete($did);
        $this->closeDescriptorDb();
        return "OK";
    }
    
    public function descriptorLock($did, $lockId, $signature, $lockerPub58, $lockerSignature, $force) {
        $db = $this->getDescriptorDbForWrite();
        if (!$db->exists($did)) {
            $this->closeDescriptorDb();
            throw new JsonRpcException("DESCRIPTOR_DOESNT_EXIST");
        }
        $descriptor = json_decode($db->fetch($did), true);
        $data = hash("sha256", "lock" . $did . $lockId . $lockerPub58["base58"], true);
        $dpub = ECUtils::publicFromBase58DER($descriptor["dpub58"]);
        if (!ECUtils::verifySignature($dpub, $signature["bin"], $data) || !ECUtils::verifySignature($lockerPub58["ecc"], $lockerSignature["bin"], $data)) {
            $this->closeDescriptorDb();
            throw new JsonRpcException("INVALID_SIGNATURE");
        }
        $currentTime = Utils::timeMili();
        $this->checkLock($descriptor, $lockId, $currentTime, $force ? $lockerPub58 : null);
        
        $descriptor["lock"] = array(
            "id" => $lockId,
            "signature" => $signature["base64"],
            "expiry" => $currentTime->toDec(),
            "lockerPub58" => $lockerPub58["base58"],
            "lockerSignature" => $lockerSignature["base64"]
        );
        
        $db->replace($did, json_encode($descriptor));
        $this->closeDescriptorDb();
        
        return $descriptor["lock"]["expiry"];
    }
    
    public function descriptorRelease($did, $lockId, $signature) {
        $db = $this->getDescriptorDbForWrite();
        if (!$db->exists($did)) {
            $this->closeDescriptorDb();
            throw new JsonRpcException("DESCRIPTOR_DOESNT_EXIST");
        }
        $descriptor = json_decode($db->fetch($did), true);
        $data = hash("sha256", "release" . $did . $lockId, true);
        $dpub = ECUtils::publicFromBase58DER($descriptor["dpub58"]);
        if (!ECUtils::verifySignature($dpub, $signature["bin"], $data)) {
            $this->closeDescriptorDb();
            throw new JsonRpcException("INVALID_SIGNATURE");
        }
        $this->checkLock($descriptor, $lockId);
        unset($descriptor["lock"]);
        $db->replace($did, json_encode($descriptor));
        $this->closeDescriptorDb();
        
        return "OK";
    }
    
    public function descriptorGet($dids, $includeBlocks = 0) {
        $this->getDescriptorDbForRead();
        $map = array();
        foreach ($dids as $did) {
            try {
                $result = $this->descriptorGetSingle($did, $includeBlocks);
            }
            catch (JsonRpcException $e) {
                $this->closeDescriptorDb();
                $e->setData($did);
                throw $e;
            }
            $map[$did] = $result;
        }
        $this->closeDescriptorDb();
        return $map;
    }
    
    public function descriptorGetSingle($did, $includeBlocks = 0) {
        $db = $this->getDescriptorDbForRead();
        if (!$db->exists($did)) {
            $this->closeDescriptorDb();
            throw new JsonRpcException("DESCRIPTOR_DOESNT_EXIST");
        }
        $descriptor = json_decode($db->fetch($did), true);
        if (isset($descriptor["lock"])) {
            unset($descriptor["lock"]);
        }
        $this->closeDescriptorDb();
        if ($includeBlocks && !empty($descriptor['blocks'])) {
            $blocks = array();
            $bids = array_slice($descriptor['blocks'], 0, $includeBlocks);
            foreach ($bids as $bid) {
                $block = $this->block->blockGetByBid(array(
                    "base58" => $bid,
                    "bin" => Base58::decode($bid)
                ));
                $data = ByteBuffer::wrap($block->getData());
                $blocks[$bid] = $data;
            }
            $descriptor['blocksData'] = $blocks;
        }
        return $descriptor;
    }
    
    public function descriptorCheck($dids) {
        $this->getDescriptorDbForRead();
        $map = array();
        foreach ($dids as $entry) {
            try {
                $result = $this->descriptorCheckSingle($entry["did"], $entry["signature"]);
            }
            catch (JsonRpcException $e) {
                $this->closeDescriptorDb();
                $e->setData(array("did" => $entry["did"], "signature" => $entry["signature"]["base64"]));
                throw $e;
            }
            $map[$entry["did"]] = $result === 0 ? "NotModified" : $result;
        }
        $this->closeDescriptorDb();
        return $map;
    }
    
    public function descriptorCheckSingle($did, $signature) {
        $db = $this->getDescriptorDbForRead();
        if (!$db->exists($did)) {
            $this->closeDescriptorDb();
            throw new JsonRpcException("DESCRIPTOR_DOESNT_EXIST");
        }
        $descriptor = json_decode($db->fetch($did), true);
        if (isset($descriptor["lock"])) {
            unset($descriptor["lock"]);
        }
        $this->closeDescriptorDb();
        return $descriptor["signature"] == $signature["base64"] ? 0 : $descriptor;
    }
    
    public function descriptorCheckExistance($did) {
        $db = $this->getDescriptorDbForRead();
        $exists = $db->exists($did);
        $this->closeDescriptorDb();
        if (!$exists) {
            throw new JsonRpcException("DESCRIPTOR_DOESNT_EXIST");
        }
    }
}
