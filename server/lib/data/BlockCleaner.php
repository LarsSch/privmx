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
use privmx\pki\KVStore;
use BI\BigInteger;

class BlockCleaner {

    private $config;
    private $dbManager;
    private $logger;
    private $transferSessions;
    
    public function __construct(Config $config, DbManager $dbManager, KVStore $transferSessions) {
        $this->config = $config;
        $this->dbManager = $dbManager;
        $this->logger = \io\privfs\log\LoggerFactory::get($this);
        $this->transferSessions = $transferSessions;
    }
    
    /*========================*/
    /*      DESCRIPTOR DB     */
    /*========================*/
    
    private function getDescriptorDbForRead() {
        return $this->dbManager->getDbForRead("descriptor");
    }
    
    private function closeDescriptorDb() {
        $this->dbManager->closeDbByName("descriptor");
    }
    
    /*========================*/
    /*        BLOCK DB        */
    /*========================*/
    
    private function getBlockDbForWrite($prefix) {
        return $this->dbManager->getDbForWrite($prefix, false);
    }
    
    private function closeBlockDb($db) {
        $this->dbManager->closeDb($db);
    }
    
    /*========================*/
    /*         SINK DB        */
    /*========================*/
    
    private function getSinkDbForRead() {
        return $this->dbManager->getDbForRead("sink");
    }
    
    private function closeSinkDb() {
        $this->dbManager->closeDbByName("sink");
    }
    
    /*========================*/
    /*       MESSAGE DB       */
    /*========================*/
    
    private function getMessageDbForRead($prefix) {
        return $this->dbManager->getDbForRead($prefix);
    }
    
    private function closeMessageDb($db) {
        $this->dbManager->closeDb($db);
    }
    
    /*========================*/
    /*   GARBAGE COLLECTOR    */
    /*========================*/
    
    public function removeNotUsedBlocks() {
        $blocks = array();
        $this->getUsedBlocksFromDescriptors($blocks);
        $this->getUsedBlocksFromMessages($blocks);
        $dataDir = $this->config->getDataDirectory();
        $blockDbFiles = scandir($dataDir);
        foreach ($blockDbFiles as $blockDbFile) {
            $blockDbFilePath = Utils::joinPaths($dataDir, $blockDbFile);
            if (strlen($blockDbFile) == 5 && Utils::endsWith($blockDbFile, ".db") && is_file($blockDbFilePath)) {
                $prefix = substr($blockDbFile, 0 , 2);
                $this->cleanSingleBlockDatabase($prefix, $blocks);
            }
        }
    }
    
    private function getUsedBlocksFromDescriptors(&$blocks) {
        $db = $this->getDescriptorDbForRead();
        $key = $db->firstkey();
        while ($key !== false) {
            $data = $db->fetch($key);
            $json = json_decode($data, true);
            foreach($json["blocks"] as $block) {
                array_push($blocks, $block);
            }
            $key = $db->nextkey();
        }
        $this->closeDescriptorDb();
    }
    
    private function getUsedBlocksFromTransferSessions(&$blocks) {
        $this->transferSessions->enumerate(function($entry) use(&$blocks) {
            $entry = json_decode($entry, true);
            foreach ($entry["blocks"] as $block) {
                array_push($blocks, $block);
            }
        });
    }
    
    private function getUsedBlocksFromMessages(&$blocks) {
        $db = $this->getSinkDbForRead();
        $key = $db->firstkey();
        while ($key !== false) {
            $this->getUsedBlocksFromSingleMessageDb($key, $blocks);
            $key = $db->nextkey();
        }
        $this->closeSinkDb();
    }
    
    private function getUsedBlocksFromSingleMessageDb($ia58, &$blocks) {
        $db = $this->getMessageDbForRead($ia58);
        $key = $db->firstkey();
        while ($key !== false) {
            $data = $db->fetch($key);
            $json = json_decode($data, true);
            if (isset($json["blocks"])) {
                foreach($json["blocks"] as $block) {
                    array_push($blocks, $block);
                }
            }
            $key = $db->nextkey();
        }
        $this->closeMessageDb($db);
    }
    
    private function cleanSingleBlockDatabase($prefix, &$blocks) {
        $db = $this->getBlockDbForWrite($prefix);
        $bid = $db->firstkey();
        $maxUnassigned = new BigInteger($this->config->getMaxUnassignedBlockAge());
        while ($bid !== false) {
            if (!in_array($bid, $blocks)) {
                $value = $db->fetch($bid);
                $timestamp = Utils::biFrom64bit(substr($value, 0, 8));
                $elapsedTime = Utils::timeMili()->sub($timestamp);
                if ($elapsedTime->cmp($maxUnassigned) > 0) {
                    $this->logger->debug(
                        "Orphan block " . $bid . " removed - too old " . $elapsedTime->toDec() . "/" . $maxUnassigned->toDec()
                    );
                    $db->delete($bid);
                }
                else {
                    $this->logger->debug(
                        "Orphan block " . $bid . " but still too young " . $elapsedTime->toDec() . "/" . $maxUnassigned->toDec()
                    );
                }
            }
            $bid = $db->nextkey();
        }
        $this->closeBlockDb($db);
    }
}
