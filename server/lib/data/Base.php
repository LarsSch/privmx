<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\data;

use io\privfs\core\DbManager;

class Base {
    
    protected $dbManager;
    
    public function __construct(DbManager $dbManager) {
        $this->dbManager = $dbManager;
    }
    
    /*========================*/
    /*      DESCRIPTOR DB     */
    /*========================*/
    
    protected function getDescriptorDbForRead() {
        return $this->dbManager->getDbForRead("descriptor");
    }
    
    protected function getDescriptorDbForWrite() {
        return $this->dbManager->getDbForWrite("descriptor");
    }
    
    protected function closeDescriptorDb() {
        $this->dbManager->closeDbByName("descriptor");
    }
    
    /*========================*/
    /*        BLOCK DB        */
    /*========================*/
    
    protected function getBlockDbForRead($prefix) {
        return $this->dbManager->getDbForRead($prefix, false);
    }
    
    protected function getBlockDbForWrite($prefix) {
        return $this->dbManager->getDbForWrite($prefix, false);
    }
    
    protected function closeBlockDb($db) {
        $this->dbManager->closeDb($db);
    }
    
    /*========================*/
    /*         SINK DB        */
    /*========================*/
    
    protected function getSinkDbForRead() {
        return $this->dbManager->getDbForRead("sink");
    }
    
    protected function getSinkDbForWrite() {
        return $this->dbManager->getDbForWrite("sink");
    }
    
    protected function closeSinkDb() {
        $this->dbManager->closeDbByName("sink");
    }
    
    /*========================*/
    /*         USER DB        */
    /*========================*/
    
    protected function getUserDbForRead() {
        return $this->dbManager->getDbForRead("user");
    }
    
    protected function getUserDbForWrite() {
        return $this->dbManager->getDbForWrite("user");
    }
    
    protected function closeUserDb() {
        $this->dbManager->closeDbByName("user");
    }
    
    /*========================*/
    /*       MESSAGE DB       */
    /*========================*/
    
    protected function getMessageDbForRead($prefix) {
        return $this->dbManager->getDbForRead($prefix);
    }
    
    protected function getMessageDbForWrite($prefix) {
        return $this->dbManager->getDbForWrite($prefix);
    }
    
    protected function closeMessageDb($db) {
        $this->dbManager->closeDb($db);
    }
    
    protected function removeMessageDb($db) {
        $this->dbManager->removeDbFile($db);
    }
    
    /*========================*/
    /*          CHECK         */
    /*========================*/
    
    public function canDatabasesBeOpened() {
        $this->getDescriptorDbForWrite();
        $this->getSinkDbForWrite();
        $this->getUserDbForWrite();
        $this->closeDescriptorDb();
        $this->closeSinkDb();
        $this->closeUserDb();
    }
}
