<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\config;

use io\privfs\core\Utils;

class Config {
    
    private $privfsServerVersion = "0.13.8";
    
    private $hosts;
    private $serverUrl;
    private $contextPath;
    private $instanceUrl;
    private $crossDomainAjax;
    private $serverEmail;
    
    private $dataDirectory;
    private $databaseEngine;
    
    private $maxTimestampDifference;
    private $garbageCollectorInterval;
    private $notifierInterval;
    private $minNotifierDelay;
    private $notifierRenewTime;
    private $notifierRenewMode;
    private $instantNotificationEnabled;
    private $maxUserInactiveTime;
    
    private $openRegistrationEnabled;
    private $invitationEnabled;
    private $maxPinAttemptsCount;
    private $maxUnassignedBlockAge;
    private $maxUserPresenceAge;
    private $maxDescriptorBulkSize;
    private $maxBlockBulkSize;
    private $maxMessageBulkSize;
    private $maxUserBulkSize;
    private $keystore;
    private $eventHandlerProcessPath;
    private $emailRequired;
    
    private $demoMode;
    private $changeCredentialsEnabled;
    private $keyLoginEnabled;
    private $interServerCommunicationEnabled;
    private $registrationEnabled;
    
    private $maxBlocksCount;
    private $maxBlockLength;
    
    private $longPollingInterval;
    private $longPollingTimeout;
    private $longPollingDelay;
    private $lockTimeout;
    private $defaultInvitationLinkPattern;
    private $adminInvitationLinkPattern;
    
    private $maxUsersCount;
    private $loginBlocked;
    private $cronEnabled;
    
    private $applicationLogLevel;
    
    private $corsDomains;
    private $customMenuItems;
    private $publicProxy;
    
