<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\data;

use io\privfs\core\ECUtils;
use privmx\pki\PrivmxPKI;

class PrivFsUser {
    
    private $pki;
    private $logger;
    
    public function __construct(PrivmxPKI $pki) {
        $this->pki = $pki;
        $this->logger = \io\privfs\log\LoggerFactory::get($this);
    }
    
    public static function parseHashmail($hashmail) {
        if (!is_string($hashmail) || strlen($hashmail) < 3) {
            return false;
        }
        $parts = explode("#", $hashmail);
        if (count($parts) != 2) {
            return false;
        }
        return array(
            "hashmail" => $hashmail,
            "user" => $parts[0],
            "domain" => $parts[1]
        );
    }
    
    public function validateHashmail($hashmail, $identityKey) {
        return $this->validateParsedHashmail(PrivFsUser::parseHashmail($hashmail), $identityKey);
    }
    
    public function validateParsedHashmail($hashmail, $identityKey) {
        if ($hashmail === false) {
            return false;
        }
        try {
            $msg = $this->pki->getKeyStoreCore(true, "user:" . $hashmail["user"], array("domain" => $hashmail["domain"]));
            $keystore = $msg ? $msg->getKeyStore() : null;
            $key = $keystore ? $keystore->getPrimaryKey()->keyPair : null;
            return $key !== null && $identityKey === ECUtils::publicToBase58DER($key);
        }
        catch (\Exception $e) {
            $this->logger->error("validateHashmail error: " . $e->getMessage());
            return false;
        }
    }
}
