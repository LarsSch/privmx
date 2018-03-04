<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
require_once("vendor/autoload.php");

function errHandle($errNo, $errStr, $errFile, $errLine) {
    $msg = "$errStr in $errFile on line $errLine";
    if ($errNo == E_NOTICE || $errNo == E_WARNING) {
        throw new ErrorException($msg, $errNo);
    } else {
        echo $msg;
    }
}

set_error_handler('errHandle');

$fetches = 0;
$deletions = 0;
$small_inserts = 0;
$big_inserts = 0;
$opens = 0;

echo "general tests\n";
$b = microtime(true);
for($cnt = 0; $cnt < 10; ++$cnt) {
    ++$opens;
    $ldb = new simplito\LinearHashingDb();
    $ldb->open("test.ldb", "c");

    $set = array();

    $bigval = str_repeat("x", rand(4,64)*1024);

    for($i = 0; $i < 10000; ++$i) {
        $key = strval(rand(0, 200000));
        $op = rand(0,10);
        if ($op < 5) {
            ++$fetches;
            $ldb->fetch($key);
        } elseif ($op < 7) {
            ++$deletions;
            $ldb->delete($key);
            unset($set[$key]);
        } elseif ($op < 10) {
            ++$small_inserts;
            $value = "xx$key";
            $ldb->replace($key, $value);
            $set[$key] = $value;
        } else {
            ++$big_inserts;
            $value = $bigval . $key;
            $ldb->replace($key, $value);
            $set[$key] = $value;
        }
    }

    foreach($set as $key => $value) {
        if ($ldb->fetch($key) != $value) {
            echo "DUPA $key!\n";
        }
    }

    echo "objects in db: {$ldb->count()}\n";
    $ldb->close();
}
$e = microtime(true);

$operations = $fetches + $deletions + $small_inserts + $big_inserts;
$time = $e - $b;
echo "reopens: $opens\n";
echo "operations: $operations\n";
echo "  fetches: $fetches\n";
echo "  deletions: $deletions\n";
echo "  small inserts: $small_inserts\n";
echo "  big inserts: $big_inserts\n";
echo "time elasped: $time\n";
echo "op. speed: " . round($operations/$time) . "/s\n";


echo "\niterating tests\n";

$dbh = ldba_open("test.ldb", "r");
$count = 0;
$b = microtime(true);
$key = ldba_firstkey($dbh);
while($key !== false) {
    ++$count;
    $key = ldba_nextkey($dbh);
}
$e = microtime(true);
$reported = ldba_count($dbh);
$time = $e - $b;
ldba_close($dbh);
echo "iterated: $count\n";
echo "reported: $reported\n";
echo "time elapsed: $time\n";
echo "speed: " . round($count/$time) . "/s\n";


echo "\nopen/close tests\n";
$b = microtime(true);
$count = 0;
for($i = 0; $i < 1000; ++$i) {
    ++$count;
    $dbh = ldba_open("test.ldb", "r");
    ldba_close($dbh);
}
$e = microtime(true);
$time = $e - $b;
echo "reopened: $count\n";
echo "time elapsed: $time\n";
echo "speed: " . round($count/$time) . "/s\n";

