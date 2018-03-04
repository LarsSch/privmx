<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\data;

use io\privfs\core\JsonRpcException;
use io\privfs\core\ECIES;
use io\privfs\core\Nonce;
use io\privfs\core\ECUtils;

class KeyLogin {
    
    private $sessionHolder;
    private $user;
    private $nonce;
    
    public function __construct(SessionHolder $sessionHolder, User $user, Nonce $nonce) {
        $this->sessionHolder = $sessionHolder;
        $this->user = $user;
        $this->nonce = $nonce;
    }
    
    public function init($pub, $properties) {
        $user = $this->user->getKeyUser($pub);
        if (is_null($user)) {
            throw new JsonRpcException("USER_DOESNT_EXIST");
        }
        $priv = ECUtils::generateRandom();
        
        $session = array();
        $session["properties"] = $properties;
        $session["state"] = "keyinit";
        $session["user"] = $user;
        $session["priv"] = ECUtils::toWIF($priv);
        
        $session["id"] = $this->sessionHolder->saveSession($session);
        
        return array(
            "sessionId" => $session["id"],
            "I" => $user["I"],
            "pub" => ECUtils::publicToBase58DER($priv)
        );
    }
    
    public function exchange($sessionId, $nonce, $timestamp, $signature, $K, $returnK = false) {
        $session = $this->sessionHolder->restoreSession($sessionId, false);
        if (is_null($session)) {
            throw new JsonRpcException("UNKNOWN_SESSION");
        }
        if ($session["state"] != "keyinit") {
            throw new JsonRpcException("INVALID_SESSION_STATE");
        }
        $pub = array("ecc" => ECUtils::publicFromBase58DER($session["user"]["pub"]), "base58" => $session["user"]["pub"]);
        $this->nonce->nonceCheck("login" . $K["base64"], $pub, $nonce, $timestamp, $signature);
        
        $ecies = new ECIES(ECUtils::fromWIF($session["priv"]), $pub["ecc"]);
        $session["state"] = "exchange";
        $session["K"] = $ecies->decrypt($K["bin"]);
        
        $this->sessionHolder->saveSession($session);
        
        $this->user->loginSuccess($session);
        
        if( $returnK === true )
            return $session["K"];
        return "OK";
    }
}
