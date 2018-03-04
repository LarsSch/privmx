<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\data;

use io\privfs\core\DbManager;

class SecureForm {
  
  protected $dbManager;
  
  public function __construct(DbManager $dbManager) {
    $this->dbManager = $dbManager;
  }
  
  public function createToken($sid) {
    $data = array(
      "sid" => $sid,
      "expires" => time() + 3600
    );
    $dataEnc = json_encode($data);
    $token = hash("sha256", rand() . $dataEnc);
    $db = $this->getDbForWrite();
    $db->replace($token, $dataEnc);
    $this->closeDb($db);
    return $token;
  }
  
  public function getTokenData($token) {
    $this->cleanExpiredTokens();
    $db = $this->getDbForRead();
    $data = $db->fetch($token);
    $this->closeDb($db);
    return $data ? json_decode($data, true) : null;
  }
  
  protected function cleanExpiredTokens() {
    $db = $this->getDbForWrite();
    $key = $db->firstkey();
    $result = array();
    while ($key !== false) {
      $result[$key] = json_decode($db->fetch($key), true);
      $key = $db->nextkey();
    }
    foreach ($result as $key => $value) {
      if ($value["expires"] < time()) {
        $db->delete($key);
      }
    }
    $this->closeDb($db);
  }
  
  protected function closeDb($db) {
    $this->dbManager->closeDb($db);
  }
  
  protected function getDbForWrite() {
    return $this->dbManager->getDbForWrite("secure-form-token");
  }
  
  protected function getDbForRead() {
    return $this->dbManager->getDbForRead("secure-form-token");
  }
  
}
