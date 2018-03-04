<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\data;

use io\privfs\config\Config;
use io\privfs\core\ECUtils;
use io\privfs\core\DbManager;
use io\privfs\core\JsonRpcException;
use io\privfs\core\Nonce;
use io\privfs\core\Settings;
use io\privfs\core\Utils;
use io\privfs\core\LogDb;
use io\privfs\core\MailService;
use io\privfs\core\Validator;
use privmx\pki\PrivmxPKI;
use io\privfs\core\Callbacks;
use BI\BigInteger;

class User {
    
    const FIRST_ADMIN_SETTINGS_KEY = "firstAdmin";
    
    private static $forbiddenUsernames = array("anonymous");
    public static function getForbiddenUsernames() {
        return self::$forbiddenUsernames;
    }
    
    private $logger;
    private $dbManager;
    private $settings;
    private $config;
    private $nonce;
    private $eventManager;
    private $loginsLog;
    private $mailService;
    private $plugins;
    private $pki;
    private $callbacks;
    
    public function __construct(DbManager $dbManager, Settings $settings, Config $config,
        Nonce $nonce, EventManager $eventManager, LogDb $loginsLog, MailService $mailService,
        PrivmxPKI $pki, $plugins, Callbacks $callbacks) {
        
        $this->logger = \io\privfs\log\LoggerFactory::get($this);
        $this->dbManager = $dbManager;
        $this->settings = $settings;
        $this->config = $config;
        $this->nonce = $nonce;
        $this->eventManager = $eventManager;
        $this->loginsLog = $loginsLog;
        $this->mailService = $mailService;
        $this->pki = $pki;
        $this->plugins = $plugins;
        $this->callbacks = $callbacks;
    }
    
    //========================
    //        HELPERS
    //========================
    
    public function getPkiName($username, $host) {
        return $username;
    }
    
    public function getUser($username, $host, $checkHost) {
        $db = $this->dbManager->getDbForRead("user");
        if ($db->exists($username)) {
            $data = json_decode($db->fetch($username), true);
            if ($checkHost && !in_array($host, $this->config->getHosts()) && (!isset($data["hosts"]) || !in_array($host, $data["hosts"]))) {
                return null;
            }
            return $data;
        }
        return null;
    }
    
    public function getUser2($username, $host, $checkHost) {
        $db = $this->dbManager->getDbForRead("user");
        if ($db->exists($username)) {
            $data = json_decode($db->fetch($username), true);
            $data["type"] = "normal";
        }
        else {
            $this->dbManager->closeDb($db);
            $db = $this->dbManager->getDbForRead("lowusers");
            if ($db->exists($username)) {
                $data = json_decode($db->fetch($username), true);
                $data["type"] = "low";
            }
            $this->dbManager->closeDb($db);
        }
        if (is_null($data)) {
            return null;
        }
        if ($checkHost && !in_array($host, $this->config->getHosts()) && (!isset($data["hosts"]) || !in_array($host, $data["hosts"]))) {
            return null;
        }
        return $data;
    }
    
    public function isAdmin($user) {
        return isset($user["isAdmin"]) && $user["isAdmin"] === true;
    }
    
    public function adminExists() {
        $db = $this->dbManager->getDbForRead("user");
        $key = $db->firstkey();
        while ($key !== false) {
            $user = json_decode($db->fetch($key), true);
            if ($this->isAdmin($user)) {
                return true;
            }
            $key = $db->nextkey();
        }
        return false;
    }
    
    public function getUsersCount($db) {
        $count = 0;
        $key = $db->firstkey();
        while ($key !== false) {
            $count++;
            $key = $db->nextkey();
        }
        return $count;
    }
    
    public function checkUsersCount($db, $additional) {
        $maxCount = $this->config->getMaxUsersCount();
        if ($maxCount != -1 && $maxCount < $this->getUsersCount($db) + $additional) {
            throw new JsonRpcException("MAX_USERS_COUNT_EXCEEDED");
        }
    }
    
    //========================
    //         SRP
    //========================
    
    public function getSrpUser($I, $host) {
        if ($this->config->isLoginBlocked()) {
            throw new JsonRpcException("LOGIN_BLOCKED");
        }
        $user = $this->getUser2($I, $host, true);
        if (is_null($user)) {
            return null;
        }
        if (!$user["activated"] || (isset($user["blocked"]) && $user["blocked"])) {
            return null;
        }
        $isAdmin = $user["type"] != "low" && isset($user["isAdmin"]) && $user["isAdmin"] === true;
        return array(
            "I" => $user["username"],
            "s" => hex2bin($user["srpSalt"]),
            "v" => new BigInteger($user["srpVerifier"], 16),
            "loginData" => $user["loginData"],
            "isAdmin" => $isAdmin,
            "type" => $user["type"] == "low" ? "low" : ($isAdmin ? "admin" : "user")
        );
    }
    
    public function getKeyUser($pub) {
        if ($this->config->isLoginBlocked()) {
            throw new JsonRpcException("LOGIN_BLOCKED");
        }
        $db = $this->dbManager->getDbForRead("user");
        $user = null;
        $key = $db->firstkey();
        while ($key !== false) {
            $u = json_decode($db->fetch($key), true);
            if ($u["activated"] && (!isset($u["blocked"]) || !$u["blocked"]) && isset($u["recoveryData"]) && $u["recoveryData"]["pub"] == $pub["base58"]) {
                $user = $u;
                break;
            }
            $key = $db->nextkey();
        }
        return is_null($user) ? null : array(
            "I" => $user["username"],
            "pub" => $user["recoveryData"]["pub"],
            "isAdmin" => isset($user["isAdmin"]) && $user["isAdmin"] === true
        );
    }
    
