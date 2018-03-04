<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\data;

use io\privfs\config\Config;
use io\privfs\core\Lock;
use io\privfs\core\Settings;
use io\privfs\core\DbManager;
use io\privfs\core\Utils;
use io\privfs\core\Mail;
use io\privfs\core\MailService;
use io\privfs\core\Executor;
use BI\BigInteger;

class Notifier extends Executor {
    
    public static $templates;
    protected $config;
    protected $dbManager;
    protected $mailService;
    
    public function __construct(Config $config, Lock $lock, Settings $settings,
        DbManager $dbManager, MailService $mailService) {
        
        parent::__construct($lock, $settings, "lastNotifierWorkTime", $config->isNotifierEnabled(), $config->getNotifierInterval());
        
        $this->config = $config;
        $this->dbManager = $dbManager;
        $this->mailService = $mailService;
    }
    
    protected function go() {
        $this->processUsers(false);
    }
    
    private function processUsers($instantMode) {
        $db = $this->dbManager->getDbForWrite("user");
        $key = $db->firstkey();
        while ($key !== false) {
            $user = $this->processUser(json_decode($db->fetch($key), true), $instantMode);
            if ($user !== false) {
                $db->replace($key, json_encode($user));
            }
            $key = $db->nextkey();
        }
        $this->dbManager->closeDb($db);
    }
    
    public function processUserByUsername($username, $instantMode) {
        $db = $this->dbManager->getDbForWrite("user");
        if ($db->exists($username)) {
            $user = $this->processUser(json_decode($db->fetch($username), true), $instantMode);
            if ($user !== false) {
                $db->replace($username, json_encode($user));
            }
        }
        $this->dbManager->closeDb($db);
    }
    
    private function processUser($user, $instantMode) {
        $this->logger->debug("processUser " . $user["username"]);
        if (!$user["activated"] || !isset($user["notificationsEntry"]) || $user["notificationsEntry"]["email"] == "" ||
            $user["notificationsEntry"]["enabled"] != true || count($user["notificationsEntry"]["tags"]) == 0 ||
            !isset($user["lastLoginDate"])) {
            
            $this->logger->debug("user has invalid notification config");
            return false;
        }
        $now = Utils::timeMili();
        $canResend = true;
        if (isset($user["lastNotification"])) {
            $lastNotification = new BigInteger($user["lastNotification"]);
            $elapsed = $now->sub($lastNotification);
            $canResend = $elapsed->cmp($this->config->getNotifierRenewTime()) > 0;
        }
        $this->logger->debug("notification can be resend - " . ($canResend ? "yes" : "no"));
        $resendMode = $this->config->getNotifierRenewMode();
        $db = $this->dbManager->getDbForRead("sink");
        $key = $db->firstkey();
        $newCount = 0;
        while ($key !== false) {
            $json = json_decode($db->fetch($key), true);
            if ($json["owner"] == $user["username"]) {
                $tags = $user["notificationsEntry"]["tags"];
                $ignoredDomains = isset($user["notificationsEntry"]["ignoredDomains"]) ?
                    $user["notificationsEntry"]["ignoredDomains"] : array();
                $newMids = $this->processSink($key, $tags, $ignoredDomains, $canResend, $resendMode, $instantMode);
                $newCount += count($newMids);
            }
            $key = $db->nextkey();
        }
        $this->dbManager->closeDb($db);
        if ($newCount > 0) {
            $this->logger->debug("new messages " . $newCount);
            $this->sendNotifyMail($user, $newCount);
            $user["lastNotification"] = $now->toDec();
            return $user;
        }
        else {
            $this->logger->debug("no notification");
        }
        return false;
    }
    
