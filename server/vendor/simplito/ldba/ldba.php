<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

use \simplito\LinearHashingDb;

function ldba_open($fname, $mode) {
    $ldb = new LinearHashingDb();
    if ($ldb->open($fname, $mode))
        return $ldb;
    return false;
}

function ldba_close($dbh) {
    $dbh->close();
}

function ldba_insert($key, $value, $dbh) {
    return $dbh->insert($key, $value);
}

function ldba_replace($key, $value, $dbh) {
    return $dbh->replace($key, $value);
}

function ldba_fetch($key, $dbh) {
    return $dbh->fetch($key);
}

function ldba_exists($key, $dbh) {
    return $dbh->exists($key);
}

function ldba_delete($key, $dbh) {
    return $dbh->delete($key);
}

function ldba_firstkey($dbh) {
    return $dbh->firstkey();
}

function ldba_nextkey($dbh) {
    return $dbh->nextkey();
}

function ldba_count($dbh) {
    return $dbh->count();
}