    public function __construct() {
        global $_PRIVMX_GLOBALS;
        $this->rawConfig = $_PRIVMX_GLOBALS["config"];
        
        $this->setRequiredVariable("hosts");
        $this->setRequiredVariable("serverUrl");
        $this->setRequiredVariable("contextPath");
        $this->setOptionalVariable("instanceUrl", Utils::concatUrl($this->serverUrl, $this->contextPath));
        $this->setOptionalVariable("crossDomainAjax", false);
        $this->setOptionalVariable("serverEmail", "privmxserver@" . $this->hosts[0]);
        $this->setOptionalVariable("serverEmailNoReply", "no-reply@" . $this->hosts[0]);
        
        $this->setRequiredVariable("dataDirectory");
        $this->setRequiredVariable("databaseEngine");

        $this->setOptionalVariable("ticketsDirectory", Utils::joinPaths($this->dataDirectory, "tickets"));
        // This is used by application
        $this->setOptionalVariable("ticketsTTL", 15 * 60); // 15 min, value is in seconds

        $this->setOptionalVariable("applicationLogDirectory", $this->dataDirectory);
        $this->setOptionalVariable("applicationLogFilename", "application.log");
        $this->setOptionalVariable("applicationLogLevel", $_PRIVMX_GLOBALS["logs"]["default"]);
        // This is used by application
        $this->setOptionalVariable("applicationLogPath", Utils::joinPaths($this->applicationLogDirectory, $this->applicationLogFilename));
        // setup logger factory config
        $_PRIVMX_GLOBALS["logs"]["default"] = $this->getApplicationLogLevel();
        $_PRIVMX_GLOBALS["logs"]["path"] = $this->getApplicationLogPath();

        $this->setOptionalVariable("maxTimestampDifference", 60 * 60 * 1000);
        $this->setOptionalVariable("garbageCollectorInterval", 60 * 60 * 1000);
        $this->setOptionalVariable("notifierInterval", 60 * 60 * 1000);
        $this->setOptionalVariable("minNotifierDelay", 15 * 60 * 1000);
        $this->setOptionalVariable("notifierRenewTime", 7 * 24 * 60 * 60 * 1000);
        $this->setOptionalVariable("notifierRenewMode", 0);
        $this->setOptionalVariable("instantNotificationEnabled", true);
        $this->setOptionalVariable("maxUserInactiveTime", 20 * 1000);
        
        $this->setOptionalVariable("openRegistrationEnabled", false);
        $this->setOptionalVariable("invitationEnabled", false);
        $this->setOptionalVariable("maxPinAttemptsCount", 3);
        $this->setOptionalVariable("maxUnassignedBlockAge", 24 * 60 * 60 * 1000);
        $this->setOptionalVariable("maxUserPresenceAge", 15 * 60 * 1000);
        $this->setOptionalVariable("maxDescriptorBulkSize", 100);
        $this->setOptionalVariable("maxMessageBulkSize", 100);
        $this->setOptionalVariable("maxBlockBulkSize", 100);
        $this->setOptionalVariable("maxUserBulkSize", 100);
        $this->setRequiredVariable("keys");
        if( file_exists($this->keys) )
            require_once($this->keys);
        if( is_array($_PRIVMX_GLOBALS["keys"]) )
            $this->rawConfig = array_merge($this->rawConfig, $_PRIVMX_GLOBALS["keys"]);
        $this->setRequiredVariable("keystore");
        $this->setRequiredVariable("symmetric");
        $this->setOptionalVariable("eventHandlerProcessPath", false);
        $this->setOptionalVariable("emailRequired", false);
        
        $this->setOptionalVariable("demoMode", false);
        $this->setOptionalVariable("changeCredentialsEnabled", !$this->demoMode);
        $this->setOptionalVariable("keyLoginEnabled", !$this->demoMode);
        $this->setOptionalVariable("interServerCommunicationEnabled", !$this->demoMode);
        $this->setOptionalVariable("registrationEnabled", !$this->demoMode);
        
        $this->setOptionalVariable("maxBlocksCount", 25 * 8); //One block is 128 KB, so 25*8 blocks is 25 MB
        $this->setOptionalVariable("maxBlockLength", 17 + 128 * 1024); //128 KB + 17 bytes for encryption header
        
        $this->setOptionalVariable("longPollingInterval", 0);
        $this->setOptionalVariable("longPollingTimeout", 0);
        $this->setOptionalVariable("longPollingDelay", 10 * 1000);
        $this->setOptionalVariable("lockTimeout", 5 * 60 * 1000);
        $this->setOptionalVariable("defaultInvitationLinkPattern", "");
        $this->setOptionalVariable("adminInvitationLinkPattern", "");
        
        $this->setOptionalVariable("maxUsersCount", -1);
        $this->setOptionalVariable("loginBlocked", false);
        $this->setOptionalVariable("cronEnabled", true);
        
        $this->setOptionalVariable("corsDomains", array());
        $this->setOptionalVariable("maxFormsExtraData", 4 * 1024); // 4kb
        
        $this->setOptionalVariable("forceHTTPS", false);
        $this->setOptionalVariable("verifySSLCertificates", true);
        
        $this->setOptionalVariable("maxCosigners", 3);
        $this->setOptionalVariableFromGlobal("customMenuItems", array());
        $this->setOptionalVariable("publicProxy", false);
    }

    private function ensureUrlProtocol($url) {
        if( Utils::startsWith($url, "https://") || empty($url) )
            return $url;

        $protocol = $this->isForceHttps() ? "https://" : "http://";
        if( Utils::startsWith($url, "http://") )
            $url = substr($url, 7);
        return $protocol . $url;
    }
    
    public function hasExplicitSetting($name) {
        return isset($this->rawConfig[$name]);
    }

    public function setRequiredVariable($name) {
        $this->{$name} = $this->rawConfig[$name];
    }
    
    public function setOptionalVariable($name, $default) {
        $this->{$name} = isset($this->rawConfig[$name]) ? $this->rawConfig[$name] : $default;
    }
    
    public function setOptionalVariableFromGlobal($name, $default) {
        global $_PRIVMX_GLOBALS;
        $this->{$name} = isset($_PRIVMX_GLOBALS[$name]) ? $_PRIVMX_GLOBALS[$name] : $default;
    }
    
