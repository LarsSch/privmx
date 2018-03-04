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
use io\privfs\core\Nonce;
use io\privfs\core\Utils;
use io\privfs\core\JsonRpcException;
use io\privfs\jsonrpc\Raw;
use privmx\pki\KVStore;
use PSON\ByteBuffer;

class Block extends Base {
    
    const TRANSFER_SESSION_TTL = 600; //10 minutes
    private $config;
    private $nonce;
    private $privFsUser;
    private $transferSessions;
    public $descriptor;
    public $message;
    
    public function __construct(Config $config, DbManager $dbManager, Nonce $nonce, PrivFsUser $privFsUser,
        KVStore $transferSessions) {
        
        parent::__construct($dbManager);
        $this->config = $config;
        $this->nonce = $nonce;
        $this->privFsUser = $privFsUser;
        $this->transferSessions = $transferSessions;
    }
    
    public function verifyInitBlockTransferSignature($data, $signature) {
        $this->nonce->nonceCheck($data . $signature["hashmail"], $signature["pub"],
            $signature["nonce"], $signature["timestamp"], $signature["signature"]);
    }
    
    public function verifyInitBlockTransferHashmail($signature) {
        return $this->privFsUser->validateHashmail($signature["hashmail"], $signature["pub"]["base58"]);
    }
    
    static public function generateTransferSessionId() {
        $tod = gettimeofday();
        $tstamp = $tod["sec"] * 1000 + intval($tod["usec"] / 1000);
        $tmp = $tstamp >> 31;
        return pack('n', $tmp >> 1) . pack('N', $tstamp & 0xffffffff) . openssl_random_pseudo_bytes(10);
    }
    
    public function createTransferSession($data) {
        $sessionId = Block::generateTransferSessionId();
        $this->transferSessions->save($sessionId, json_encode($data), Block::TRANSFER_SESSION_TTL);
        return bin2hex($sessionId);
    }
    
    public function validateTransferBlocks($transferId, $blocks, $fields) {
        return $this->transferSessions->withContext(function($store) use ($transferId, $blocks, $fields) {
            if (!$store->contains($transferId["bin"])) {
                throw new JsonRpcException("INVALID_TRANSFER_SESSION");
            }
            $session = json_decode($store->fetch($transferId["bin"]), true);
            foreach ($fields as $key => $field) {
                if (!isset($session[$key]) || $session[$key] != $field) {
                    throw new JsonRpcException("ACCESS_DENIED", $key);
                }
            }
            foreach ($blocks as $bid) {
                if (!in_array($bid["base58"], $session["blocks"])) {
                    throw new JsonRpcException("ACCESS_DENIED", $bid["base58"]);
                }
            }
            $store->delete($transferId["bin"]);
            return isset($session["extra"]) ? $session["extra"] : null;
        }, "w");
    }
    
    public function blockCreate($transferIds, $bid, $data) {
        $prefix = $this->getPrefix($bid);
        $myHash = hash("sha256", $data, true);
        if ($bid["bin"] != $myHash) {
            throw new JsonRpcException("INVALID_BID");
        }
        $db = $this->getBlockDbForWrite($prefix);
        if (!$db->exists($bid["base58"])) {
            $db->insert($bid["base58"], Utils::biTo64bit(Utils::timeMili()) . $data);
        }
        $this->closeBlockDb($db);
        $this->transferSessions->withContext(function($store) use ($transferIds, $bid) {
            foreach ($transferIds as $id) {
                if (!$store->contains($id["bin"])) {
                    throw new JsonRpcException("INVALID_TRANSFER_SESSION");
                }
            }
            if ($this->config->hasBlockCountLimit()) {
                foreach ($transferIds as $id) {
                    $session = json_decode($store->fetch($id["bin"]), true);
                    if (count($session["blocks"]) + 1 > $this->config->getMaxBlocksCount()) {
                        throw new JsonRpcException("MAX_COUNT_OF_BLOCKS_EXCEEDED");
                    }
                }
            }
            foreach ($transferIds as $id) {
                $session = json_decode($store->fetch($id["bin"]), true);
                array_push($session["blocks"], $bid["base58"]);
                $store->save($id["bin"], json_encode($session), Block::TRANSFER_SESSION_TTL);
            }
        }, "w");
        return "OK";
    }
    
