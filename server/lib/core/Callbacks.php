<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

class Callbacks
{
    // map callback name => callback function
    private $callbacks;

    public function __construct()
    {
        global $_PRIVMX_GLOBALS;
        if( !is_array($_PRIVMX_GLOBALS["callbacks"]) )
            $_PRIVMX_GLOBALS["callbacks"] = array();
        $this->callbacks = $_PRIVMX_GLOBALS["callbacks"];
    }

    /**
    * Triggers callbacks registred under name $name
    * returns array of callbacks results
    *
    * @param string $name
    * @param array $args
    *
    * @return mixed[]
    */
    public function trigger($name, $args = array())
    {
        if( !isset($this->callbacks[$name]) )
            return array();

        return array_map(function($callback) use (&$args) {
            return call_user_func_array($callback, $args);
        }, $this->callbacks[$name]);
    }
};
