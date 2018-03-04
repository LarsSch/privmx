<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\plugin\updater;

use io\privfs\config\Config;
use io\privfs\core\Executor;
use io\privfs\core\Lock;
use io\privfs\core\Settings;

class UpdateChecker extends Executor {
    
    protected $updateService;
    
    public function __construct(Config $config, Lock $lock, Settings $settings, UpdateService $updateService) {
        parent::__construct($lock, $settings, "lastUpdateCheckerWorkTime", $config->updateCheckerEnabled, $config->updateCheckerInterval);
        
        $this->updateService = $updateService;
    }
    
    protected function shouldStartWork() {
        if (!$this->enabled) {
            return false;
        }
        $this->updateService->checkChannelUrl();
        $last = $this->getLastWorkTime()->div(1000)->toNumber();
        return date("d", $last) != date("d");
    }
    
    protected function go() {
        $this->updateService->checkPackVersionStatus(false);
    }
}
