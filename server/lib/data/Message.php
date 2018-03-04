<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\data;

use io\privfs\config\Config;
use io\privfs\core\Callbacks;
use io\privfs\core\DbManager;
use io\privfs\core\Utils;
use io\privfs\core\ECUtils;
use io\privfs\core\JsonRpcException;
use io\privfs\core\Settings;
use io\privfs\core\MailService;
use io\privfs\jsonrpc\Raw;
use Doctrine\Common\Cache\CacheProvider;

class Message extends Base {
    const ANONYMOUS_KEY_TTL = 600; // 10 min
    
    public static $templates;
    private $config;
    private $block;
    private $sink;
    private $privFsUser;
    private $accessService;
    private $anonymousKeys;
    private $domainFilter;
    private $userStatus;
    private $notifier;
    private $callbacks;
    private $settings;
    private $mailService;
    private $logger;
    
    public function __construct(DbManager $dbManager, Block $block,
        Sink $sink, PrivFsUser $privFsUser, AccessService $accessService,
        CacheProvider $anonymousKeys, DomainFilter $domainFilter,
        UserStatus $userStatus, Notifier $notifier, Config $config,
        Callbacks $callbacks, Settings $settings, MailService $mailService, User $user) {
        
        parent::__construct($dbManager);
        $this->block = $block;
        $this->sink = $sink;
        $this->privFsUser = $privFsUser;
        $this->accessService = $accessService;
        $this->anonymousKeys = $anonymousKeys;
        $this->domainFilter = $domainFilter;
        $this->userStatus = $userStatus;
        $this->notifier = $notifier;
        $this->config = $config;
        $this->callbacks = $callbacks;
        $this->settings = $settings;
        $this->mailService = $mailService;
        $this->user = $user;
        $this->logger = \io\privfs\log\LoggerFactory::get($this);
    }
    
    private function afterMessageReceived($sink, $hashmail = "", $extra = "") {
        if ($this->config->isInstantNotificationEnabled() && ($sink["acl"] === "public" || $sink["acl"] === "anonymous")) {
            if ($this->userStatus->isUserLogged($sink["owner"])) {
                $this->logger->debug("User " . $sink["owner"] . " is still logged in, so no instant notification for him");
            }
            else {
                $this->logger->debug("User " . $sink["owner"] . " is logged out, try send instant notification to him");
                $this->notifier->processUserByUsername($sink["owner"], true);
            }
        }
        $lowUsers = $this->sink->getLowUsers($sink);
        $decExtra = $extra === "" ? false : json_decode($extra, true);
        foreach ($lowUsers as $username) {
            $lowUser = $this->user->getLowUser($username);
            if (is_null($lowUser)) {
                continue;
            }
            if ($lowUser["username"] . "#" . $lowUser["hosts"][0] != $hashmail) {
                $this->notifier->sendLowUserNotify($lowUser, $decExtra);
            }
        }
    }
    
    private function isAnonymousHashmail($hashmail) {
        $parsedHashmail = PrivFsUser::parseHashmail($hashmail);
        return $parsedHashmail["user"] == "anonymous";
    }
    
