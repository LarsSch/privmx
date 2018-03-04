<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
namespace PSON;

abstract class Pair {
    protected $encoder;
    protected $decoder;

    public function encode($json) {
        return $this->encoder->encode($json);
    }    

    public function toArrayBuffer($json) {
        return $this->encoder->encode($json)->toArrayBuffer();
    }

    public function toBuffer($json) {
        return $this->encoder->encode($json)->toBuffer();
    }

    public function decode($pson) {
        return $this->decoder->decode($pson);
    }
}
