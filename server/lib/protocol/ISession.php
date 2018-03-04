<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\protocol;

interface ISession {
    public function contains($key);
    public function save($key, $value);
    public function get($key, $default = null);
    public function delete($key);
}

?>