    private function validateExternalAccess($hashmail, $pub, $sid, $atInit, $keystoreCheck = true) {
        $parsedHashmail = PrivFsUser::parseHashmail($hashmail);
        if ($parsedHashmail === false) {
            throw new JsonRpcException("INVALID_HASHMAIL");
        }
        $isAnonymous = $parsedHashmail["user"] == "anonymous";
        $isLowUser = false;
        if ($isAnonymous) {
            if ($atInit) {
                if ($this->anonymousKeys->contains($pub["base58"])) {
                    throw new JsonRpcException("ACCESS_DENIED");
                }
                $this->anonymousKeys->save($pub["base58"], "", Message::ANONYMOUS_KEY_TTL);
            }
            else {
                if (!$this->anonymousKeys->contains($pub["base58"])) {
                    throw new JsonRpcException("ACCESS_DENIED");
                }
            }
        }
        else {
            if ($this->user->isLowUser($parsedHashmail["user"], $parsedHashmail["domain"], $pub["base58"])) {
                $isLowUser = true;
            }
            else {
                if ($keystoreCheck && !$this->privFsUser->validateParsedHashmail($parsedHashmail, $pub["base58"])) {
                    throw new JsonRpcException("INVALID_HASHMAIL");
                }
                if (!$this->domainFilter->isValidDomain($parsedHashmail["domain"])) {
                    throw new JsonRpcException("HOST_BLACKLISTED");
                }
            }
        }
        $sink = $this->sink->sinkGet($sid["base58"]);
        if ($sink === null) {
            throw new JsonRpcException("SINK_DOESNT_EXISTS");
        }
        if ($isAnonymous) {
            if ($sink["acl"] !== "anonymous") {
                throw new JsonRpcException("INVALID_SINK_ACL");
            }
        }
        else if ($isLowUser) {
            $lowUsers = $this->sink->getLowUsers($sink);
            if (!in_array($parsedHashmail["user"], $lowUsers, true)) {
                throw new JsonRpcException("INVALID_SINK_ACL");
            }
        }
        else {
            if ($sink["acl"] != "public" && $sink["acl"] != "shared" && $sink["acl"] != "anonymous") {
                throw new JsonRpcException("INVALID_SINK_ACL");
            }
        }
        return array(
            "hashmail" => $parsedHashmail,
            "sink" => $sink
        );
    }
    
    private function readExternalMessageData($data, $keystoreCheck = true) {
        $vData = $this->validateExternalAccess($data["senderHashmail"], $data["senderPub58"], $data["sid"], $keystoreCheck, $keystoreCheck);
        $nData = $this->readMessageData($data);
        $nData["json"]["senderDomain"] = $vData["hashmail"]["host"];
        $nData["sink"] = $vData["sink"];
        return $nData;
    }
    
    private function readInternalMessageData($data, $username) {
        if ($data["sid"]["base58"] != $data["senderPub58"]["base58"]) {
            throw new JsonRpcException("INVALID_JSON_PARAMETERS");
        }
        $this->sink->validateAccessToSink($username, $data["sid"]);
        return $this->readMessageData($data);
    }
    
    private function readMessageData($data) {
        if (count($data["blocks"]) == 0 && strlen($data["extra"]["bin"]) == 0) {
            throw new JsonRpcException("INVALID_JSON_PARAMETERS");
        }
        $versionData = "";
        $blocks = array();
        foreach ($data["blocks"] as $block) {
            if (!$this->block->blockExists($block)) {
                throw new JsonRpcException("BLOCK_DOESNT_EXIST");
            }
            $versionData .= $block["base58"];
            array_push($blocks, $block["base58"]);
        }
        $versionData .= base64_encode(hash("sha256", $data["extra"]["bin"], true));
        $versionData .= $data["sid"]["base58"];
        $versionData .= $data["senderPub58"]["base58"];
        $version = hash("sha256", $versionData, true);
        if (!ECUtils::verifySignature($data["senderPub58"]["ecc"], $data["signature"]["bin"], $version)) {
            throw new JsonRpcException("INVALID_SIGNATURE");
        }
        return array("json" => array(
            "sid" => $data["sid"]["base58"],
            "senderPub58" => $data["senderPub58"]["base58"],
            "blocks" => $blocks,
            "extra" => $data["extra"]["base64"],
            "signature" => $data["signature"]["base64"]
        ), "sid" => $data["sid"]["base58"]);
    }
    
    private function addMidToIndex($db, $dbId, $mid) {
        if ($db->exists($dbId)) {
            $raw = $db->fetch($dbId);
            $mids = Sink::getMids($raw);
            $index = array_search($mid, $mids);
            if ($index === false) {
                $db->replace($dbId, $raw . "," . strval($mid));
            }
        }
        else {
            $db->insert($dbId, strval($mid));
        }
    }
    