    public function loginSuccess($session) {
        $username = $session["user"]["I"];
        $timestamp = Utils::timeMili()->toDec();
        $loginEntry = array(
            "username" => $username,
            "timestamp" => $timestamp,
            "properties" => $session["properties"]
        );
        $this->loginsLog->add($loginEntry);
        
        $db = $this->dbManager->getDbForWrite("user");
        $user = $this->getUser($username, null, false);
        if (is_null($user)) {
            $this->dbManager->closeDb($db);
            $db = $this->dbManager->getDbForWrite("lowusers");
            if ($db->exists($username)) {
                $user = json_decode($db->fetch($username), true);
                $user["lastLoginDate"] = $timestamp;
                $db->replace($username, json_encode($user));
            }
            $this->dbManager->closeDb($db);
        }
        else {
            $user["lastLoginDate"] = $timestamp;
            $db->replace($username, json_encode($user));
            $this->dbManager->closeDb($db);
        }
    }
    
    protected function checkUsername($username) {
        if (in_array($username, self::$forbiddenUsernames)) {
            throw new JsonRpcException("FORBIDDEN_USERNAME");
        }
    }
    
    //========================
    //          RPC
    //========================
    
    public function createUser($username, $host, $srpSalt, $srpVerifier, $loginData, $pin, $token, $privData,
        $keystore, $kis, $signature, $email, $language, $dataVersion, $weakPassword) {
        
        $this->checkUsername($username);
        
        $db = $this->dbManager->getDbForWrite("user");
        if (!$this->config->hostIsMyself($host)) {
            throw new JsonRpcException("INVALID_HOST");
        }
        $deleteToken = false;
        $exists = false;
        if ($token == "") {
            $user = $this->getUser($username, null, false);
            $exists = !is_null($user);
            if ($exists) {
                if ($user["activated"]) {
                    throw new JsonRpcException("USER_ALREADY_EXISTS");
                }
                if (isset($user["token"])) {
                    throw new JsonRpcException("INVALID_TOKEN");
                }
                $user["pinAttemptsCount"]++;
                if ($user["pinAttemptsCount"] > $this->config->getMaxPinAttemptsCount()) {
                    throw new JsonRpcException("MAX_PIN_ATTEMPTS_EXCEEDED");
                }
                if ($user["pin"] != $pin) {
                    $db->replace($user["username"], json_encode($user));
                    throw new JsonRpcException("INVALID_PIN");
                }
            }
            else {
                if (!$this->config->isOpenRegistrationEnabled()) {
                    throw new JsonRpcException("OPEN_REGISTRATION_DISABLED");
                }
                $this->checkUsersCount($db, 1);
                if ($pin != "") {
                    throw new JsonRpcException("INVALID_PIN");
                }
                $user = array(
                    "username" => $username,
                    "pin" => $pin,
                    "pinAttemptsCount" => 1
                );
            }
        }
        else {
            $user = $this->getUser($token, null, false);
            if (is_null($user)) {
                $user = $this->getUser($username, null, false);
                $exists = !is_null($user);
                if (!$exists) {
                    throw new JsonRpcException("INVALID_TOKEN");
                }
                if ($user["activated"]) {
                    throw new JsonRpcException("USER_ALREADY_EXISTS");
                }
                if (isset($user["pin"]) && $user["pin"]) {
                    throw new JsonRpcException("INVALID_PIN");
                }
                if (!isset($user["token"]) || $user["token"] != $token) {
                    throw new JsonRpcException("INVALID_TOKEN");
                }
                unset($user["token"]);
            }
            else {
                if (!isset($user["invitedBy"])) {
                    throw new JsonRpcException("INVALID_TOKEN");
                }
                if ($user["activated"]) {
                    throw new JsonRpcException("USER_ALREADY_EXISTS");
                }
                if (!is_null($this->getUser($username, null, false))) {
                    throw new JsonRpcException("USER_ALREADY_EXISTS");
                }
                $deleteToken = true;
                $user["username"] = $username;
            }
        }
        $identityKey = array("ecc" => $keystore["keystore"]->getPrimaryKey()->keyPair);
        $identityKey["base58"] = ECUtils::publicToBase58DER($identityKey["ecc"]);
        $data = hash("sha256", "createUser" . $username . $pin . $token . $srpSalt["hex"] . $srpVerifier["hex"] . $identityKey["base58"], true);
        if (!ECUtils::verifySignature($identityKey["ecc"], $signature["bin"], $data)) {
            throw new JsonRpcException("INVALID_SIGNATURE");
        }
        
        if (isset($user["email"])) {
            if ($email) {
                $user["description"] = (isset($user["description"]) && $user["description"] != "" ? $user["description"] . " " : "") . $user["email"];
                $user["email"] = $email;
            }
        }
        else {
            $user["email"] = $email;
        }
        if (isset($user["notificationsEntry"]) && $email) {
            $user["notificationsEntry"] = array("enabled" => true, "email" => $email, "tags" => array());
        }
        $pkiName = $this->getPkiName($username, $host);
        if ($keystore["keystore"]->getPrimaryUserId() != $pkiName) {
            throw new JsonRpcException("INVALID_IDENTITY_KEY");
        }
        try {
            $this->pki->insertKeyStore("user:" . $pkiName, $keystore["keystore"], $kis["signature"]);
        }
        catch (\Exception $e) {
            if ($e->getMessage() == "exists") {
                throw new JsonRpcException("USER_ALREADY_EXISTS");
            }
            $this->logger->error("PKI insert error - " . $e->getMessage());
            $this->logger->debug("Trace:\n" . $e->getTraceAsString());
            throw new JsonRpcException("INVALID_IDENTITY_KEY");
        }
        
        $user["activated"] = true;
        $user["srpSalt"] = $srpSalt["hex"];
        $user["srpVerifier"] = $srpVerifier["hex"];
        $user["privData"] = $privData["base64"];
        $user["loginData"] = $loginData;
        $user["hosts"] = array($host);
        $user["noSinks"] = true;
        $user["registrationDate"] = Utils::timeMili()->toDec();
        $user["language"] = $language;
        $user["dataVersion"] = $dataVersion;
        $user["weakPassword"] = $weakPassword;
        $user["cachedPkiEntry"] = $this->getCachedPkiEntry($keystore);
        
        if ($exists) {
            $db->replace($user["username"], json_encode($user));
        }
        else {
            $db->insert($user["username"], json_encode($user));
        }
        if ($deleteToken) {
            $db->delete($token);
        }
        
        $usersCount = $this->getUsersCount($db);
        $isAdmin = isset($user["isAdmin"]) && $user["isAdmin"] === true;
        if ($usersCount == 1 && $isAdmin) {
            $this->settings->setSetting(User::FIRST_ADMIN_SETTINGS_KEY, $user["username"]);
            $initData = $this->settings->getSetting("initData");
            $initData = is_null($initData) ? array() : json_decode($initData, true);
            if (isset($initData["defaultLang"]) && isset($initData["langs"]) && isset($initData["langs"][$user["language"]])) {
                $lang = $initData["langs"][$user["language"]];
                array_push($lang, array(
                    "type" => "addContact",
                    "hashmail" => $user["username"] . "#" . $host
                ));
                $initData = array(
                    "defaultLang" => $user["language"],
                    "langs" => array(
                        $user["language"] => $lang
                    )
                );
                $this->settings->setSetting("initData", json_encode($initData));
            }
        }
        $this->eventManager->newUser($usersCount, $user["username"], $host, $user["language"], $isAdmin);
        
        return "OK";
    }
    
