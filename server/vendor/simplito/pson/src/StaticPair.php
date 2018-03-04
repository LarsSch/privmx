<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
namespace PSON;

class StaticPair extends Pair {
    public function __construct($dict = array(), $options = array()) {
        $this->encoder = new Encoder($dict, false, $options);
        $this->decoder = new Decoder($dict, false, $options);
    }
}
