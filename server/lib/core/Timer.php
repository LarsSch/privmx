<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

class Timer {
    
    private $name;
    private $start;
    private $last;
    
    public function __construct($name = "") {
        $this->name = $name;
        $this->start = Utils::timeMicro();
        $this->last = $this->start;
    }
    
    public function formatBI($bi) {
        $str = $bi->toString();
        $len = strlen($str);
        if ($len == 3) {
            return "0." . $str;
        }
        if ($len == 2) {
            return "0.0" . $str;
        }
        if ($len == 1) {
            return "0.00" . $str;
        }
        return substr($str, 0, -3) .  "." . substr($str, -3);
    }
    
    public function log($text = "") {
        $current = Utils::timeMicro();
        $fromBeg = $current->sub($this->start);
        $fromLast = $current->sub($this->last);
        $this->last = $current;
        error_log($this->name . $text . " +" . $this->formatBI($fromLast) . "ms (" . $this->formatBI($fromBeg) . "ms)");
    }
    
    static $instance;
    
    public static function init($name = "") {
        static::$instance = new Timer("[" . $_SERVER["SERVER_NAME"] . "][" . substr(uniqid('', true), -8) . "]" . $name);
    }
    
    public static function log2($text = "") {
        static::$instance->log($text);
    }
}