    public function getEmailRequired() {
        return $this->emailRequired;
    }
    
    public function hasEventHandler() {
        return $this->eventHandlerProcessPath !== false;
    }
    
    public function getEventHandlerProcessPath() {
        return $this->eventHandlerProcessPath;
    }
    
    public function getHosts() {
        return $this->hosts;
    }
    
    public function getServerUrl() {
        return $this->ensureUrlProtocol(
            $this->serverUrl
        );
    }
    
    public function getContextPath() {
        return $this->contextPath;
    }
    
    public function getInstanceUrl() {
        return $this->ensureUrlProtocol(
            $this->instanceUrl
        );
    }
    
    public function getBaseInstanceUrl() {
        $ctx = trim($this->contextPath, "/");
        $a = explode("/", $ctx);
        $ctx = implode("/", array_slice($a, 0, count($a) - 1));
        return $this->ensureUrlProtocol(
            Utils::concatUrl($this->serverUrl, $ctx)
        );
    }
    
    public function getUserContactFormUrl() {
        return Utils::concatUrl($this->getBaseInstanceUrl(), "/contact/");
    }
    
    public function getUserContactFormUrl2($username) {
        return $this->getUserContactFormUrl() . "?" . $username;
    }
    
    public function getTalkUrl() {
        return Utils::concatUrl($this->getBaseInstanceUrl(), "/talk/");
    }
    
    public function getTalkUrl2($username) {
        return $this->getTalkUrl() . "?" . $username;
    }
    
    public function getValidateUrl() {
        return Utils::concatUrl($this->getBaseInstanceUrl(), "/validate/");
    }
    
    public function getValidateUrl2($token) {
        return $this->getValidateUrl() . "?token=" . $token;
    }
    
    public function getServerEmail() {
        return $this->serverEmail;
    }
    
    public function getServerEmailNoReply() {
        return $this->serverEmailNoReply;
    }
    
    public function hostIsMyself($host) {
        foreach ($this->hosts as $i => $h) {
            if ($host == $h) {
                return true;
            }
        }
        return false;
    }
    
    public function isCrossDomainAjax() {
        return $this->crossDomainAjax;
    }
    
    public function getDataDirectory() {
        return $this->dataDirectory;
    }
    
    public function getDatabaseEngine() {
        return $this->databaseEngine;
    }
    
    public function getMaxTimestampDifference() {
        return $this->maxTimestampDifference;
    }
    
    public function getGarbageCollectorInterval() {
        return $this->garbageCollectorInterval;
    }
    
    public function isGarbageCollectorEnabled() {
        return $this->garbageCollectorInterval !== false;
    }
    
    public function getNotifierInterval() {
        return $this->notifierInterval;
    }
    
    public function isNotifierEnabled() {
        return $this->notifierInterval !== false;
    }
    
    public function getMinNotifierDelay() {
        return $this->minNotifierDelay;
    }
    
    public function getNotifierRenewTime() {
        return $this->notifierRenewTime;
    }
    
    public function getNotifierRenewMode() {
        return $this->notifierRenewMode;
    }
    
    public function getMaxUserInactiveTime() {
        return $this->maxUserInactiveTime;
    }
    
    public function getMaxUnassignedBlockAge() {
        return $this->maxUnassignedBlockAge;
    }
    
    public function getMaxUserPresenceAge() {
        return $this->maxUserPresenceAge;
    }
    
    public function getMaxDescriptorBulkSize() {
        return $this->maxDescriptorBulkSize;
    }
    
    public function getMaxMessageBulkSize() {
        return $this->maxMessageBulkSize;
    }
    
    public function getMaxBlockBulkSize() {
        return $this->maxBlockBulkSize;
    }
    
    public function getMaxUserBulkSize() {
        return $this->maxUserBulkSize;
    }
    
    public function isOpenRegistrationEnabled() {
        return $this->openRegistrationEnabled;
    }
    
    public function isInvitationEnabled() {
        return $this->invitationEnabled;
    }
    
