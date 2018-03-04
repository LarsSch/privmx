<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

function register_privmx_callback($name, $callback)
{
    if( !is_callable($callback) )
        return false;
    global $_PRIVMX_GLOBALS;
    if( !isset($_PRIVMX_GLOBALS["callbacks"][$name]) )
        $_PRIVMX_GLOBALS["callbacks"][$name] = array();
    array_push($_PRIVMX_GLOBALS["callbacks"][$name], $callback);
    return true;
}