    public function blockAddToSession($username, $transferIds, $source, $blocks) {
        if ($source["type"] == "descriptor") {
            $d = $this->descriptor->descriptorGetSingle($source["did"], false);
            $sBlocks = $d["blocks"];
            foreach ($blocks as $bid) {
                if (!in_array($bid["base58"], $sBlocks)) {
                    throw new JsonRpcException("INVALID_BLOCK_SOURCE");
                }
            }
        }
        else if ($source["type"] == "message") {
            if (empty($username)) {
                throw new JsonRpcException("ACCESS_DENIED");
            }
            $m = $this->message->messageGetSingle($username, $source["sid"], $source["mid"]);
            $sBlocks = $m["blocks"];
            foreach ($blocks as $bid) {
                if (!in_array($bid["base58"], $sBlocks)) {
                    throw new JsonRpcException("INVALID_BLOCK_SOURCE");
                }
            }
        }
        else {
            throw new JsonRpcException("INVALID_BLOCK_SOURCE");
        }
        $this->transferSessions->withContext(function($store) use ($transferIds, $blocks) {
            foreach ($transferIds as $id) {
                if (!$store->contains($id["bin"])) {
                    throw new JsonRpcException("INVALID_TRANSFER_SESSION");
                }
            }
            if ($this->config->hasBlockCountLimit()) {
                $count = count($blocks);
                foreach ($transferIds as $id) {
                    $session = json_decode($store->fetch($id["bin"]), true);
                    if (count($session["blocks"]) + $count > $this->config->getMaxBlocksCount()) {
                        throw new JsonRpcException("MAX_COUNT_OF_BLOCKS_EXCEEDED");
                    }
                }
            }
            foreach ($transferIds as $id) {
                $session = json_decode($store->fetch($id["bin"]), true);
                foreach ($blocks as $bid) {
                    array_push($session["blocks"], $bid["base58"]);
                }
                $store->save($id["bin"], json_encode($session), Block::TRANSFER_SESSION_TTL);
            }
            return true;
        }, "w");
        return "OK";
    }
    
    public function blockGet($username, $bid, $source) {
        if ($source["type"] == "descriptor") {
            $d = $this->descriptor->descriptorGetSingle($source["did"], false);
            $blocks = $d["blocks"];
            if (!in_array($bid["base58"], $blocks)) {
                throw new JsonRpcException("INVALID_BLOCK_SOURCE");
            }
        }
        else if ($source["type"] == "message") {
            if (empty($username)) {
                throw new JsonRpcException("ACCESS_DENIED");
            }
            $m = $this->message->messageGetSingle($username, $source["sid"], $source["mid"]);
            $blocks = $m["blocks"];
            if (!in_array($bid["base58"], $blocks)) {
                throw new JsonRpcException("INVALID_BLOCK_SOURCE");
            }
        }
        else {
            throw new JsonRpcException("INVALID_BLOCK_SOURCE");
        }
        return $this->blockGetByBid($bid);
    }
    
    public function blockGetByBid($bid) {
        $prefix = $this->getPrefix($bid);
        $db = $this->getBlockDbForRead($prefix);
        if (!$db->exists($bid["base58"])) {
            $this->closeBlockDb($db);
            throw new JsonRpcException("BLOCK_DOESNT_EXIST");
        }
        $value = ByteBuffer::wrap($db->fetch($bid["base58"]));
        $this->closeBlockDb($db);
        return new Raw($value->toBinary(8), true);
    }
    
    public function blockExists($bid) {
        $prefix = $this->getPrefix($bid);
        $db = $this->getBlockDbForRead($prefix);
        $exists = $db->exists($bid["base58"]);
        $this->closeBlockDb($db);
        return $exists;
    }
    
    public static function getPrefix($bid) {
        return substr($bid["base58"], 0, 2);
    }
}
