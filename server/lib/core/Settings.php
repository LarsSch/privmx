<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

class Settings {

    private $dbManager;
    
    public function __construct(DbManager $dbManager) {
        $this->dbManager = $dbManager;
    }
    
    public function hasSetting($name) {
        $db = $this->dbManager->getDbForRead("settings");
        $value = $db->exists($name);
        $this->dbManager->closeDbByName("settings");
        return $value;
    }
    
    public function getSetting($name) {
        $db = $this->dbManager->getDbForRead("settings");
        $value = null;
        if ($db->exists($name)) {
            $value = $db->fetch($name);
        }
        $this->dbManager->closeDbByName("settings");
        return $value;
    }
    
    public function getSettingWithDefaultLanguage($name, $defaultValue) {
        $setting = $this->getSetting($name);
        if (is_null($setting)) {
            return $defaultValue;
        }
        $setting = json_decode($setting, true);
        if (!isset($setting["langs"])) {
            return $defaultValue;
        }
        if (isset($setting["defaultLang"])) {
            return isset($setting["langs"][$setting["defaultLang"]]) ? $setting["langs"][$setting["defaultLang"]] : $defaultValue;
        }
        return count($setting["langs"]) > 0 ? array_values($array)[0] : $defaultValue;
    }
    
    public function getSettingForLanguage($name, $obj, $defaultValue) {
        $setting = $this->getSetting($name);
        if (is_null($setting)) {
            return $defaultValue;
        }
        $setting = json_decode($setting, true);
        if (!isset($setting["langs"])) {
            return $defaultValue;
        }
        if (isset($obj["language"]) && isset($setting["langs"][$obj["language"]])) {
            return $setting["langs"][$obj["language"]];
        }
        if (!isset($setting["defaultLang"])) {
            return $defaultValue;
        }
        return isset($setting["langs"][$setting["defaultLang"]]) ? $setting["langs"][$setting["defaultLang"]] : $defaultValue;
    }
    
    public function getSettingForLanguageFromObj($setting, $lang) {
        if (isset($setting["langs"][$lang])) {
            return $setting["langs"][$lang];
        }
        return $setting["langs"][$setting["defaultLang"]];
    }
    
    public function setSetting($name, $value) {
        $db = $this->dbManager->getDbForWrite("settings");
        $db->update($name, $value);
        $this->dbManager->closeDbByName("settings");
    }
    
    public function deleteSetting($name) {
        $db = $this->dbManager->getDbForWrite("settings");
        $db->delete($name);
        $this->dbManager->closeDbByName("settings");
    }
    
    public function getObject($name) {
        $value = $this->getSetting($name);
        return is_null($value) ? $value : json_decode($value, true);
    }
    
    public function setObject($name, $value) {
        $this->setSetting($name, json_encode($value));
    }
}
