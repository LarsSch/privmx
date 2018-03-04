<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\data;

use io\privfs\config\Config;
use io\privfs\core\Comparator;
use io\privfs\core\DbManager;
use io\privfs\core\Lock;
use io\privfs\core\Nonce;
use io\privfs\core\JsonRpcException;
use io\privfs\core\Utils;
use io\privfs\core\Callbacks;
use io\privfs\core\Validator;
use BI\BigInteger;

class Sink extends Base {

    const SEQ_KEY = "seq";
    const MOD_SEQ_KEY = "modSeq";
    const MIDS_KEY = "mids";
    const LAST_SEEN_SEQ_KEY = "lastSeenSeq";
    const LAST_NOTIFIER_SEQ_KEY = "lastNotifierSeq";
    
    private $accessService;
    private $nonce;
    private $config;
    private $lock;
    private $callbacks;
    
    public function __construct(DbManager $dbManager, AccessService $accessService, Nonce $nonce,
        Config $config, Lock $lock, Callbacks $callbacks) {
        parent::__construct($dbManager);
        $this->accessService = $accessService;
        $this->nonce = $nonce;
        $this->config = $config;
        $this->lock = $lock;
        $this->callbacks = $callbacks;
    }
    
    public function sinkExists($sid) {
        $db = $this->getSinkDbForRead();
        $exists = $db->exists($sid);
        $this->closeSinkDb();
        return $exists;
    }
    
    public function sinkGet($sid) {
        $db = $this->getSinkDbForRead();
        $sink = null;
        if ($db->exists($sid)) {
            $sink = json_decode($db->fetch($sid), true);
        }
        $this->closeSinkDb();
        return $sink;
    }
    
    public static function getMessageDbId($mid) {
        return "msg/" . $mid;
    }
    
    public static function getMessageMetaDbId($mid) {
        return "meta/" . $mid;
    }
    
    public static function getMessageDateDbId($mid) {
        return "date/" . $mid;
    }
    
    public static function getModDbId($modSeq) {
        return "mod/" . $modSeq;
    }
    
    public static function getTagDbId($tag) {
        return "tag/" . $tag;
    }
    
    public static function getMids($mids) {
        return $mids == "" ? array() : array_map("intval", explode(",", $mids));
    }
    
    public function validateAccessToSink($username, $sid, $readOnly = false) {
        if ($this->accessService->canModifyMessage($username) === false) {
            throw new JsonRpcException("ACCESS_DENIED");
        }
        $sink = $this->sinkGet($sid["base58"]);
        if (is_null($sink)) {
            throw new JsonRpcException("SINK_DOESNT_EXISTS");
        }
        if ($sink["acl"] == "shared") {
            if (!$readOnly) {
                throw new JsonRpcException("ACCESS_DENIED");
            }
        }
        else {
            if ($sink["owner"] != $username) {
                if ($readOnly || $readOnly == "modify-msg") {
                    $lowUsers = $this->getLowUsers($sink);
                    if (in_array($username, $lowUsers)) {
                        return;
                    }
                }
                throw new JsonRpcException("ACCESS_DENIED");
            }
        }
    }
    
    //====================================================
    
    public function sinkGetAllMy($username) {
        $db = $this->getSinkDbForRead();
        $sinks = array();
        $key = $db->firstkey();
        while ($key !== false) {
            $json = json_decode($db->fetch($key), true);
            if ($json["owner"] == $username && ($json["acl"] == "public" || $json["acl"] == "private" || $json["acl"] == "anonymous")) {
                array_push($sinks, array(
                    "id" => $key,
                    "acl" => $json["acl"],
                    "data" => $json["data"],
                    "options" => isset($json["options"]) ? $json["options"] : array()
                ));
            }
            $key = $db->nextkey();
        }
        $this->closeSinkDb();
        return $sinks;
    }
    
    public function sinkCreate($username, $sid, $acl, $data, $options) {
        if ($this->accessService->canCreateSink($username) === false) {
            throw new JsonRpcException("INVALID_OWNER");
        }
        $db = $this->getSinkDbForWrite();
        if ($db->exists($sid["base58"])) {
            $this->closeSinkDb();
            throw new JsonRpcException("SINK_ALREADY_EXISTS");
        }
        $db->insert($sid["base58"], json_encode(array(
            "owner" => $username,
            "acl" => $acl,
            "data" => $data,
            "options" => Validator::clean($options)
        )));
        $this->closeSinkDb();
        
        $db = $this->getMessageDbForWrite($sid["base58"]);
        $db->insert(static::SEQ_KEY, "0");
        $db->insert(static::MOD_SEQ_KEY, "0");
        $this->closeMessageDb($db);
        $this->callbacks->trigger("sinkCreate", array($username, $sid["base58"], $acl));
        
        return "OK";
    }
    
