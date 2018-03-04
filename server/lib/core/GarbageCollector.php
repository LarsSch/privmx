<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

use io\privfs\config\Config;

class GarbageCollector extends Executor {
    
    public $func;
    
    public function __construct(Config $config, Lock $lock, Settings $settings) {
        parent::__construct($lock, $settings, "lastGCWorkTime", $config->isGarbageCollectorEnabled(), $config->getGarbageCollectorInterval());
    }
    
    protected function go() {
        if (!is_null($this->func)) {
            call_user_func_array($this->func, array());
        }
    }
}