    private function processSink($sid, $tags, $ignoredDomains, $canBeResend, $resendMode, $instantMode) {
        $this->logger->debug("processSink " . $sid);
        $db = $this->dbManager->getDbForWrite($sid);
        $seq = intval($db->fetch(Sink::SEQ_KEY));
        if (!$db->exists(Sink::LAST_SEEN_SEQ_KEY)) {
            $db->insert(Sink::LAST_SEEN_SEQ_KEY, $seq);
            $this->dbManager->closeDb($db);
            $this->logger->debug("sink skipped - last seen seq is absent");
            return array();
        }
        $lastSeenSeq = intval($db->fetch(Sink::LAST_SEEN_SEQ_KEY));
        if ($seq == $lastSeenSeq) {
            $this->dbManager->closeDb($db);
            $this->logger->debug("sink skipped - last seen seq == seq " . $lastSeenSeq);
            return array();
        }
        $notifierSeq = $db->exists(Sink::LAST_NOTIFIER_SEQ_KEY) ? intval($db->fetch(Sink::LAST_NOTIFIER_SEQ_KEY)) : $lastSeenSeq;
        if ($notifierSeq > $lastSeenSeq) {
            if (!$canBeResend || $resendMode == 0 || ($resendMode == 1 && $seq <= $notifierSeq)) {
                $this->logger->debug("sink skipped - has new messages, but no need to resend");
                $this->dbManager->closeDb($db);
                return array();
            }
            $seqToCheck = $resendMode == 2 ? $lastSeenSeq : $notifierSeq;
        }
        else {
            $seqToCheck = $lastSeenSeq;
        }
        if ($seq == $seqToCheck) {
            $this->logger->debug("sink skipped - seq to check == seq " . $seqToCheck);
            $this->dbManager->closeDb($db);
            return array();
        }
        $all = Sink::getMids($db->fetch(Sink::MIDS_KEY));
        $newMids = array();
        $maxTime = Utils::timeMili()->sub($this->config->getMinNotifierDelay());
        foreach ($all as $mid) {
            if ($mid > $seqToCheck) {
                $meta = json_decode($db->fetch(Sink::getMessageMetaDbId($mid)), true);
                $createDate = new BigInteger($meta["timestamp"]);
                if ($instantMode === false && $maxTime->cmp($createDate) < 0) {
                    $this->logger->debug($mid . " message is too young, skipped");
                    continue;
                }
                if (!isset($meta["tags"])) {
                    $this->logger->debug($mid . " message has not tags, skipped");
                    continue;
                }
                $found = false;
                foreach ($tags as $tag) {
                     if (in_array($tag, $meta["tags"])) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $this->logger->debug($mid . " message has not one of required tags, skipped");
                    continue;
                }
                if (count($ignoredDomains) > 0) {
                    $msg = json_decode($db->fetch(Sink::getMessageDbId($mid)), true);
                    if (isset($msg["senderDomain"])) {
                        if (in_array($msg["senderDomain"], $ignoredDomains)) {
                            $this->logger->debug($mid . " message from ignored domain, skipped");
                            continue;
                        }
                    }
                }
                $this->logger->debug($mid . " message added");
                array_push($newMids, $mid);
            }
        }
        if (count($newMids) > 0) {
            $db->update(Sink::LAST_NOTIFIER_SEQ_KEY, $seq);
        }
        $this->dbManager->closeDb($db);
        if (count($newMids) == 0) {
            $this->logger->debug("sink skipped - any new message");
        }
        else {
            $this->logger->debug("finish - new messages " . count($newMids));
        }
        return $newMids;
    }
    
    private function sendNotifyMail($user, $newCount) {
        $config = $this->settings->getSettingForLanguage("notifier", $user, false);
        if ($config === false) {
            $this->logger->error("Cannot send mail to '{$user["username"]}' - invalid config");
            return;
        }
        $name = $user["username"];
        if (isset($user["entry"]) && isset($user["entry"]["profile"]) && isset($user["entry"]["profile"]["name"]) && strlen($user["entry"]["profile"]["name"]) > 0) {
            $name = $user["entry"]["profile"]["name"];
        }
        $from = array(
            "name" => $config["from"]["name"],
            "email" => $this->config->getServerEmail()
        );
        $to = array("name" => $name, "email" => $user["notificationsEntry"]["email"]);
        if ($this->mailService->send($from, $to, $config["subject"], $config["body"], $config["isHtml"])) {
            $this->logger->debug("Successfully sent mail to '{$user["username"]}'");
        }
        else {
            $this->logger->error("Cannot send mail to '{$user["username"]}' - unknown error");
        }
    }
    
    public function getSettingForLanguage($setting, $lang) {
        if (isset($setting["langs"][$lang])) {
            return $setting["langs"][$lang];
        }
        return $setting["langs"][$setting["defaultLang"]];
    }
    
    public function sendLowUserNotify($lowUser, $opts) {
        if (!isset($lowUser["language"]) || !isset($lowUser["notifier"]) || !isset($lowUser["notifier"]["email"])) {
            $this->logger->error("Cannot send mail to '{$lowUser["username"]}' - invalid config");
            return;
        }
        $config = $this->getSettingForLanguage(Notifier::$templates["lowUserNotify"], $lowUser["language"]);
        $body = $opts && isset($opts["body"]) ? $opts["body"] : $config["body"];
        $link = $this->config->getTalkUrl2($lowUser["username"]);
        $from = array(
            "name" => $opts && isset($opts["from"]) ? $opts["from"] : $config["from"]["name"],
            "email" => $this->config->getServerEmailNoReply()
        );
        $body = str_replace("{link}", $link, $body);
        $subject = $opts && isset($opts["subject"]) ? $opts["subject"] : $config["subject"];
        if ($this->mailService->send($from, $lowUser["notifier"]["email"], $subject, $body, $config["isHtml"])) {
            $this->logger->debug("Successfully sent mail to '{$lowUser["username"]}'");
        }
        else {
            $this->logger->error("Cannot send mail to '{$lowUser["username"]}' - unknown error");
        }
    }
}

Notifier::$templates = array(
    "lowUserNotify" => array(
        "defaultLang" => "en",
        "langs" => array(
            "en" => array(
                "from" => array(
                    "name" => "PrivMX"
                ),
                "isHtml" => false,
                "subject" => "[PrivMX] New message",
                "body" => "\nNew private message:\n{link}\n\n(this notification has been automatically generated by my PrivMX private mail server)"
            ),
            "pl" => array(
                "from" => array(
                    "name" => "PrivMX"
                ),
                "isHtml" => false,
                "subject" => "[PrivMX] Nowa wiadomość",
                "body" => "\nNowa prywatna wiadomość:\n{link}\n\n(to powiadomienie zostało wygenerowane automatycznie przez mój serwer prywatnej poczty PrivMX)"
            )
        )
    )
);
