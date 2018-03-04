<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

class LanguageDetector {
    
    public $defaultLang;
    public $allowedLangs;
    public $getParamName;
    public $postParamName;
    public $requestParamName;
    public $cookieName;
    
    public function __construct($defaultLang, $allowedLangs) {
        $this->defaultLang = $defaultLang;
        $this->allowedLangs = $allowedLangs;
        $this->getParamName = "lang";
        $this->postParamName = "lang";
        $this->requestParamName = "lang";
        $this->cookieName = "lang";
    }
    
    public function detectFromGet($noDefault = false) {
        if (isset($_GET[$this->getParamName]) && in_array($_GET[$this->getParamName], $this->allowedLangs)) {
            return $_GET[$this->getParamName];
        }
        return $noDefault ? null : $this->defaultLang;
    }
    
    public function detectFromPost($noDefault = false) {
        if (isset($_POST[$this->postParamName]) && in_array($_POST[$this->postParamName], $this->allowedLangs)) {
            return $_POST[$this->postParamName];
        }
        return $noDefault ? null : $this->defaultLang;
    }
    
    public function detectFromRequest($noDefault = false) {
        if (isset($_REQUEST[$this->requestParamName]) && in_array($_REQUEST[$this->requestParamName], $this->allowedLangs)) {
            return $_REQUEST[$this->requestParamName];
        }
        return $noDefault ? null : $this->defaultLang;
    }
    
    public function detectFromCookie($noDefault = false) {
        if (isset($_COOKIE[$this->cookieName]) && in_array($_COOKIE[$this->cookieName], $this->allowedLangs)) {
            return $_COOKIE[$this->cookieName];
        }
        return $noDefault ? null : $this->defaultLang;
    }
    
    public function detectFromUrl($noDefault = false) {
        $parts = explode("/", $_SERVER["REQUEST_URI"]);
        if (count($parts) >= 2 && in_array($parts[1], $this->allowedLangs)) {
            return $parts[1];
        }
        return $noDefault ? null : $this->defaultLang;
    }
    
    public function detectFromHeader($noDefault = false) {
        if (isset($_SERVER["HTTP_ACCEPT_LANGUAGE"])) {
            $entries = explode(",", $_SERVER["HTTP_ACCEPT_LANGUAGE"]);
            foreach ($entries as $entry) {
                $lang = substr($entry, 0, 2);
                if (in_array($lang, $this->allowedLangs)) {
                    return $lang;
                }
            }
        }
        return $noDefault ? null : $this->defaultLang;
    }
    
    public function detect($noDefault = false) {
        $lang = $this->detectFromGet(true);
        if ($lang !== null) {
            return $lang;
        }
        $lang = $this->detectFromPost(true);
        if ($lang !== null) {
            return $lang;
        }
        $lang = $this->detectFromUrl(true);
        if ($lang !== null) {
            return $lang;
        }
        $lang = $this->detectFromCookie(true);
        if ($lang !== null) {
            return $lang;
        }
        $lang = $this->detectFromHeader(true);
        if ($lang !== null) {
            return $lang;
        }
        return $noDefault ? null : $this->defaultLang;
    }
}