<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\data;

use io\privfs\config\Config;
use io\privfs\core\DbManager;
use io\privfs\core\Utils;
use io\privfs\core\JsonDatabase;
use BI\BigInteger;

class UserStatus {
    
    const DB_NAME = "user_status_file_enc";
    
    private $dbManager;
    private $config;
    
    public function __construct(DbManager $dbManager, Config $config) {
        $this->dbManager = $dbManager;
        $this->config = $config;
    }
    
    public function isUserLogged($username) {
        $db = new JsonDatabase($this->dbManager->getDbFilePath(UserStatus::DB_NAME), "r", hex2bin($this->config->getSymmetric()));
        $logged = false;
        if ($db->has($username)) {
            $time = Utils::timeMili();
            $last = new BigInteger($db->get($username), 16);
            $logged = $last->cmp($time->sub($this->config->getMaxUserInactiveTime())) >= 0;
        }
        $db->close();
        return $logged;
    }
    
    public function refreshUser($username) {
        $db = new JsonDatabase($this->dbManager->getDbFilePath(UserStatus::DB_NAME), "w", hex2bin($this->config->getSymmetric()));
        $time = Utils::timeMili();
        $db->set($username, $time->toHex());
        $db->close();
    }
}
