<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

require_once __DIR__ . "/../../vendor/autoload.php";

use simplito\PrivMXServiceDiscovery;

$config = null;

if (isset($_POST['host']) ) {
  $host = $_POST['host'];
  $service = new PrivMXServiceDiscovery();
  $config = $service->discover($host, !empty($_POST['dnsOnly']));
}

header('Access-Control-Allow-Origin: *');
header('Content-type: application/json; charset=utf-8');
die(json_encode($config));