    public function sinkSave($username, $sid, $acl, $data, $options) {
        $db = $this->getSinkDbForWrite();
        $sink = $this->sinkGet($sid["base58"]);
        if (is_null($sink)) {
            $this->closeSinkDb();
            throw new JsonRpcException("SINK_DOESNT_EXISTS");
        }
        if ($sink["owner"] != $username) {
            $this->closeSinkDb();
            throw new JsonRpcException("ACCESS_DENIED");
        }
        $db->replace($sid["base58"], json_encode(array(
            "owner" => $username,
            "acl" => $acl,
            "data" => $data,
            "options" => Validator::clean($options)
        )));
        $this->closeSinkDb();
        
        return "OK";
    }
    
    public function getSinkOption($sink, $name, $default) {
        return isset($sink["options"]) && isset($sink["options"][$name]) ? $sink["options"][$name] : $default;
    }
    
    public function isSinkRemovable($sink) {
        return $this->getSinkOption($sink, "removable", true);
    }
    
    public function sinkNeedEmailVerification($sink) {
        return $this->getSinkOption($sink, "verify", null) == "email";
    }
    
    public function getSinkProxyTo($sink) {
        return $this->getSinkOption($sink, "proxyTo", array("list" => array()));
    }
    
    public function getSinkProxyFrom($sink) {
        return $this->getSinkOption($sink, "proxyFrom", array("list" => array()));
    }
    
    public function getLowUsers($sink) {
        return $this->getSinkOption($sink, "lowUsers", array());
    }
    
    public function sinkDelete($username, $sid) {
        $db = $this->getSinkDbForWrite();
        $sink = $this->sinkGet($sid["base58"]);
        if (is_null($sink)) {
            $this->closeSinkDb();
            throw new JsonRpcException("SINK_DOESNT_EXISTS");
        }
        if ($sink["owner"] != $username || !$this->isSinkRemovable($sink)) {
            $this->closeSinkDb();
            throw new JsonRpcException("ACCESS_DENIED");
        }
        $db->delete($sid["base58"]);
        $this->removeMessageDb($sid["base58"]);
        $this->closeSinkDb();
        return "OK";
    }
    
    public function sinkSetLastSeenSeq($username, $sid, $lastSeenSeq) {
        $this->validateAccessToSink($username, $sid);
        $db = $this->getMessageDbForWrite($sid["base58"]);
        $db->update(Sink::LAST_SEEN_SEQ_KEY, $lastSeenSeq);
        $this->closeMessageDb($db);
        return "OK";
    }
    
    public function sinkPoll($username, $sinks, $updateLastSeen) {
        $this->getSinkDbForRead();
        $lastModifyTime = array();
        foreach ($sinks as $sink) {
            $sid = $sink["sid"]["base58"];
            $dbSink = $this->sinkGet($sid);
            if (is_null($dbSink)) {
                $this->closeSinkDb();
                throw new JsonRpcException("SINK_DOESNT_EXISTS", $sid);
            }
            if ($dbSink["acl"] != "shared" && $dbSink["owner"] != $username) {
                $this->closeSinkDb();
                throw new JsonRpcException("ACCESS_DENIED", $sid);
            }
            $lastModifyTime[$sid] = 0;
        }
        $this->closeSinkDb();
        $this->lock->release();
        $initTime = Utils::timeMili();
        $result = array("delay" => (new BigInteger($this->config->getLongPollingDelay()))->toDec(), "sinks" => array());
        do {
            $locked = false;
            foreach ($sinks as $sink) {
                $sid = $sink["sid"]["base58"];
                $path = $this->dbManager->getDbFilePath($sid);
                clearstatcache(false, $path);
                $mTime = filemtime($path);
                if ($lastModifyTime[$sid] != $mTime) {
                    $lastModifyTime[$sid] = $mTime;
                    if (!$locked) {
                        $locked = true;
                        $this->lock->reader();
                    }
                    $sres = $this->sinkPollSingle($sid, $sink["seq"], $sink["modSeq"], $updateLastSeen);
                    if ($sres !== false) {
                        $result["sinks"][$sid] = $sres;
                    }
                }
            }
            if (count($result["sinks"]) > 0) {
                return $result;
            }
            if ($locked) {
                $this->lock->release();
            }
            sleep($this->config->getLongPollingInterval());
            $elapsed = Utils::timeMili()->sub($initTime);
        } while($elapsed->cmp($this->config->getLongPollingTimeout()) < 0);
        $this->lock->reader();
        return $result;
    }
    