    public function getMaxPinAttemptsCount() {
        return $this->maxPinAttemptsCount;
    }
    
    public function getKeys() {
        return $this->keys;
    }
    
    public function getKeystore() {
        return $this->keystore;
    }

    public function getSymmetric() {
        return $this->symmetric;
    }
    
    public function getLongPollingInterval() {
        return $this->longPollingInterval;
    }
    
    public function getLongPollingTimeout() {
        return $this->longPollingTimeout;
    }
    
    public function getLongPollingDelay() {
        return $this->longPollingDelay;
    }
    
    public function getLockTimeout() {
        return $this->lockTimeout;
    }
    
    public function isChangeCredentialsEnabled() {
        return $this->changeCredentialsEnabled;
    }
    
    public function isKeyLoginEnabled() {
        return $this->keyLoginEnabled;
    }
    
    public function isInterServerCommunicationEnabled() {
        return $this->interServerCommunicationEnabled;
    }
    
    public function isRegistrationEnabled() {
        return $this->registrationEnabled;
    }
    
    public function hasBlockCountLimit() {
        return $this->maxBlocksCount != -1;
    }
    
    public function getMaxBlocksCount() {
        return $this->maxBlocksCount;
    }
    
    public function hasBlockLengthLimit() {
        return $this->maxBlockLength != -1;
    }
    
    public function getMaxBlocksLength() {
        return $this->maxBlockLength;
    }
    
    public function getDefaultInvitationLinkPattern() {
        return $this->ensureUrlProtocol(
            $this->defaultInvitationLinkPattern
        );
    }
    
    public function getAdminInvitationLinkPattern() {
        return $this->ensureUrlProtocol(
            $this->adminInvitationLinkPattern
        );
    }
    
    public function getClientUrl() {
        return str_replace("token={token}", "", $this->getDefaultInvitationLinkPattern());
    }
    
    public function getMaxUsersCount() {
        return $this->maxUsersCount;
    }
    
    public function isLoginBlocked() {
        return $this->loginBlocked;
    }
    
    public function getServerVersion() {
        return $this->privfsServerVersion;
    }
    
    public function isCronEnabled() {
        return $this->cronEnabled;
    }
    
    public function isInstantNotificationEnabled() {
        return $this->instantNotificationEnabled;
    }
    
    public function getCorsDomains() {
        return $this->corsDomains;
    }
    
    /* ===== maintenance ===== */
    
    public function getMaintenanceLockPath() {
      return realpath(__DIR__ . "/../../..") . "/maintenance.lock";
    }
    
    public function isMaintenanceModeEnabled() {
        $path = $this->getMaintenanceLockPath();
        return file_exists($path);
    }
    
    public function enterMaintenanceMode() {
      $path = $this->getMaintenanceLockPath();
      if (!file_exists($path)) {
        return false !== file_put_contents($path, "1");
      }
      return true;
    }
    
    public function exitMaintenanceMode() {
      $path = $this->getMaintenanceLockPath();
      if (file_exists($path)) {
        return false !== unlink($path);
      }
      return true;
    }

    public function getTicketsDirectory() {
        return $this->ticketsDirectory;
    }

    public function getTicketsEngine() {
        return $this->ticketsEngine;
    }

    public function getApplicationLogPath() {
        return $this->applicationLogPath;
    }
    
    public function getApplicationLogLevel() {
        return $this->applicationLogLevel;
    }

    public function getMaxFormsExtraData() {
        return $this->maxFormsExtraData;
    }

    public function isForceHttps() {
        return $this->forceHTTPS === true;
    }

    public function verifySSLCertificates() {
        return $this->verifySSLCertificates === false ? false : true;
    }

    public function getMaxCosigners() {
        return $this->maxCosigners;
    }

    public function getTicketsTTL() {
        return $this->ticketsTTL;
    }
    
    public function getCustomMenuItems() {
        return $this->customMenuItems;
    }
    
    public function getPublicProxy() {
        return $this->publicProxy;
    }
}