    private function deleteMidFromIndex($db, $dbId, $mid) {
        if ($db->exists($dbId)) {
            $mids = Sink::getMids($db->fetch($dbId));
            $index = array_search($mid, $mids);
            if ($index !== false) {
                if (count($mids) == 1) {
                    $db->delete($dbId);
                }
                else {
                    array_splice($mids, $index, 1);
                    $db->replace($dbId, implode(",", $mids));
                }
            }
        }
    }
    
    private function addMessageToIndex($db, $mid) {
        $this->addMidToIndex($db, Sink::MIDS_KEY, $mid);
    }
    
    private function deleteMessageFromIndex($db, $mid) {
        $this->deleteMidFromIndex($db, Sink::MIDS_KEY, $mid);
    }
    
    private function addTagToIndex($db, $mid, $tag) {
        $this->addMidToIndex($db, Sink::getTagDbId($tag), $mid);
    }
    
    private function deleteTagFromIndex($db, $mid, $tag) {
        $this->deleteMidFromIndex($db, Sink::getTagDbId($tag), $mid);
    }
    
    private function updateTagsIndex($db, $mid, $oldTags, $newTags) {
        foreach ($oldTags as $oldTag) {
            $found = false;
            foreach ($newTags as $newTag) {
                if ($oldTag == $newTag) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $this->deleteTagFromIndex($db, $mid, $oldTag);
            }
        }
        foreach ($newTags as $newTag) {
            $found = false;
            foreach ($oldTags as $oldTag) {
                if ($newTag == $oldTag) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $this->addTagToIndex($db, $mid, $newTag);
            }
        }
    }
    
    private function messageLog($db, $mid, $data, $tags, $delete, $isNew, $expectedModSeq) {
        $modSeq = $db->fetch(Sink::MOD_SEQ_KEY) + 1;
        if (!is_null($expectedModSeq) && $modSeq != $expectedModSeq) {
            throw new JsonRpcException("INVALID_MOD_SEQ");
        }
        
        $metaDbId = Sink::getMessageMetaDbId($mid);
        $modDbId = Sink::getModDbId($modSeq);
        
        $timestamp = Utils::timeMili()->toDec();
        $meta = json_encode(array(
            "data" => $data,
            "tags" => $tags,
            "timestamp" => $timestamp,
            "modId" => $modSeq,
            "msgId" => $mid,
            "deleted" => $delete
        ));
        $db->replace(Sink::MOD_SEQ_KEY, $modSeq);
        $db->insert($modDbId, $mid);
        if ($isNew) {
            $db->insert($metaDbId, $meta);
            $this->updateTagsIndex($db, $mid, array(), $tags);
        }
        else {
            $oldMeta = json_decode($db->fetch($metaDbId), true);
            $oldTags = isset($oldMeta["tags"]) ? $oldMeta["tags"] : array();
            $db->replace($metaDbId, $meta);
            $this->updateTagsIndex($db, $mid, $oldTags, $tags);
        }
        
        return $timestamp;
    }
    
    private function messageSaveCore($db, &$data, $flags, $tags) {
        $mid = $db->fetch(Sink::SEQ_KEY) + 1;
        
        $timestamp = $this->messageLog($db, $mid, $flags, $tags, false, true, null);
        $data["serverId"] = $mid;
        $data["serverDate"] = $timestamp;
        
        $db->replace(Sink::SEQ_KEY, $mid);
        $db->insert(Sink::getMessageDbId($mid), json_encode($data));
        $db->insert(Sink::getMessageDateDbId($mid), $timestamp);
        $this->addMessageToIndex($db, $mid);
        
        return $timestamp;
    }
    
    private function messageModifyCore($db, $mid, $flags, $tags, $expectedModSeq) {
        return $this->messageLog($db, $mid, $flags, $tags, false, false, $expectedModSeq);
    }
    
    private function messageDeleteCore($db, $mid, $expectedModSeq) {
        $timestamp = $this->messageLog($db, $mid, "", array(), true, false, $expectedModSeq);
        $db->delete(Sink::getMessageDbId($mid));
        $db->delete(Sink::getMessageDateDbId($mid));
        $this->deleteMessageFromIndex($db, $mid);
        return $timestamp;
    }
    
