<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

use BI\BigInteger;

class Executor {
    
    protected $lock;
    protected $settings;
    protected $logger;
    protected $settingsKey;
    protected $enabled;
    protected $interval;
    
    public function __construct(Lock $lock, Settings $settings, $settingsKey, $enabled, $interval) {
        
        $this->lock = $lock;
        $this->settings = $settings;
        $this->settingsKey = $settingsKey;
        $this->enabled = $enabled;
        $this->interval = $interval;
        $this->logger = \io\privfs\log\LoggerFactory::get($this);
    }
    
    protected function getLastWorkTime() {
        $last = $this->settings->getSetting($this->settingsKey);
        if (is_null($last)) {
            return new BigInteger(0);
        }
        return Utils::biFrom64bit($last);
    }
    
    protected function setLastWorkTime($last) {
        $this->settings->setSetting($this->settingsKey, Utils::biTo64bit($last));
    }
    
    protected function shouldStartWork() {
        if (!$this->enabled) {
            return false;
        }
        $last = $this->getLastWorkTime();
        return Utils::timeMili()->sub($last)->cmp($this->interval) > 0;
    }
    
    public function start() {
        if ($this->shouldStartWork()) {
            if (!$this->lock->try_writer()) {
                return;
            }
            if ($this->shouldStartWork()) {
                $this->logger->debug("Starting...");
                $this->go();
                $this->setLastWorkTime(Utils::timeMili());
            }
            $this->lock->release();
        }
    }
    
    protected function go() {
    }
}
