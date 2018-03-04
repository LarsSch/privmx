<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\plugin\updater;

use io\privfs\data\Plugin;

class UpdaterPlugin extends Plugin {
  
  public $ioc;
  public $settings;
  public $config;
  public $validators;
  public $updateService;
  public $updateChecker;
  
  public function __construct($ioc) {
    $this->ioc = $ioc;
    $this->settings = $this->ioc->getSettings();
    
    $this->config = $this->ioc->getConfig();
    $this->config->setOptionalVariable("updateCheckerEnabled", true);
    $this->config->setOptionalVariable("updateCheckerInterval", 24 * 60 * 60 * 1000);
    $this->config->setOptionalVariable("updateIgnoreCertificate", !$this->config->verifySSLCertificates());
    $defaultUpdatesChannel = "https://privmx.com/packages/main";
    $this->config->setOptionalVariable("updatesChannel", $defaultUpdatesChannel);
    $this->config->customUpdatesChannel = $this->config->updatesChannel != $defaultUpdatesChannel;
    
    $this->validators = new Validators($ioc->getValidators());
    $this->updateService = new UpdateService($this->config, $this->settings);
    $this->updateChecker = new UpdateChecker($this->config, $this->ioc->getLock(), $this->settings, $this->updateService);
    
    register_privmx_callback("afterRequest", array($this, "afterRequest"));
    register_privmx_callback("getUserConfig", array($this, "getUserConfig"));
  }

  public function registerEndpoint(\io\privfs\protocol\ServerEndpoint $server) {
    $server->bind($this, "checkPackVersionStatus", array(
      "name" => "updaterCheckPackVersionStatus",
      "validator" => $this->validators->get("checkPackVersionStatus"),
      "permissions" => $server::ADMIN_PERMISSIONS
    ));

    $server->bind($this, "initUpdate", array(
      "name" => "updaterInitUpdate",
      "validator" => $this->validators->get("initUpdate"),
      "permissions" => $server::ADMIN_PERMISSIONS
    ));
    
    $server->bind($this, "getInstalledPackInfo", array(
      "name" => "updaterGetInstalledPackInfo",
      "validator" => $this->validators->get("getInstalledPackInfo")
    ));
    
    $server->bind($this, "getUpdateVersionDetails", array(
      "name" => "updaterGetUpdateVersionDetails",
      "validator" => $this->validators->get("getUpdateVersionDetails"),
      "permissions" => $server::ADMIN_PERMISSIONS
    ));
    
    $server->bind($this, "setLastSeenUpdate", array(
      "name" => "updaterSetLastSeenUpdate",
      "validator" => $this->validators->get("setLastSeenUpdate"),
      "permissions" => $server::ADMIN_PERMISSIONS
    ));
  }
  
  public function processEvent($event) {
    
  }
  
  public function getName() {
    return "updater";
  }
  
  /* ====================================================================== */
  
  public function afterRequest() {
    $this->updateChecker->start();
  }
  
  public function getUserConfig() {
    return $this->updateService->getUserConfig();
  }
  
  /* ====================================================================== */
  
  public function checkPackVersionStatus() {
    return $this->updateService->checkPackVersionStatus(true);
  }
  
  public function initUpdate($version) {
    return $this->updateService->init($version);
  }
  
  public function getInstalledPackInfo() {
    return $this->updateService->getInstalledPackInfo();
  }
  
  public function getUpdateVersionDetails($version) {
    return $this->updateService->getUpdateVersionDetails($version);
  }
  
  public function setLastSeenUpdate($version) {
    return $this->updateService->setLastSeenUpdate($version);
  }
  
}
