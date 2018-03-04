<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
require_once("vendor/autoload.php");

$b = microtime(true);
for($cnt = 0; $cnt < 10; ++$cnt) {
$db = dba_open("test.dba", "c", "db4");

$set = array();

$bigval = str_repeat("abc", 1000);

for($i = 0; $i < 200000; ++$i) {
    $key = strval(rand(0, 200000));
    $op = rand(0,5);
    if ($op < 3) {
        dba_delete($key, $db);
        unset($set[$key]);
    } elseif ($op < 5) {
        $value = "xx$key";
        dba_replace($key, $value, $db);
        $set[$key] = $value;
    } else {
        $value = $bigval . $key;
        dba_replace($key, $value, $db);
        $set[$key] = $value;
    }
}

foreach($set as $key => $value) {
    if (dba_fetch($key, $db) != $value) {
        echo "DUPA $key!\n";
    }
}
dba_close($db);
}
$e = microtime(true);

echo "ok " . ($e - $b) . "\n";
echo count($set) . "\n";
