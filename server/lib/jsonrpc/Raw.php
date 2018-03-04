<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\jsonrpc;

class Raw {
    
    private $data;
    private $binary;
    
    public function __construct($data, $binary = false) {
        $this->data = $data;
        $this->binary = $binary;
    }
    
    public function getData() {
        return $this->data;
    }
    
    public function isBinary() {
        return $this->binary;
    }
}