<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\data;

abstract class Plugin {
    abstract public function processEvent($event);
    abstract public function getName();

    public function registerEndpoint(\io\privfs\protocol\ServerEndpoint $endpoint) {}
}
