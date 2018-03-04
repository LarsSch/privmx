<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

class Comparator {
    
    public static function isHigher($a, $b) {
        return $a > $b;
    }
    
    public static function isHigherOrEqual($a, $b) {
        return $a >= $b;
    }
    
    public static function isLower($a, $b) {
        return $a < $b;
    }
    
    public static function isLowerOrEqual($a, $b) {
        return $a <= $b;
    }
    
    public static function isEqual($a, $b) {
        return $a == $b;
    }
    
    public static function isNotEqual($a, $b) {
        return $a != $b;
    }
    
    public static function getFunctionByName($name) {
        if ($name == "HIGHER") {
            return "isHigher";
        }
        if ($name == "HIGHER_EQUAL") {
            return "isHigherOrEqual";
        }
        if ($name == "LOWER") {
            return "isLower";
        }
        if ($name == "LOWER_EQUAL") {
            return "isLowerOrEqual";
        }
        if ($name == "EQUAL") {
            return "isEqual";
        }
        if ($name == "NOT_EQUAL") {
            return "isNotEqual";
        }
        throw new \Exception("Invalid name " . $name);
    }
    
    public static function getCallableByName($name) {
        return "\io\privfs\core\Comparator::" . Comparator::getFunctionByName($name);
    }
}