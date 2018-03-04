<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\plugin\updater;

use io\privfs\core\Validator;

class Validators {
    
    public function __construct($that) {
        
        $this->version = $that->rangeLength($that->string, 1, 32);
        $this->updateID = $that->rangeLength($that->string, 1, 64);
        
        $this->checkPackVersionStatus = $that->createObject(array(
        ));
        
        $this->initUpdate = $that->createObject(array(
            "version" => $this->version
        ));
        
        $this->getInstalledPackInfo = $that->createObject(array(
        ));
        
        $this->getUpdateVersionDetails = $that->createObject(array(
            "version" => $this->version
        ));
        
        $this->setLastSeenUpdate = $that->createObject(array(
            "version" => $this->version
        ));
    }
    
    public function get($name) {
        return new Validator($this->{$name});
    }
}