    public function registerInPKI($username, $keystore, $kis) {
        $db = $this->dbManager->getDbForWrite("user");
        $user = $this->getUser($username, null, false);
        $identityKey = ECUtils::publicToBase58DER($keystore["keystore"]->getPrimaryKey()->keyPair);
        $this->compareIdentityKeyWithUser($user, $identityKey);
        $pkiName = $this->getPkiName($username, $user["hosts"][0]);
        if ($keystore["keystore"]->getPrimaryUserId() != $pkiName) {
            throw new JsonRpcException("INVALID_IDENTITY_KEY");
        }
        try {
            $this->pki->insertKeyStore("user:" . $pkiName, $keystore["keystore"], $kis["signature"]);
            if (!isset($user["cachedPkiEntry"])) {
                $user["cachedPkiEntry"] = array();
            }
            $user["cachedPkiEntry"] = $this->getCachedPkiEntry($keystore);
            $db->replace($user["username"], json_encode($user));
        }
        catch (\Exception $e) {
            if ($e->getMessage() == "exists") {
                throw new JsonRpcException("USER_ALREADY_EXISTS");
            }
            $this->logger->error("PKI insert error - " . $e->getMessage());
            $this->logger->debug("Trace:\n" . $e->getTraceAsString());
            throw new JsonRpcException("INVALID_IDENTITY_KEY");
        }
        return "OK";
    }
    
    public function getUsersPresence($usernames, $host, $pub58, $nonce, $timestamp, $signature) {
        $this->nonce->nonceCheck("userPresence" . implode(",", $usernames), $pub58, $nonce, $timestamp, $signature);
        $res = array();
        foreach ($usernames as $username) {
            $presence = $this->getUserPresence($username, $host, $pub58);
            if ($presence !== false) {
                $res[$username] = $presence;
            }
        }
        return $res;
    }
    
    private function getUserPresence($username, $host, $pub58) {
        $user = $this->getUser($username, $host, true);
        if (is_null($user)) {
            return false;
        }
        if (!isset($user["presence"])) {
            return false;
        }
        $presence = $user["presence"];
        $timestamp = new BigInteger($presence["entry"]["timestamp"]);
        $elapsedTime = Utils::timeMili()->sub($timestamp);
        if ($elapsedTime->cmp($this->config->getMaxUserPresenceAge()) > 0) {
            return false;
        }
        $acl = $presence["acl"];
        if ($acl["type"] == "noone") {
            return false;
        }
        if ($acl["type"] == "whitelist") {
            if (!in_array($pub58["base58"], $acl["pubs"])) {
                return false;
            }
        }
        return array("signature" => $presence["signature"], "entry" => $presence["entry"]);
    }
    
    public function getKeystore($username) {
        $pkiName = $this->getPkiName($username, $this->config->getHosts()[0]);
        $message = $this->pki->getKeyStore("user:" . $pkiName, null, null, true);
        return $message->getKeyStore();
    }
    
    public function getUserKeystoreInfo($username) {
        $user = $this->getUser($username, null, false);
        $hashmail = $username . "#" . ($user ? $user["hosts"][0] : $this->config->getHosts()[0]);
        $keystore = $this->getKeystore($username);
        $userImgBuf = null; $userImgUrl = null; $userInfoBuf = null; $userInfo = null; $userDisplayName = null;
        if ($keystore != null) {
            $userImgBuf = $keystore->getAttachment("image");
            if ($userImgBuf) {
                $userImgUrl = 'data:image/png;base64,' . base64_encode($userImgBuf);
            }
            $userInfoBuf = $keystore->getAttachment("info");
            if ($userInfoBuf) {
                $userInfo = json_decode($userInfoBuf, true);
                if ($userInfo) {
                    $userDisplayName = isset($userInfo["name"]) ? $userInfo["name"] : null;
                }
            }
        }
        return array(
            "user" => $user,
            "username" => $username,
            "hashmail" => $hashmail,
            "keystore" => $keystore,
            "imgBuf" => $userImgBuf,
            "imgUrl" => $userImgUrl,
            "infoBuf" => $userInfoBuf,
            "info" => $userInfo,
            "displayName" => $userDisplayName
        );
    }
    
    //========================
    //        SAFE RPC
    //========================
    
