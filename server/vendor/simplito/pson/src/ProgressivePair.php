<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
namespace PSON;

class ProgressivePair extends Pair {
    public function __construct(array $dict = array(), array $options = array()) {
        $this->encoder = new Encoder($dict, true, $options);
        $this->decoder = new Decoder($dict, true, $options);
    }

    public function exclude($obj) {
        PSON::exclude($obj);
    }
}
