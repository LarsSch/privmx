<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

require_once(__DIR__."/vendor/autoload.php");

$ioc = new \io\privfs\data\IOC();
if (php_sapi_name() != "cli") {
    $logger = \io\privfs\log\LoggerFactory::get("[CRON SCRIPT]");
    $logger->error("Running cron from non cli environment");
    die();
}
$ioc->getCron()->execute();