    public function getMyData($username) {
        $user = $this->getUser($username, null, false);
        if (is_null($user)) {
            throw new JsonRpcException("USER_DOESNT_EXIST");
        }
        $result = array(
            "username" => $user["username"],
            "isAdmin" => isset($user["isAdmin"]) ? $user["isAdmin"] : false,
            "secureFormsEnabled" => isset($user["secureFormsEnabled"]) ? $user["secureFormsEnabled"] : false,
            "contactFormEnabled" => isset($user["contactFormEnabled"]) ? $user["contactFormEnabled"] : false,
            "plugins" => array(),
        );
        if (isset($user["presence"])) {
            $result["presence"] = $user["presence"];
        }
        if (isset($user["notificationsEntry"])) {
            $result["notificationsEntry"] = $user["notificationsEntry"];
        }

        $pkiName = $this->getPkiName($username, $this->config->getHosts()[0]);
        $message = $this->pki->getKeyStore("user:" . $pkiName, null, null, true);
        $keystore = $message->getKeyStore();
        $result["keystore"] = $keystore ? $keystore->encode() : null;

        foreach ($this->plugins as $plugin) {
            array_push($result["plugins"], $plugin->getName());
        }
        
        return $result;
    }
    
    public function setUserPreferences($username, $language, $notificationsEntry, $contactFormSid) {
        $db = $this->dbManager->getDbForWrite("user");
        $user = $this->getUser($username, null, false);
        if (is_null($user)) {
            throw new JsonRpcException("USER_DOESNT_EXIST");
        }
        $user["language"] = $language;
        $user["notificationsEntry"] = $notificationsEntry;
        if (is_null($contactFormSid)) {
            unset($user["contactFormSid"]);
        }
        else {
            $user["contactFormSid"] = $contactFormSid["base58"];
        }
        $db->replace($user["username"], json_encode($user));
        return "OK";
    }
    
    public function getCachedPkiEntry($keystore) {
        $identityKey = array("ecc" => $keystore["keystore"]->getPrimaryKey()->keyPair);
        $res = array("primaryKey" => ECUtils::publicToBase58DER($identityKey["ecc"]));
        if (isset($keystore["attachments"]["image"])) {
            $res["image"] = base64_encode($keystore["attachments"]["image"]);
        }
        if (isset($keystore["attachments"]["info"])) {
            $info = $keystore["attachments"]["info"];
            if (isset($info["name"])) {
                $res["name"] = $info["name"];
            }
            if (isset($info["description"])) {
                $res["description"] = $info["description"];
            }
            if (isset($info["sinks"])) {
                $sinks = array();
                foreach ($info["sinks"] as $sink) {
                    $rSink = array("id" => $sink["id"]["base58"]);
                    if (isset($sink["name"])) {
                        $rSink["name"] = $sink["name"];
                    }
                    if (isset($sink["description"])) {
                        $rSink["description"] = $sink["description"];
                    }
                    array_push($sinks, $rSink);
                }
                $res["sinks"] = $sinks;
            }
        }
        return $res;
    }
    
    public function setUserInfo($username, $keystore, $kis) {
        $db = $this->dbManager->getDbForWrite("user");
        $user = $this->getUser($username, null, false);
        $identityKey = ECUtils::publicToBase58DER($keystore["keystore"]->getPrimaryKey()->keyPair);
        $this->compareIdentityKeyWithUser($user, $identityKey);
        $pkiName = $this->getPkiName($username, $user["hosts"][0]);
        if ($keystore["keystore"]->getPrimaryUserId() != $pkiName) {
            throw new JsonRpcException("INVALID_IDENTITY_KEY");
        }
        $msg = $this->pki->updateKeyStore("user:" . $pkiName, $keystore["keystore"], $kis["signature"]);
        $user["cachedPkiEntry"] = $this->getCachedPkiEntry($keystore);
        $db->replace($user["username"], json_encode($user));
        return $msg;
    }
    
    public function getUserPrimaryKey($user) {
        if (isset($user["cachedPkiEntry"]) && isset($user["cachedPkiEntry"]["primaryKey"])) {
            return $user["cachedPkiEntry"]["primaryKey"];
        }
        if (isset($user["identityKey"])) {
            return $user["identityKey"];
        }
        return null;
    }
    
    public function getUserPrimaryKeyWithCheck($user) {
        $key = $this->getUserPrimaryKey($user);
        if (is_null($key)) {
            throw new JsonRpcException("INVALID_IDENTITY_KEY");
        }
        return $key;
    }
    
    public function compareIdentityKeyWithUser($user, $identityKey) {
        $key = $this->getUserPrimaryKey($user);
        if (!is_null($key) && $key !== $identityKey) {
            throw new JsonRpcException("INVALID_IDENTITY_KEY");
        }
    }
    
