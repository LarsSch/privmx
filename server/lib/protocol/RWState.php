<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\protocol;

class RWState {
    var $initialized;
    var $sequence_number;
    var $key;
    var $mac_key;

    public function __construct($key = "", $mac_key = "") {
        $this->key = $key;
        $this->mac_key = $mac_key;
        $this->initialized = ($this->key != null) && ($this->mac_key != null);
        $this->sequence_number = 0;
    }
};

?>
