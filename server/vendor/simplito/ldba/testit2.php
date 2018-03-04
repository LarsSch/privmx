<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
require_once("vendor/autoload.php");

$db = dba_open("test.dba", "r", "db4");
$key = dba_firstkey($db);
while($key !== false) {
    echo $key . "\n";
    $key = dba_nextkey($db);
}
dba_close($db);