    public function setUserPresence($username, $presence, $acl, $signature) {
        $db = $this->dbManager->getDbForWrite("user");
        $user = $this->getUser($username, null, false);
        if (is_null($user)) {
            throw new JsonRpcException("USER_DOESNT_EXIST");
        }
        if (!$this->nonce->validateTimestamp($presence["timestamp"])) {
            throw new JsonRpcException("INVALID_USER_PRESENCE");
        }
        $rawPresence = array("status" => $presence["status"], "timestamp" => $presence["timestamp"]["dec"]);
        $data = hash("sha256", json_encode($rawPresence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
        $pub = ECUtils::publicFromBase58DER($this->getUserPrimaryKeyWithCheck($user));
        if (!ECUtils::verifySignature($pub, $signature["bin"], $data)) {
            throw new JsonRpcException("INVALID_SIGNATURE");
        }
        $rawAcl = array("type" => $acl["type"]);
        if (isset($acl["pubs"])) {
            $rawAcl["pubs"] = array();
            foreach ($acl["pubs"] as $pub) {
                array_push($rawAcl["pubs"], $pub["base58"]);
            }
        }
        $user["presence"] = array(
            "entry" => $rawPresence,
            "acl" => $rawAcl,
            "signature" => $signature["base64"],
        );
        $db->replace($user["username"], json_encode($user));
        return "OK";
    }
    
    public function invite($username) {
        if (!$this->config->isInvitationEnabled()) {
            throw new JsonRpcException("INVITATION_DISABLED");
        }
        $user = $this->getUser($username, null, false);
        if (is_null($user)) {
            throw new JsonRpcException("USER_DOESNT_EXIST");
        }
        return $this->generateInvitations($username, 1, "", "")[0]["token"];
    }
    
    public function setCredentials($username, $srpSalt, $srpVerifier, $privData, $loginData, $dataVersion, $weakPassword) {
        $db = $this->dbManager->getDbForWrite("user");
        $user = $this->getUser($username, null, false);
        if (is_null($user)) {
            throw new JsonRpcException("USER_DOESNT_EXIST");
        }
        $user["srpSalt"] = $srpSalt["hex"];
        $user["srpVerifier"] = $srpVerifier["hex"];
        $user["privData"] = $privData["base64"];
        $user["loginData"] = $loginData;
        $user["dataVersion"] = $dataVersion;
        $user["weakPassword"] = $weakPassword;
        $db->replace($user["username"], json_encode($user));
        return "OK";
    }
    
    public function getInitData($username) {
        $user = $this->getUser($username, null, false);
        if (is_null($user)) {
            throw new JsonRpcException("USER_DOESNT_EXIST");
        }
        $initDataKey = isset($user["initDataKey"]) ? $user["initDataKey"] : "";
        $key = "initData" . $initDataKey;
        $data = $this->settings->getSettingForLanguage($key, $user, array());
        if (! empty($data)) {
          $initData = $this->settings->getObject("initData");
          if (!empty($initData["mailsDisabled"])) {
            $filteredData = array();
            foreach($data as $v) {
              if (empty($v["type"]) || $v["type"] != "sendMail") {
                $filteredData[] = $v;
              }
            }
            $data = $filteredData;
          }
        }
        $tmp = $this->callbacks->trigger("getInitData", array($username));
        foreach ($tmp as $t) {
            if (gettype($t) == "array") {
                $data = array_merge($data, $t);
            }
        }
        return $data;
    }
    
    public function getPrivData($username) {
        $user = $this->getUser2($username, null, false);
        if (is_null($user)) {
            throw new JsonRpcException("USER_DOESNT_EXIST");
        }
        return isset($user["privData"]) ? $user["privData"] : null;
    }
    
    public function getRecoveryData($username) {
        $user = $this->getUser($username, null, false);
        if (is_null($user)) {
            throw new JsonRpcException("USER_DOESNT_EXIST");
        }
        return isset($user["recoveryData"]) ? $user["recoveryData"] : null;
    }
    
    public function setRecoveryData($username, $privData, $recoveryData, $dataVersion) {
        $db = $this->dbManager->getDbForWrite("user");
        $user = $this->getUser($username, null, false);
        if (is_null($user)) {
            throw new JsonRpcException("USER_DOESNT_EXIST");
        }
        $user["privData"] = $privData["base64"];
        $user["dataVersion"] = $dataVersion;
        $user["recoveryData"] = array("pub" => $recoveryData["pub"]["base58"], "data" => $recoveryData["data"]["base64"]);
        $db->replace($user["username"], json_encode($user));
        return "OK";
    }
    
    public function setPrivData($username, $privData, $dataVersion) {
        $db = $this->dbManager->getDbForWrite("user");
        $user = $this->getUser($username, null, false);
        if (is_null($user)) {
            throw new JsonRpcException("USER_DOESNT_EXIST");
        }
        $user["privData"] = $privData["base64"];
        $user["dataVersion"] = $dataVersion;
        $db->replace($user["username"], json_encode($user));
        return "OK";
    }
    
    public function setContactFormEnabled($username, $enabled) {
        return $this->changeContactFormEnabled($username, $enabled);
    }
    
    //========================
    //     SAFE RPC ADMIN
    //========================
    
    public function getUsers() {
        $db = $this->dbManager->getDbForRead("user");
        $users = array();
        $key = $db->firstkey();
        while ($key !== false) {
            array_push($users, json_decode($db->fetch($key), true));
            $key = $db->nextkey();
        }
        return $users;
    }
    
    public function getUserEx($username) {
        $user = $this->getUser($username, null, false);
        if (is_null($user)) {
            throw new JsonRpcException("USER_DOESNT_EXIST");
        }
        return $user;
    }
    
    public function removeUser($executor, $username) {
        if ($executor == $username) {
            throw new JsonRpcException("ACCESS_DENIED");
        }
        $db = $this->dbManager->getDbForWrite("user");
        if ($db->exists($username)) {
            $db->delete($username);
        }
        else {
            throw new JsonRpcException("USER_DOESNT_EXIST");
        }
        $this->dbManager->closeDb($db);
        //Removing sinks
        $db = $this->dbManager->getDbForWrite("sink");
        $toDelete = array();
        $key = $db->firstkey();
        while ($key !== false) {
            $json = json_decode($db->fetch($key), true);
            if ($json["owner"] == $username) {
                array_push($toDelete, $key);
            }
            $key = $db->nextkey();
        }
        foreach ($toDelete as $sid) {
            $db->delete($sid);
            $this->dbManager->removeDbFile($sid);
        }
        $this->dbManager->closeDb($db);
        return "OK";
    }
    
    private function userExists($db, $username) {
        if ($db->exists($username)) {
            return true;
        }
        $hosts = $this->config->getHosts();
        foreach ($hosts as $host) {
            if ($this->pki->exists("user:" . $this->getPkiName($username, $host))) {
                return true;
            }
        }
        return false;
    }
    
    public function addUser($username, $pin) {
        $db = $this->dbManager->getDbForWrite("user");
        $this->checkUsersCount($db, 1);
        if ($this->userExists($db, $username)) {
            throw new JsonRpcException("USER_ALREADY_EXISTS");
        }
        $user = array(
            "username" => $username,
            "pin" => $pin,
            "activated" => false,
            "pinAttemptsCount" => 0,
            "invitationCount" => 0
        );
        $db->insert($user["username"], json_encode($user));
        return "OK";
    }
    
    public function getConfig() {
        return array(
            "maxDescriptorBulkSize" => $this->config->getMaxDescriptorBulkSize(),
            "maxBlockBulkSize" => $this->config->getMaxBlockBulkSize(),
            "maxMessageBulkSize" => $this->config->getMaxMessageBulkSize(),
            "maxUserBulkSize" => $this->config->getMaxUserBulkSize(),
            "maxBlocksCount" => $this->config->getMaxBlocksCount(),
            "maxBlockLength" => $this->config->getMaxBlocksLength(),
            "maxCosigners" => $this->config->getMaxCosigners()
        );
    }
    
    public function getUserConfig() {
        $res = array(
            "customMenuItems" => $this->config->getCustomMenuItems(),
            "talkUrl" => $this->config->getTalkUrl(),
            "userContactFormUrl" => $this->config->getUserContactFormUrl()
        );
        $tmp = $this->callbacks->trigger("getUserConfig");
        foreach ($tmp as $t) {
            $res = array_merge($res, $t);
        }
        return $res;
    }
    
    public function getConfigEx() {
        $size = Utils::getDirectorySize($this->config->getDataDirectory());
        $phpBits = PHP_INT_SIZE == 4 ? "32" : '64';
        $bi = new BigInteger();
        return array(
            "hosts" => $this->config->getHosts(),
            "instanceUrl" => $this->config->getInstanceUrl(),
            "defaultInvitationLinkPattern" => $this->config->getDefaultInvitationLinkPattern(),
            "maxPinAttemptsCount" => $this->config->getMaxPinAttemptsCount(),
            "databaseEngine" => $this->config->getDatabaseEngine(),
            "dataDirectory" => $this->config->getDataDirectory(),
            "serverVersion" => $this->config->getServerVersion(),
            "diskUsage" => $size,
            "cronEnabled" => $this->config->isCronEnabled(),
            "garbageCollectorEnabled" => $this->config->isGarbageCollectorEnabled(),
            "garbageCollectorInterval" => (new BigInteger($this->config->getGarbageCollectorInterval()))->toDec(),
            "notifierEnabled" => $this->config->isNotifierEnabled(),
            "notifierInterval" => (new BigInteger($this->config->getNotifierInterval()))->toDec(),
            "minNotifierDelay" => (new BigInteger($this->config->getMinNotifierDelay()))->toDec(),
            "notifierRenewTime" => (new BigInteger($this->config->getNotifierRenewTime()))->toDec(),
            "notifierRenewMode" => (new BigInteger($this->config->getNotifierRenewMode()))->toDec(),
            "maxCosigners" => $this->config->getMaxCosigners(),
            "phpVersion" => phpversion() . " {$phpBits}bit",
            "mathLib" => S_MATH_BIGINTEGER_MODE
        );
    }
    
    public function changePin($username, $pin) {
        $db = $this->dbManager->getDbForWrite("user");
        $user = $this->getUser($username, null, false);
        if (is_null($user)) {
            throw new JsonRpcException("USER_DOESNT_EXIST");
        }
        if ($user["activated"] == true) {
            throw new JsonRpcException("USER_ALREADY_ACTIVATED");
        }
        $user["pin"] = $pin;
        $user["pinAttemptsCount"] = 0;
        $db->replace($user["username"], json_encode($user));
        return "OK";
    }
    
    public function changeEmail($username, $email) {
        $db = $this->dbManager->getDbForWrite("user");
        $user = $this->getUser($username, null, false);
        if (is_null($user)) {
            throw new JsonRpcException("USER_DOESNT_EXIST");
        }
        $user["email"] = $email;
        if ($user["activated"] == false && isset($user["notificationsEntry"])) {
            $user["notificationsEntry"] = array("enabled" => !!$email, "email" => $email, "tags" => array());
        }
        $db->replace($user["username"], json_encode($user));
        return "OK";
    }
    
    public function changeDescription($username, $description) {
        $db = $this->dbManager->getDbForWrite("user");
        $user = $this->getUser($username, null, false);
        if (is_null($user)) {
            throw new JsonRpcException("USER_DOESNT_EXIST");
        }
        $user["description"] = $description;
        $db->replace($user["username"], json_encode($user));
        return "OK";
    }
    
    public function changeContactFormEnabled($username, $enabled) {
        $db = $this->dbManager->getDbForWrite("user");
        $user = $this->getUser($username, null, false);
        if (is_null($user)) {
            throw new JsonRpcException("USER_DOESNT_EXIST");
        }
        $user["contactFormEnabled"] = $enabled;
        $db->replace($user["username"], json_encode($user));
        return "OK";
    }
    
    public function changeSecureFormsEnabled($username, $enabled) {
        $db = $this->dbManager->getDbForWrite("user");
        $user = $this->getUser($username, null, false);
        if (is_null($user)) {
            throw new JsonRpcException("USER_DOESNT_EXIST");
        }
        $user["secureFormsEnabled"] = $enabled;
        $db->replace($user["username"], json_encode($user));
        return "OK";
    }
    
    public function changeIsAdmin($executor, $username, $isAdmin, $data, $kis) {
        if ($executor == $username) {
            throw new JsonRpcException("ACCESS_DENIED");
        }
        $db = $this->dbManager->getDbForWrite("user");
        $user = $this->getUser($username, null, false);
        if (is_null($user)) {
            throw new JsonRpcException("USER_DOESNT_EXIST");
        }
        $users = $this->getUsers();
        $userKeys = array();
        foreach ($users as $u) {
            if ($u["isAdmin"] && $u["username"] != $username) {
                array_push($userKeys, $this->getUserPrimaryKey($u));
            }
        }
        if ($isAdmin) {
            array_push($userKeys, $this->getUserPrimaryKey($user));
        }
        if (!$this->validatePkiDocument($data["data"], $userKeys)) {
            throw new JsonRpcException("INVLIAD_PKI_DOCUMENT");
        }
        $msg = $this->pki->updateKeyStore($this->getPkiAdminEntryName(), $data["data"], $kis["signature"]);
        $this->settings->setSetting("cosigners", $data["data"]->getAttachment("cosigners"));
        $user["isAdmin"] = $isAdmin;
        $db->replace($user["username"], json_encode($user));
        return $msg;
    }
    
    public function changeUserData($executor, $username, $data) {
        if (isset($data["email"])) {
          $this->changeEmail($username, $data["email"]);
        }
        if (isset($data["description"])) {
          $this->changeDescription($username, $data["description"]);
        }
        if (isset($data["contactFormEnabled"])) {
          $this->changeContactFormEnabled($username, $data["contactFormEnabled"]);
        }
        if (isset($data["secureFormsEnabled"])) {
          $this->changeSecureFormsEnabled($username, $data["secureFormsEnabled"]);
        }
        return "OK";
    }
    
    public function addUserWithToken($creator, $username, $email, $description, $sendActivationLink, $notificationEnabled, $language, $linkPattern) {
        $db = $this->dbManager->getDbForWrite("user");
        $this->checkUsersCount($db, 1);
        if ($this->userExists($db, $username)) {
            throw new JsonRpcException("USER_ALREADY_EXISTS");
        }
        
        $inviter = $this->getUser($creator, null, false);
        if (is_null($inviter)) {
            throw new JsonRpcException("USER_DOESNT_EXIST");
        }
        
        $invitation = $this->generateInvitation($db, $inviter["username"], $description, $linkPattern);
        if ($email) {
            $invitation["user"]["email"] = $email;
            $invitation["user"]["notificationsEntry"] = array("enabled" => $notificationEnabled, "email" => $email, "tags" => array());
        }
        if ($username) {
            $invitation["user"]["username"] = $username;
            $invitation["user"]["token"] = $invitation["token"];
        }
        $invitation["user"]["language"] = $language;
        $db->insert($invitation["user"]["username"], json_encode($invitation["user"]));
        
        if (!is_null($inviter)) {
            $inviter["invitationsCount"] = isset($inviter["invitationsCount"]) ? $inviter["invitationsCount"] + 1 : 1;
            $db->replace($inviter["username"], json_encode($inviter));
        }
        $linkSent = 1;
        if ($sendActivationLink) {
            $config = $this->settings->getSettingForLanguage("invitationMail", $invitation["user"], false);
            if ($config === false) {
                $this->logger->error("Cannot send invitation mail to '{$email}' - invalid config");
                $linkSent = 2;
            }
            else {
                $body = str_replace("{link}", $invitation["link"], $config["body"]);
                $from = array(
                    "name" => $config["from"]["name"],
                    "email" => $this->config->getServerEmail()
                );
                if ($this->mailService->send($from, $email, $config["subject"], $body, $config["isHtml"])) {
                    $this->logger->debug("Successfully sent invitation mail to '{$email}'");
                    $linkSent = 0;
                }
                else {
                    $this->logger->error("Cannot send invitation mail to '{$email}' - unknown error");
                    $linkSent = 3;
                }
            }
        }
        return array(
            "token" => $invitation["token"],
            "link" => $invitation["link"],
            "linkSent" => $linkSent
        );
    }
    
    private function generateInvitation($db, $inviter, $description, $linkPattern) {
        do {
            $token = uniqid("", true);
        }
        while ($db->exists($token));
        
        $link = str_replace("{token}", $token, $linkPattern);
        $user = array(
            "username" => $token,
            "pin" => "",
            "activated" => false,
            "pinAttemptsCount" => 0,
            "invitedBy" => $inviter,
            "invitationCount" => 0,
            "description" => $description,
            "invitationLink" => $link
        );
        return array(
            "user" => $user,
            "token" => $token,
            "link" => $link
        );
    }
    
    public function generateAdminInvitation($inviter, $description, $linkPattern, $initDataKey = "", $email = "") {
        $db = $this->dbManager->getDbForWrite("user");
        $this->checkUsersCount($db, 1);
        $invitation = $this->generateInvitation($db, $inviter, $description, $linkPattern);
        $invitation["user"]["isAdmin"] = true;
        $invitation["user"]["initDataKey"] = $initDataKey;
        if ($email) {
            $invitation["user"]["email"] = $email;
            $invitation["user"]["notificationsEntry"] = array("enabled" => true, "email" => $email, "tags" => array());
        }
        $db->insert($invitation["user"]["username"], json_encode($invitation["user"]));
        return array(
            "token" => $invitation["token"],
            "link" => $invitation["link"]
        );
    }
    
    public function generateInvitations($username, $count, $description, $linkPattern) {
        $db = $this->dbManager->getDbForWrite("user");
        $this->checkUsersCount($db, $count);
        $inviter = $this->getUser($username, null, false);
        if (is_null($inviter)) {
            throw new JsonRpcException("USER_DOESNT_EXIST");
        }
        $tokens = array();
        for ($i = 0; $i < $count; $i++) {
            $invitation = $this->generateInvitation($db, $inviter["username"], $description, $linkPattern);
            $db->insert($invitation["user"]["username"], json_encode($invitation["user"]));
            array_push($tokens, array(
                "token" => $invitation["token"],
                "link" => $invitation["link"]
            ));
        }
        
        if (!is_null($inviter)) {
            $inviter["invitationsCount"] = isset($inviter["invitationsCount"]) ? $inviter["invitationsCount"] + $count : $count;
            $db->replace($inviter["username"], json_encode($inviter));
        }
        
        return $tokens;
    }
    
    public function getFullInitData($key = "") {
        $initData = $this->settings->getSetting("initData" . $key);
        return is_null($initData) ? (object)array() : json_decode($initData, true);
    }
    
    public function setInitData($data, $key = "") {
        $raw = array("defaultLang" => $data["defaultLang"], "langs" => array());
        foreach ($data["langs"] as $langName => $lang) {
            $rawLang = array();
            foreach ($lang as $entry) {
                if ($entry["type"] == "addContact") {
                    array_push($rawLang, $entry);
                }
                else if ($entry["type"] == "addFile") {
                    $file = array("type" => $entry["type"], "name" => $entry["name"], "content" => $entry["content"]["base64"]);
                    if (isset($entry["mimetype"])) {
                        $file["mimetype"] = $entry["mimetype"];
                    }
                    array_push($rawLang, $file);
                }
                else if ($entry["type"] == "sendMail") {
                    $mail = array("type" => $entry["type"], "subject" => $entry["subject"], "content" => $entry["content"]["base64"], "attachments" => array());
                    foreach ($entry["attachments"] as $attachment) {
                        array_push($mail["attachments"], array(
                            "name" => $attachment["name"],
                            "mimetype" => $attachment["mimetype"],
                            "content" => $attachment["content"]["base64"]
                        ));
                    }
                    array_push($rawLang, $mail);
                }
            }
            $raw["langs"][$langName] = $rawLang;
        }
        if (!empty($data["mailsDisabled"])) {
          $raw["mailsDisabled"] = true;
        }
        $this->settings->setSetting("initData" . $key, json_encode($raw));
        return "OK";
    }
    
    public function getNotifierConfig() {
        $config = $this->settings->getSetting("notifier");
        return is_null($config) ? (object)array() : json_decode($config, true);
    }
    
    public function setNotifierConfig($config) {
        $this->settings->setSetting("notifier", json_encode($config));
        return "OK";
    }
    
    public function getInvitationMailConfig() {
        $config = $this->settings->getSetting("invitationMail");
        return is_null($config) ? (object)array() : json_decode($config, true);
    }
    
    public function setInvitationMailConfig($config) {
        $this->settings->setSetting("invitationMail", json_encode($config));
        return "OK";
    }
    
    public function getLoginsPage($beg, $end) {
        return $this->loginsLog->getPage($beg, $end);
    }
    
    public function getLastLogins($count) {
        return $this->loginsLog->getLast($count);
    }
    
    public function getPkiAdminEntryName() {
        return "admin:";
    }
    
    public function validatePkiDocument($data, $userKeys = null) {
        if (is_null($userKeys)) {
            $users = $this->getUsers();
            $userKeys = array();
            foreach ($users as $user) {
                if ($user["isAdmin"]) {
                    array_push($userKeys, $this->getUserPrimaryKey($user));
                }
            }
        }
        $pkiKeys = array();
        foreach ($data->keys as $key) {
            array_push($pkiKeys, ECUtils::publicToBase58DER($key->keyPair));
        }
        sort($userKeys, SORT_STRING);
        sort($pkiKeys, SORT_STRING);
        if (implode(",", $userKeys) != implode(",", $pkiKeys)) {
            return false;
        }
        return true;
    }
    
    public function setPkiDocument($name, $data, $kis) {
        if ($name != $this->getPkiAdminEntryName()) {
            throw new JsonRpcException("INVLIAD_PKI_DOCUMENT");
        }
        if (!$this->validatePkiDocument($data["data"])) {
            throw new JsonRpcException("INVLIAD_PKI_DOCUMENT");
        }
        $msg = $this->pki->insertOrUpdateKeyStore($name, $data["data"], $kis["signature"]);
        $this->settings->setSetting("cosigners", $data["data"]->getAttachment("cosigners"));
        return $msg;
    }
    
    //========================
    //      LOW USER
    //========================
    
    public function getLowUser($username, $activated = true) {
        $db = $this->dbManager->getDbForWrite("lowusers");
        if (!$db->exists($username)) {
            return null;
        }
        $user = json_decode($db->fetch($username), true);
        $this->dbManager->closeDb($db);
        return !$activated || $user["activated"] ? $user : null;
    }
    
    public function createLowUser($owner, $host) {
        $db = $this->dbManager->getDbForWrite("lowusers");
        do {
            $username = substr("lu" . hash("sha256", uniqid("", true)), 0, 16);
        }
        while ($db->exists($username));
        $db->insert($username, json_encode(array(
            "owner" => $owner,
            "username" => $username,
            "hosts" => array($host),
            "activated" => false,
            "registrationDate" => Utils::timeMili()->toDec()
        )));
        $this->dbManager->closeDb($db);
        return $username;
    }
    
    public function modifyLowUser($owner, $properties) {
        $db = $this->dbManager->getDbForWrite("lowusers");
        if (!$db->exists($properties["username"])) {
            throw new JsonRpcException("USER_DOESNT_EXIST");
        }
        $u = json_decode($db->fetch($properties["username"]), true);
        if ($u["owner"] != $owner) {
            throw new JsonRpcException("ACCESS_DENIED");
        }
        foreach ($properties as $key => $value) {
            $u[$key] = Validator::clean($value);
        }
        $db->replace($properties["username"], json_encode($u));
        $this->dbManager->closeDb($db);
        return "OK";
    }
    
    public function deleteLowUser($owner, $username) {
        $db = $this->dbManager->getDbForWrite("lowusers");
        if (!$db->exists($username)) {
            throw new JsonRpcException("USER_DOESNT_EXIST");
        }
        $u = json_decode($db->fetch($username), true);
        if ($u["owner"] != $owner) {
            throw new JsonRpcException("ACCESS_DENIED");
        }
        $db->delete($username);
        $this->dbManager->closeDb($db);
        return "OK";
    }
    
    public function isLowUser($username, $host, $pub58) {
        $db = $this->dbManager->getDbForRead("lowusers");
        $res = false;
        if ($db->exists($username)) {
            $u = json_decode($db->fetch($username), true);
            if ($u["activated"] && in_array($host, $u["hosts"], true) && isset($u["identityKey"]) && $u["identityKey"] == $pub58) {
                $res = true;
            }
        }
        $this->dbManager->closeDb($db);
        return $res;
    }
}
