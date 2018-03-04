<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\protocol;

class Session implements ISession {
    var $container = array();

    public function contains($key) { 
        return isset($this->container[$key]); 
    }
    public function save($key, $value) {
        $this->container[$key] = $value;
    }
    public function get($key, $default = null) {
        return isset($this->container[$key]) ? $this->container[$key] : $default;
    }
    public function delete($key) {
//        unset($this->container[$key]);
    }
}

?>
