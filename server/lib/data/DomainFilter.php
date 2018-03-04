<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\data;

use io\privfs\core\DbManager;
use io\privfs\core\Utils;

class DomainFilter {
    
    const DB_NAME = "blacklist";
    const MODE_ALLOW = "ALLOW";
    const MODE_DENY = "DENY";
    const MODE_SUGGEST = "SUGGEST";
    const MODE_DELETED = "DELETED";
    const SEQ_KEY = "seq";
    
    private $dbManager;
    
    public function __construct(DbManager $dbManager) {
        $this->dbManager = $dbManager;
    }
    
    //----------------------
    
    public static function getDomainDbId($domain) {
        return "domain/" . $domain;
    }
    
    public static function getEntryDbId($seq) {
        return "entry/" . $seq;
    }
    
    protected function getDbForRead() {
        return $this->dbManager->getDbForRead(static::DB_NAME);
    }
    
    protected function getDbForWrite() {
        return $this->dbManager->getDbForWrite(static::DB_NAME);
    }
    
    protected function closeDb() {
        $this->dbManager->closeDbByName(static::DB_NAME);
    }
    
    //----------------------
    
    public function getBlacklist() {
        $db = $this->getDbForRead();
        $res = array();
        $key = $db->firstkey();
        while ($key !== false) {
            if (Utils::startsWith($key, "domain/")) {
                $domain = substr($key, 7);
                $mode = $db->fetch($key);
                if (!isset($res[$domain])) {
                    $res[$domain] = array("mode" => "", "history" => array());
                }
                $res[$domain]["mode"] = $mode;
            }
            else if (Utils::startsWith($key, "entry/")) {
                $data = json_decode($db->fetch($key), true);
                if (!isset($res[$data["domain"]])) {
                    $res[$data["domain"]] = array("mode" => "", "history" => array());
                }
                array_push($res[$data["domain"]]["history"], array(
                    "mode" => $data["mode"],
                    "username" => $data["username"],
                    "time" => $data["time"]
                ));
            }
            $key = $db->nextkey();
        }
        $this->closeDb();
        return $res;
    }
    
    private function saveEntry($username, $domain, $mode, $setMode) {
        $db = $this->getDbForWrite();
        $seq = intval($db->getOrDefault(static::SEQ_KEY, "0"));
        
        $entry = array(
            "domain" => $domain,
            "mode" => $mode,
            "username" => $username,
            "time" => time()
        );
        $db->insert($this->getEntryDbId($seq), json_encode($entry));
        $db->update(static::SEQ_KEY, strval($seq + 1));
        $domainId = $this->getDomainDbId($domain);
        if ($db->exists($domainId)) {
            $oldMode = $db->fetch($domainId);
            if ($setMode || $oldMode == self::MODE_DELETED) {
                $db->replace($domainId, $mode);
            }
        }
        else {
            $db->insert($domainId, $mode);
        }
        $this->closeDb();
        return "OK";
    }
    
    public function setBlacklistEntry($username, $domain, $mode) {
        return $this->saveEntry($username, $domain, $mode, true);
    }
    
    public function suggestBlacklistEntry($username, $domain) {
        return $this->saveEntry($username, $domain, static::MODE_SUGGEST, false);
    }
    
    public function deleteBlacklistEntry($username, $domain) {
        return $this->saveEntry($username, $domain, static::MODE_DELETED, true);
    }
    
    public function isValidDomain($domain) {
        $db = $this->getDbForRead();
        $domainId = $this->getDomainDbId($domain);
        if (!$db->exists($domainId)) {
            $this->closeDb();
            return true;
        }
        $mode = $db->fetch($domainId);
        $this->closeDb();
        return $mode != static::MODE_DENY;
    }
}