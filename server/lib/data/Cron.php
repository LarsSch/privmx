<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\data;

use io\privfs\core\GarbageCollector;
use io\privfs\core\Nonce;

class Cron {
    
    private $garbageCollector;
    private $blockCleaner;
    private $notifier;
    private $eventManager;
    private $nonce;
    private $logger;
    
    public function __construct(GarbageCollector $garbageCollector, BlockCleaner $blockCleaner,
        Notifier $notifier, EventManager $eventManager, Nonce $nonce) {
        
        $this->garbageCollector = $garbageCollector;
        $this->blockCleaner = $blockCleaner;
        $this->notifier =  $notifier;
        $this->eventManager = $eventManager;
        $this->nonce = $nonce;
        $this->logger = \io\privfs\log\LoggerFactory::get($this);
    }
    
    public function cleanup() {
        $this->blockCleaner->removeNotUsedBlocks();
        $this->nonce->cleanNonceDb();
    }
    
    public function execute() {
        $this->garbageCollector->func = array($this, "cleanup");
        $this->garbageCollector->start();
        $this->notifier->start();
        $this->eventManager->run();
    }
}