    private function sinkPollSingle($sid, $seq, $modSeq, $updateLastSeen) {
        $db = $this->getMessageDbForRead($sid);
        $dbSeq = intval($db->fetch(Sink::SEQ_KEY));
        $dbModSeq = intval($db->fetch(Sink::MOD_SEQ_KEY));
        if ($seq >= $dbSeq && $modSeq >= $dbModSeq) {
            $this->closeMessageDb($db);
            return false;
        }
        $result = array("msg" => array(), "meta" => array(), "seq" => $dbSeq, "modSeq" => $dbModSeq);
        for ($i = $seq + 1; $i <= $dbSeq; $i++) {
            $msgId = Sink::getMessageDbId($i);
            if ($db->exists($msgId)) {
                array_push($result["msg"], json_decode($db->fetch($msgId), true));
            }
        }
        $mids = array();
        for ($i = $modSeq + 1; $i <= $dbModSeq; $i++) {
            $modId = Sink::getModDbId($i);
            if ($db->exists($modId)) {
                $mid = $db->fetch($modId);
                $mids[$mid] = true;
            }
        }
        foreach ($mids as $mid => $v) {
            $msgMetaId = Sink::getMessageMetaDbId($mid);
            if ($db->exists($msgMetaId)) {
                $meta = json_decode($db->fetch($msgMetaId), true);
                array_push($result["meta"], $meta);
            }
        }
        $this->closeMessageDb($db);
        if ($updateLastSeen) {
            $db = $this->getMessageDbForWrite($sid);
            $db->update(Sink::LAST_SEEN_SEQ_KEY, $dbSeq);
            $this->closeMessageDb($db);
        }
        return $result;
    }
    
    public function sinkClear($username, $sid, $currentModSeq) {
        $this->validateAccessToSink($username, $sid);
        
        $db = $this->getMessageDbForWrite($sid["base58"]);
        $seq = intval($db->fetch(Sink::SEQ_KEY));
        $modSeq = intval($db->fetch(Sink::MOD_SEQ_KEY));
        
        if (!is_null($currentModSeq) && $modSeq != $currentModSeq) {
            $this->closeMessageDb($db);
            throw new JsonRpcException("INVALID_MOD_SEQ");
        }
        
        $result = array("mids" => array());
        for ($mid = 0; $mid <= $seq; $mid++) {
            $msgId = Sink::getMessageDbId($mid);
            if ($db->exists($msgId)) {
                $modSeq++;
                $metaDbId = Sink::getMessageMetaDbId($mid);
                $modDbId = Sink::getModDbId($modSeq);
                
                $meta = json_encode(array(
                    "data" => "",
                    "timestamp" => Utils::timeMili()->toDec(),
                    "modId" => $modSeq,
                    "msgId" => $mid,
                    "deleted" => true
                ));
                
                $db->insert($modDbId, $mid);
                $db->replace($metaDbId, $meta);
                
                array_push($result["mids"], $mid);
            }
        }
        $db->replace(Sink::MOD_SEQ_KEY, $modSeq);
        $result["seq"] = $seq;
        $result["modSeq"] = $modSeq;
        
        $this->closeMessageDb($db);
        return $result;
    }
    
    public function sinkInfo($username, $sid, $addMidList) {
        $this->validateAccessToSink($username, $sid, true);
        $db = $this->getMessageDbForRead($sid["base58"]);
        $seq = intval($db->fetch(Sink::SEQ_KEY));
        $modSeq = intval($db->fetch(Sink::MOD_SEQ_KEY));
        $lastSeenSeq = intval($db->fetch(Sink::LAST_SEEN_SEQ_KEY));
        
        $result = array("seq" => $seq, "modSeq" => $modSeq, "lastSeenSeq" => $lastSeenSeq);
        if ($addMidList) {
            $result["mids"] = Sink::getMids($db->fetch(Sink::MIDS_KEY));
        }
        $this->closeMessageDb($db);
        return $result;
    }
    