    private function validateAccessToMessage($db, $username, $sid, $mid, $mode = false) {
        $this->sink->validateAccessToSink($username, $sid, $mode);
        if (is_null($db)) {
            $db = $this->getMessageDbForWrite($sid["base58"]);
        }
        $this->messageExists($db, $mid);
        return $db;
    }
    
    private function messageExists($db, $mid) {
        $msgDbId = Sink::getMessageDbId($mid);
        $metaDbId = Sink::getMessageMetaDbId($mid);
        if (!$db->exists($msgDbId) || !$db->exists($metaDbId)) {
            throw new JsonRpcException("MESSAGE_DOESNT_EXISTS");
        }
        $meta = json_decode($db->fetch($metaDbId), true);
        if ($meta["deleted"]) {
            throw new JsonRpcException("MESSAGE_DOESNT_EXISTS");
        }
    }
    
    private function secureFormValidate($sid, $extra) {
        // Trigger formvalidator callback for anonymous user only
        $results = $this->callbacks->trigger("formvalidator", array($sid, $extra));
        foreach ($results as $valid) {
            if ($valid !== true) {
                throw new JsonRpcException("SECURE_FORM_VALIDATION_FAILED");
            }
        }
    }
    
    private function validateVerifyEmailExtra($extra) {
        $enc = json_decode($extra, true);
        if ($enc === false || !isset($enc["email"]) || !Utils::isValidEmail($enc["email"])) {
            throw new JsonRpcException("SECURE_FORM_VALIDATION_FAILED");
        }
        return $enc;
    }
    
    private function proxyPassAcls($data, $acls) {
        foreach ($acls as $acl) {
            if ($acl["type"] == 0) {
                continue;
            }
            if ($acl["type"] == 1) {
                return false;
            }
            if ($acl["property"] != "senderPub58") {
                return false;
            }
            $present = in_array($data["senderPub58"], $acl["list"], true);
            if ($acl["type"] == 2 && !$present) {
                return false;
            }
            if ($acl["type"] == 3 && $present) {
                return false;
            }
        }
        return true;
    }
    
    private function getProxyEntry($entry, $defaultAcls) {
        return isset($entry["value"]) && isset($entry["acls"]) ? $entry : array("value" => $entry, "acls" => $defaultAcls);
    }
    
    private function getProxyEntry2($value, $info) {
        $defaultAcls = isset($info["defaultAcls"]) ? $info["defaultAcls"] : array();
        foreach ($info["list"] as $entry) {
            $v = $this->getProxyEntry($entry, $defaultAcls);
            if ($v["value"] == $value) {
                return $v;
            }
        }
        return null;
    }
    
    private function proxyMessage($sink, $srcSid, $data, $flags, $tags) {
        $info = $this->sink->getSinkProxyTo($sink);
        $defaultAcls = isset($info["defaultAcls"]) ? $info["defaultAcls"] : array();
        if (count($info["list"]) > 0) {
            $this->getSinkDbForRead();
            $data["originalSid"] = $srcSid;
            foreach ($info["list"] as $entry) {
                $entry = $this->getProxyEntry($entry, $defaultAcls);
                $destSid = $entry["value"];
                if (!$this->proxyPassAcls($data, $entry["acls"])) {
                    $this->logger->debug("Cannot proxy message from " . $srcSid . " to " . $destSid . " - source acl does not pass");
                    continue;
                }
                $destSink = $this->sink->sinkGet($destSid);
                if ($destSink == null) {
                    $this->logger->debug("Cannot proxy message from " . $srcSid . " to " . $destSid . " - destination sink does not exist");
                    continue;
                }
                $proxyFrom = $this->sink->getSinkProxyFrom($destSink);
                $fromEntry = $this->getProxyEntry2($srcSid, $proxyFrom);
                if ($fromEntry == null || !$this->proxyPassAcls($data, $fromEntry["acls"])) {
                    $this->logger->debug("Cannot proxy message from " . $srcSid . " to " . $destSid . " - destination sink not allow proxy from this sink");
                    continue;
                }
                $this->logger->debug("Proxy message from " . $srcSid . " to " . $destSid);
                $db = $this->getMessageDbForWrite($destSid);
                $this->messageSaveCore($db, $data, $flags, $tags);
                $this->afterMessageReceived($destSink);
                $this->closeMessageDb($db);
            }
            $this->closeSinkDb();
        }
    }
    
