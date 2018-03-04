<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\data;

use io\privfs\core\Utils;
use BI\BigInteger;

class SessionHolder {
    
    private $sessionStarted;
    
    public function __construct() {
        ini_set("session.use_cookies", 0);
        $this->sessionStarted = false;
    }
    
    public function saveSession($session, $closeSession = true) {
        if (!$this->sessionStarted) {
            session_start();
            session_regenerate_id();
            $this->sessionStarted = true;
        }
        
        $this->saveToSession($session["user"], "I");
        $this->saveToSession($session["user"], "pub");
        $this->saveHexToSession($session["user"], "s");
        $this->saveBigIntegerToSession($session["user"], "v");
        $this->saveToSession($session["user"], "isAdmin");
        $this->saveToSession($session["user"], "type");
        $this->saveToSession($session, "state");
        $this->saveToSession($session, "priv");
        $this->saveToSession($session["properties"], "appVersion");
        $this->saveToSession($session["properties"], "sysVersion");
        $this->saveBigIntegerToSession($session, "N");
        $this->saveBigIntegerToSession($session, "g");
        $this->saveBigIntegerToSession($session, "k");
        $this->saveBigIntegerToSession($session, "b");
        $this->saveBigIntegerToSession($session, "B");
        $this->saveBigIntegerToSession($session, "clientM1");
        $this->saveBigIntegerToSession($session, "u");
        $this->saveBigIntegerToSession($session, "s");
        $this->saveBigIntegerToSession($session, "serverM1");
        $this->saveBigIntegerToSession($session, "M2");
        $this->saveHexToSession($session, "K");
        $id = session_id();
        
        if ($closeSession) {
            $this->sessionStarted = false;
            session_write_close();
        }
        
        return $id;
    }
    
    public function restoreSession($sessionId, $closeSession = true) {
        session_id($sessionId);
        if (session_start() == false || !isset($_SESSION["state"])) {
            return null;
        }
        $this->sessionStarted = true;
        $user = array(
            "I" => $this->getFromSession("I"),
            "pub" => $this->getFromSession("pub"),
            "s" => $this->getHexFromSession("s"),
            "v" => $this->getBigIntegerFromSession("v"),
            "isAdmin" => $this->getFromSession("isAdmin"),
            "type" => $this->getFromSession("type")
        );
        $properties = array(
            "appVersion" => $this->getFromSession("appVersion"),
            "sysVersion" => $this->getFromSession("sysVersion")
        );
        $res = array(
            "user" => $user,
            "properties" => $properties,
            "state" => $this->getFromSession("state"),
            "priv" => $this->getFromSession("priv"),
            "N" => $this->getBigIntegerFromSession("N"),
            "g" => $this->getBigIntegerFromSession("g"),
            "k" => $this->getBigIntegerFromSession("k"),
            "b" => $this->getBigIntegerFromSession("b"),
            "B" => $this->getBigIntegerFromSession("B"),
            "clientM1" => $this->getBigIntegerFromSession("clientM1"),
            "u" => $this->getBigIntegerFromSession("u"),
            "s" => $this->getBigIntegerFromSession("s"),
            "serverM1" => $this->getBigIntegerFromSession("serverM1"),
            "M2" => $this->getBigIntegerFromSession("M2"),
            "K" => $this->getHexFromSession("K")
        );
        
        if ($closeSession) {
            $this->sessionStarted = false;
            session_write_close();
        }
        
        return $res;
    }
    
    private function saveBigIntegerToSession($collection, $key) {
        if (!isset($collection[$key])) {
            return;
        }
        $_SESSION[$key] = $collection[$key]->toHex();
    }
    
    private function getBigIntegerFromSession($key) {
        if (!isset($_SESSION[$key])) {
            return null;
        }
        return new BigInteger($_SESSION[$key], 16);
    }
    
    private function saveHexToSession($collection, $key) {
        if (!isset($collection[$key])) {
            return;
        }
        $_SESSION[$key] = bin2Hex($collection[$key]);
    }
    
    private function getHexFromSession($key) {
        if (!isset($_SESSION[$key])) {
            return null;
        }
        return Utils::hex2bin($_SESSION[$key]);
    }
    
    private function saveToSession($collection, $key) {
        if (!isset($collection[$key])) {
            return;
        }
        $_SESSION[$key] = $collection[$key];
    }
    
    private function getFromSession($key) {
        if (!isset($_SESSION[$key])) {
            return null;
        }
        return $_SESSION[$key];
    }
}