    private function getTagsKeys($db, &$cache) {
        if (!isset($cache["tags"])) {
            $result = array();
            $key = $db->firstKey();
            while ($key !== false) {
                if (strpos($key, "tag/") === 0) {
                    array_push($result, substr($key, 4));
                }
                $key = $db->nextKey();
            }
            $cache["tags"] = $result;
        }
        return $cache["tags"];
    }
    
    private function resolveQuery($db, &$cache, $query) {
        if (is_string($query)) {
            return Sink::getMids($db->fetch(Sink::getTagDbId($query)));
        }
        if ($query["operand"] == "NOT") {
            $all = Sink::getMids($db->fetch(Sink::MIDS_KEY));
            $not = $this->resolveQuery($db, $cache, $query["value"]);
            return array_values(array_diff($all, $not));
        }
        if ($query["operand"] == "PREFIX") {
            $tags = $this->getTagsKeys($db, $cache);
            $result = array();
            foreach ($tags as $tag) {
                if (strpos($tag, $query["value"]) === 0) {
                    array_push($result, Sink::getMids($db->fetch(Sink::getTagDbId($tag))));
                }
            }
            return array_values(array_unique(call_user_func_array("array_merge", $result)));
        }
        if ($query["operand"] == "AND") {
            $left = $this->resolveQuery($db, $cache, $query["right"]);
            $right = $this->resolveQuery($db, $cache, $query["left"]);
            return array_values(array_intersect($left, $right));
        }
        if ($query["operand"] == "OR") {
            $left = $this->resolveQuery($db, $cache, $query["right"]);
            $right = $this->resolveQuery($db, $cache, $query["left"]);
            return array_values(array_unique(array_merge($left, $right)));
        }
        if ($query["operand"] == "DATE") {
            $all = Sink::getMids($db->fetch(Sink::MIDS_KEY));
            $result = array();
            $callable = Comparator::getCallableByName($query["relation"]);
            foreach ($all as $mid) {
                if (call_user_func($callable, $db->fetch(Sink::getMessageDateDbId($mid)), $query["value"])) {
                    array_push($result, $mid);
                }
            }
            return $result;
        }
        return null;
    }
    
    public function sinkQuery($username, $sid, $query, $limit, $order) {
        $this->validateAccessToSink($username, $sid, true);
        $db = $this->getMessageDbForRead($sid["base58"]);
        $cache = array();
        $result = $this->resolveQuery($db, $cache, $query);
        if ($order["by"] == "SEQ") {
            if ($order["type"] == "ASC") {
                sort($result, SORT_NUMERIC);
            }
            else {
                rsort($result, SORT_NUMERIC);
            }
        }
        else if ($order["by"] == "DATE") {
            $toSort = array();
            foreach ($result as $mid) {
                $toSort[$mid] = $db->fetch(Sink::getMessageDateDbId($mid));
            }
            if ($order["type"] == "ASC") {
                asort($toSort, SORT_NUMERIC);
            }
            else {
                arsort($toSort, SORT_NUMERIC);
            }
            $result = array_keys($toSort);
        }
        if ($limit >= 0) {
            $result = array_slice($result, 0, $limit);
        }
        $this->closeMessageDb($db);
        return $result;
    }
    
    public function deletedCleanup() {
        $db = $this->getSinkDbForRead();
        $key = $db->firstkey();
        while ($key !== false) {
            $this->deletedCleanupDb($key);
            $key = $db->nextkey();
        }
        $this->closeSinkDb();
    }
    
    public function deletedCleanupDb($sid) {
        $db = $this->getMessageDbForWrite($sid);
        $key = $db->firstkey();
        $mids = array();
        while ($key !== false) {
            if (strpos($key, "msg/") === 0) {
                $mid = substr($key, 4);
                $metaDbId = Sink::getMessageMetaDbId($mid);
                $meta = json_decode($db->fetch($metaDbId), true);
                if ($meta["deleted"]) {
                    $db->delete($key);
                }
                else {
                    array_push($mids, $mid);
                    $msg = json_decode($db->fetch(Sink::getMessageDbId($mid)), true);
                    $db->insert(Sink::getMessageDateDbId($mid), $msg["serverDate"]);
                }
            }
            $key = $db->nextkey();
        }
        $db->update(Sink::MIDS_KEY, implode(",", $mids));
        $this->closeMessageDb($db);
    }
}