    //====================================================
    
    public function messagePostInit($sid, $signature, $extra = "") {
        $this->block->verifyInitBlockTransferSignature("messagePost" . $sid["base58"], $signature);
        $vData = $this->validateExternalAccess($signature["hashmail"], $signature["pub"], $sid, true);
        if ($vData["hashmail"]["user"] === "anonymous") {
            $this->secureFormValidate($sid["base58"], $extra);
            if ($this->sink->sinkNeedEmailVerification($vData["sink"])) {
                $this->validateVerifyEmailExtra($extra);
            }
        }
        $trasferSessionId = $this->block->createTransferSession(array(
            "type" => "messagePost",
            "blocks" => array(),
            "sid" => $sid["base58"],
            "hashmail" => $signature["hashmail"],
            "pub" => $signature["pub"]["base58"],
            "extra" => $extra
        ));
        return $trasferSessionId;
    }
    
    public function messagePost($data, $tags, $transferId, $extra = "") {
        if (strlen($transferId["bin"]) == 0) {
            if (count($data["blocks"]) > 0) {
                throw new JsonRpcException("INVALID_TRANSFER_SESSION");
            }
            $keystoreCheck = true;
        }
        else {
            $extra = $this->block->validateTransferBlocks($transferId, $data["blocks"], array(
                "type" => "messagePost",
                "sid" => $data["sid"]["base58"],
                "hashmail" => $data["senderHashmail"],
                "pub" => $data["senderPub58"]["base58"]
            ));
            $keystoreCheck = false;
        }
        $nData = $this->readExternalMessageData($data, $keystoreCheck);
        if ($this->isAnonymousHashmail($data["senderHashmail"])) {
            if ($keystoreCheck) {
                $this->secureFormValidate($data["sid"]["base58"], $extra);
            }
            if ($this->sink->sinkNeedEmailVerification($nData["sink"])) {
                $extraEnc = $this->validateVerifyEmailExtra($extra);
                $timestamp = Utils::timeMili()->toDec();
                $this->addMessageToVerify($extraEnc["email"], Utils::arrayValue($extraEnc, "lang"), $nData["sid"], $nData["json"], "", $tags, $timestamp, $nData["sink"]["owner"]);
                return array("serverId" => -1, "serverDate" => $timestamp, "emailVerificationRequired" => true);
            }
        }
        
        $db = $this->getMessageDbForWrite($nData["sid"]);
        $this->messageSaveCore($db, $nData["json"], "", $tags);
        $this->afterMessageReceived($nData["sink"], $data["senderHashmail"], $extra);
        $this->closeMessageDb($db);
        
        $this->proxyMessage($nData["sink"], $nData["sid"], $nData["json"], "", $tags);
        
        return array("serverId" => $nData["json"]["serverId"], "serverDate" => $nData["json"]["serverDate"]);
    }
    
    public function messageCreateInit($username, $sid) {
        $this->sink->validateAccessToSink($username, $sid);
        $trasferSessionId = $this->block->createTransferSession(array(
            "type" => "messageCreate",
            "blocks" => array(),
            "sid" => $sid["base58"],
            "username" => $username
        ));
        return $trasferSessionId;
    }
    
