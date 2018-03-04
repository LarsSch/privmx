<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
namespace PSON;

class PSON {
    public static function exclude($obj) {
        if (is_object($obj)) {
            $obj->_PSON_EXCL_ = true;
        }
    }
}



