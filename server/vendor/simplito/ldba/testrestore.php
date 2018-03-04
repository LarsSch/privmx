<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
require_once("vendor/autoload.php");

$mode = $argv[1];
$dMode = count($argv) > 2 ? $argv[2] : "";
if ($mode == "create") {
    unlink("test.ldb");
    $dbh = ldba_open("test.ldb", "c");

    ldba_insert("first1", "some text", $dbh);
    ldba_insert("first2", "some text", $dbh);
    ldba_insert("first3", "some text", $dbh);
    ldba_insert("second", "some very long text", $dbh);
    //ldba_insert("lorem", "Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.", $dbh);
    $dbh->flush();
    ldba_delete("first1", $dbh);
    ldba_delete("first2", $dbh);
    
    if ($dMode == "dirty") {
        $dbh->flush();
        error_log($dbh->showBuddySystem());
        $dbh->setBuddySystemDirty();
    }
    else if ($dMode == "kill") {
        error_log("you have 2 seconds to kill process and destroy database");
        sleep(2);
    }
    ldba_close($dbh);
    error_log("Database successfully closed");
}
else if ($mode == "read") {
    $dbh = ldba_open("test.ldb", "r");

    $key = ldba_firstkey($dbh);
    while ($key !== false) {
        if ($dMode == "novalue") {
            error_log($key);
        }
        else {
            $value = ldba_fetch($key, $dbh);
            error_log($key . ": " . $value);
        }
        $key = ldba_nextkey($dbh);
    }
    error_log($dbh->showBuddySystem());
    ldba_close($dbh);
}
else if ($mode == "restore") {
    $dbh = ldba_open("test.ldb", "w");
    error_log($dbh->showBuddySystem());
    ldba_close($dbh);
}