    public function messageCreate($username, $data, $flags, $tags, $transferId) {
        if (strlen($transferId["bin"]) == 0) {
            if (count($data["blocks"]) > 0) {
                throw new JsonRpcException("INVALID_TRANSFER_SESSION");
            }
        }
        else {
            $this->block->validateTransferBlocks($transferId, $data["blocks"], array(
                "type" => "messageCreate",
                "sid" => $data["sid"]["base58"],
                "username" => $username
            ));
        }
        $data = $this->readInternalMessageData($data, $username);
        
        $db = $this->getMessageDbForWrite($data["sid"]);
        $this->messageSaveCore($db, $data["json"], $flags, $tags);
        $this->closeMessageDb($db);
        
        return array("serverId" => $data["json"]["serverId"], "serverDate" => $data["json"]["serverDate"]);
    }
    
    public function messageCreateAndDeleteInit($username, $dSid, $dMid, $cSid) {
        $deleteDb = $this->getMessageDbForWrite($dSid["base58"]);
        $this->validateAccessToMessage($deleteDb, $username, $dSid, $dMid);
        $this->closeMessageDb($deleteDb);
        $this->sink->validateAccessToSink($username, $cSid);
        $trasferSessionId = $this->block->createTransferSession(array(
            "type" => "messageCreateAndDelete",
            "blocks" => array(),
            "dSid" => $dSid["base58"],
            "dMid" => $dMid,
            "cSid" => $cSid["base58"],
            "username" => $username
        ));
        return $trasferSessionId;
    }
    
    public function messageCreateAndDelete($username, $data, $flags, $tags, $transferId, $sid, $mid, $expectedModSeq) {
        if (strlen($transferId["bin"]) == 0) {
            if (count($data["blocks"]) > 0) {
                throw new JsonRpcException("INVALID_TRANSFER_SESSION");
            }
        }
        else {
            $this->block->validateTransferBlocks($transferId, $data["blocks"], array(
                "type" => "messageCreateAndDelete",
                "dSid" => $sid["base58"],
                "dMid" => $mid,
                "cSid" => $data["sid"]["base58"],
                "username" => $username
            ));
        }
        $data = $this->readInternalMessageData($data, $username);
        if ($sid["base58"] < $data["sid"]) {
            $deleteDb = $this->getMessageDbForWrite($sid["base58"]);
            $insertDb = $this->getMessageDbForWrite($data["sid"]);
        }
        else {
            $insertDb = $this->getMessageDbForWrite($data["sid"]);
            $deleteDb = $this->getMessageDbForWrite($sid["base58"]);
        }
        $this->validateAccessToMessage($deleteDb, $username, $sid, $mid);
        
        $this->messageSaveCore($insertDb, $data["json"], $flags, $tags);
        $this->messageDeleteCore($deleteDb, intval($mid), $expectedModSeq);
        
        $this->closeMessageDb($insertDb);
        $this->closeMessageDb($deleteDb);
        
        return array("serverId" => $data["json"]["serverId"], "serverDate" => $data["json"]["serverDate"]);
    }
    
    public function messageModify($username, $sid, $mid, $flags, $tags, $expectedModSeq) {
        $db = $this->validateAccessToMessage(null, $username, $sid, $mid, "modify-msg");
        $this->messageModifyCore($db, intval($mid), $flags, $tags, $expectedModSeq);
        $this->closeMessageDb($db);
        return "OK";
    }
    
    public function messageReplaceFlags($username, $sid, $mid, $flags) {
        $db = $this->validateAccessToMessage(null, $username, $sid, $mid);
        $metaDbId = Sink::getMessageMetaDbId($mid);
        $meta = json_decode($db->fetch($metaDbId), true);
        $meta["data"] = $flags;
        $db->replace($metaDbId, json_encode($meta));
        $this->closeMessageDb($db);
        return "OK";
    }
    
    public function messageGetSingle($username, $sid, $mid) {
        $this->sink->validateAccessToSink($username, $sid, true);
        $db = $this->getMessageDbForRead($sid["base58"]);
        $this->messageExists($db, $mid);
        $data = json_decode($db->fetch(Sink::getMessageDbId($mid)), true);
        $this->closeMessageDb($db);
        return $data;
    }
    
    public function messageGet($username, $sid, $mids, $mode) {
        $this->sink->validateAccessToSink($username, $sid, true);
        $addData = $mode == "DATA" || $mode == "DATA_AND_META";
        $addMeta = $mode == "META" || $mode == "DATA_AND_META";
        $db = $this->getMessageDbForRead($sid["base58"]);
        $map = "{";
        foreach ($mids as $mid) {
            try {
                $this->messageExists($db, $mid);
            }
            catch (JsonRpcException $e) {
                $e->setData($mid);
                throw $e;
            }
            $map .= ($map == "{" ? "" : ",") . "\"" . $mid . "\":{";
            if ($addData) {
                $map .= "\"data\":" . $db->fetch(Sink::getMessageDbId($mid));
            }
            if ($addMeta) {
                $map .= ($addData ? "," : "") . "\"meta\":" . $db->fetch(Sink::getMessageMetaDbId($mid));
            }
            $map .= "}";
        }
        $this->closeMessageDb($db);
        return new Raw($map . "}");
    }
    
    public function messageDelete($username, $sid, $mids, $expectedModSeq) {
        $this->sink->validateAccessToSink($username, $sid);
        
        $db = $this->getMessageDbForWrite($sid["base58"]);
        $modSeq = intval($db->fetch(Sink::MOD_SEQ_KEY));
        if ($modSeq + count($mids) != $expectedModSeq) {
            $this->closeMessageDb($db);
            throw new JsonRpcException("INVALID_MOD_SEQ");
        }
        foreach ($mids as $mid) {
            try {
                $this->messageExists($db, $mid);
            }
            catch (JsonRpcException $e) {
                $e->setData($mid);
                throw $e;
            }
        }
        foreach ($mids as $mid) {
            $modSeq++;
            $msgDbId = Sink::getMessageDbId($mid);
            $dateDbId = Sink::getMessageDateDbId($mid);
            $metaDbId = Sink::getMessageMetaDbId($mid);
            $modDbId = Sink::getModDbId($modSeq);
            
            $meta = json_encode(array(
                "data" => "",
                "tags" => array(),
                "timestamp" => Utils::timeMili()->toDec(),
                "modId" => $modSeq,
                "msgId" => $mid,
                "deleted" => true
            ));
            
            $oldMeta = json_decode($db->fetch($metaDbId), true);
            $oldTags = isset($oldMeta["tags"]) ? $oldMeta["tags"] : array();
            
            $db->insert($modDbId, $mid);
            $db->replace($metaDbId, $meta);
            $this->updateTagsIndex($db, $mid, $oldTags, array());
            $db->delete($msgDbId);
            $db->delete($dateDbId);
            $this->deleteMessageFromIndex($db, $mid);
        }
        $db->replace(Sink::MOD_SEQ_KEY, $modSeq);
        
        $this->closeMessageDb($db);
        return "OK";
    }
    
    public function messageModifyTags($username, $sid, $mids, $toAdd, $toRemove, $expectedModSeq) {
        $this->sink->validateAccessToSink($username, $sid);
        
        $db = $this->getMessageDbForWrite($sid["base58"]);
        $modSeq = intval($db->fetch(Sink::MOD_SEQ_KEY));
        if ($modSeq + count($mids) != $expectedModSeq) {
            $this->closeMessageDb($db);
            throw new JsonRpcException("INVALID_MOD_SEQ");
        }
        foreach ($mids as $mid) {
            try {
                $this->messageExists($db, $mid);
            }
            catch (JsonRpcException $e) {
                $e->setData($mid);
                throw $e;
            }
        }
        foreach ($mids as $mid) {
            $modSeq++;
            $metaDbId = Sink::getMessageMetaDbId($mid);
            $modDbId = Sink::getModDbId($modSeq);
            
            $oldMeta = json_decode($db->fetch($metaDbId), true);
            $oldTags = isset($oldMeta["tags"]) ? $oldMeta["tags"] : array();
            $newTags = array_values(array_unique(array_merge($oldTags, $toAdd)));
            $newTags = array_values(array_diff($newTags, $toRemove));
            
            $meta = json_encode(array(
                "data" => $oldMeta["data"],
                "tags" => $newTags,
                "timestamp" => Utils::timeMili()->toDec(),
                "modId" => $modSeq,
                "msgId" => $mid,
                "deleted" => false
            ));
            
            $db->insert($modDbId, $mid);
            $db->replace($metaDbId, $meta);
            $this->updateTagsIndex($db, $mid, $oldTags, $newTags);
        }
        $db->replace(Sink::MOD_SEQ_KEY, $modSeq);
        
        $this->closeMessageDb($db);
        return "OK";
    }
    
    public function addMessageToVerify($email, $lang, $sid, $data, $flags, $tags, $timestamp, $username) {
        $mqDb = $this->dbManager->getDbForWrite("messageQueue");
        do {
            $token = uniqid("", true);
        }
        while ($mqDb->exists($token));
        $mqDb->insert($token, json_encode(array(
            "email" => $email,
            "lang" => $lang,
            "sid" => $sid,
            "data" => $data,
            "flags" => $flags,
            "tags" => $tags,
            "timestamp" => $timestamp
        )));
        $this->dbManager->closeDb($mqDb);
        $link = $this->config->getValidateUrl2($token);
        $config = $this->settings->getSettingForLanguageFromObj(Message::$templates["emailConfirmation"], $lang);
        if ($config === false) {
            $this->logger->error("Cannot send mail to '$email' - invalid config");
            return;
        }
        $userInfo = $this->user->getUserKeystoreInfo($username);
        $from = array(
            "name" => $userInfo["displayName"] ? $userInfo["displayName"] : $userInfo["hashmail"],
            "email" => $this->config->getServerEmailNoReply()
        );
        $body = str_replace("{link}", $link, $config["body"]);
        if ($this->mailService->send($from, $email, $config["subject"], $body, $config["isHtml"])) {
            $this->logger->debug("Successfully sent mail to '$email'");
        }
        else {
            $this->logger->error("Cannot send mail to '$email' - unknown error");
        }
        return "OK";
    }
    
    public function verifyMessageToken($token) {
        $mqDb = $this->dbManager->getDbForWrite("messageQueue");
        if ($mqDb->exists($token)) {
            $raw = $mqDb->fetch($token);
            $mqDb->delete($token);
            $this->dbManager->closeDb($mqDb);
        }
        else {
            $this->dbManager->closeDb($mqDb);
            return false;
        }
        $entry = json_decode($raw, true);
        if ($entry === false) {
            return false;
        }
        $sink = $this->sink->sinkGet($entry["sid"]);
        if ($sink === null) {
            return false;
        }
        $db = $this->getMessageDbForWrite($entry["sid"]);
        $this->messageSaveCore($db, $entry["data"], $entry["flags"], $entry["tags"]);
        $this->afterMessageReceived($sink);
        $this->closeMessageDb($db);
        
        $this->proxyMessage($sink, $entry["sid"], $entry["data"], $entry["flags"], $entry["tags"]);
        
        return $entry;
    }
}

Message::$templates = array(
    "emailConfirmation" => array(
        "defaultLang" => "en",
        "langs" => array(
            "en" => array(
                "from" => array(
                    "name" => "PrivMX"
                ),
                "isHtml" => false,
                "subject" => "Please confirm your message",
                "body" => "\nPlease click this link to confirm sending message via contact form:\n{link}\n\n(this request has been automatically generated by my PrivMX private mail server)"
            ),
            "pl" => array(
                "from" => array(
                    "name" => "PrivMX"
                ),
                "isHtml" => false,
                "subject" => "Proszę o potwierdzenie wiadomości",
                "body" => "\nKliknij link, aby potwierdzić wysłanie wiadomości przez formularz kontaktowy:\n{link}\n\n(ta prośba została wygenerowana automatycznie przez mój serwer prywatnej poczty PrivMX)"
            )
        )
    )
);
