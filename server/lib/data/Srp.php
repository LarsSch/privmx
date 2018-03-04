<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\data;

use io\privfs\core\JsonRpcException;
use io\privfs\core\SrpLogic;
use io\privfs\core\LogDb;
use io\privfs\core\Utils;
use io\privfs\config\Config;

/*========================*/
/*           SRP          */
/*========================*/

class Srp {
    private $sessionHolder;
    private $user;
    private $conf;
    private $logger;
    private $loginAttemptsLog;
    private $config;
    
    public function __construct(SessionHolder $sessionHolder, User $user, LogDb $loginAttemptsLog, Config $config) {
        $this->logger = \io\privfs\log\LoggerFactory::get($this);
        $this->sessionHolder = $sessionHolder;
        $this->user = $user;
        $this->conf = SrpLogic::getConfig();
        $this->loginAttemptsLog = $loginAttemptsLog;
        $this->config = $config;
    }
    
    public function info() {
        return array(
            "N" => $this->conf["N"]->toHex(),
            "g" => $this->conf["g"]->toHex()
        );
    }
    
    public function init($I, $host, $properties) {
        $this->logger->debug("init", array("I" => $I, "host" => $host, "properties" => $properties));
        if ($this->detectAttack()) {
            $this->saveLoginAttempt($I, false, "LOGIN_REJECTED");
            sleep(2);
            throw new JsonRpcException("LOGIN_REJECTED");
        }
        $user = $this->user->getSrpUser($I, $host);
        if (is_null($user)) {
            $this->saveLoginAttempt($I, false, "USER_DOESNT_EXIST");
            throw new JsonRpcException("USER_DOESNT_EXIST");
        }
        $session = array();
        $session["state"] = "init";
        $session["properties"] = $properties;
        $session["N"] = $this->conf["N"];
        $session["g"] = $this->conf["g"];
        $session["k"] = $this->conf["k"];
        $session["user"] = $user;
        
        $session["b"] = SrpLogic::get_b();
        $session["B"] = SrpLogic::get_big_B($session["g"], $session["N"], $session["k"], $session["b"], $session["user"]["v"]);
        
        $session["id"] = $this->sessionHolder->saveSession($session);
        
        return array(
            "sessionId" => $session["id"],
            "N" => $session["N"]->toHex(),
            "g" => $session["g"]->toHex(),
            "k" => $session["k"]->toHex(),
            "s" => bin2hex($session["user"]["s"]),
            "B" => $session["B"]->toHex(),
            "loginData" => $session["user"]["loginData"]
        );
    }
    
    public function exchange($sessionId, $A, $M1, $withK = false) {
        $this->logger->debug("exchange", array("A" => $A, "M1" => $M1));
        $A = $A["bi"];
        $M1 = $M1["bi"];
        
        $session = $this->sessionHolder->restoreSession($sessionId, false);
        if (is_null($session)) {
            throw new JsonRpcException("UNKNOWN_SESSION");
        }
        $username = $session["user"]["I"];
        if ($this->detectAttack()) {
            $this->saveLoginAttempt($username, false, "LOGIN_REJECTED");
            sleep(2);
            throw new JsonRpcException("LOGIN_REJECTED");
        }
        if ($session["state"] != "init") {
            throw new JsonRpcException("INVALID_SESSION_STATE");
        }
        if (SrpLogic::valid_A($A, $session["N"]) == false) {
            throw new JsonRpcException("INVALID_A");
        }
        $session["state"] = "exchange";
        $session["A"] = $A;
        $session["clientM1"] = $M1;
        
        $session["u"] = SrpLogic::get_u($session["A"], $session["B"], $session["N"]);
        $session["S"] = SrpLogic::getServer_S($session["A"], $session["user"]["v"], $session["u"], $session["b"], $session["N"]);
        $session["serverM1"] = SrpLogic::get_M1($session["A"], $session["B"], $session["S"], $session["N"]);
        $this->logger->debug("Server M1", array(
            "A" => $session["A"]->toHex(),
            "B" => $session["B"]->toHex(),
            "S" => $session["S"]->toHex(),
            "N" => $session["N"]->toHex(),
            "M1" => $session["serverM1"]->toHex()
        ));
        
        if ($session["serverM1"]->cmp($session["clientM1"]) != 0) {
            $this->saveLoginAttempt($username, false, "DIFFERENT_M1");
            throw new JsonRpcException("DIFFERENT_M1");
        }
        
        $session["M2"] = SrpLogic::get_M2($session["A"], $session["serverM1"], $session["S"], $session["N"]);
        $session["K"] = SrpLogic::get_big_K($session["S"], $session["N"], true);
        if (strlen($session["K"]) != 32) {
            $zeros = implode(array_map("chr", array_fill(0, 32 - strlen($session["K"]), 0)));
            $session["K"] = $zeros . $session["K"];
        }
        
        $this->sessionHolder->saveSession($session);
        
        $this->user->loginSuccess($session);
        
        $this->saveLoginAttempt($username, true);
        
        $result = array(
            "M2" => $session["M2"]->toHex()
        );

        if( $withK === true )
            $result["K"] = $session["K"];

        return $result;
    }
    
    protected function saveLoginAttempt($username, $success, $error = null) {
        $data = array(
          "success" => $success,
          "error" => $error,
          "time" => time(),
          "username" => $username,
          "ip" => Utils::getClientIp()
        );
        $this->loginAttemptsLog->add($data);
    }
    
    protected function detectAttack() {
      if (! empty($this->config->rawConfig["srpAttackAllowedErros"])) {
        $allowedErrors = $this->config->rawConfig["srpAttackAllowedErros"];
      } else {
        $allowedErrors = 16;
      }
      
      if (! empty($this->config->rawConfig["srpAttackCheckPeriod"])) {
        $checkPeriod = $this->config->rawConfig["srpAttackCheckPeriod"];
      } else {
        $checkPeriod = 64*60;
      }
      
      if (! empty($this->config->rawConfig["srpAttackPenalty"])) {
        $penalty = $this->config->rawConfig["srpAttackPenalty"];
      } else {
        $penalty = 64*60;
      }
      $ip = Utils::getClientIp();
      $detected = false;
      $now = time();
      $db = $this->loginAttemptsLog->init(false);
      $index = intval($db->fetch(LogDb::INDEX_KEY));
      $blacklist = $db->fetch("blacklist");
      if ($blacklist) {
        $blacklist = json_decode($blacklist, true);
      } else {
        $blacklist = array();
      }
      if (!empty($blacklist[$ip])) {
        if ($blacklist[$ip]["expires"] > $now) {
          $detected = true;
        }
      }
      if (!$detected) {
        $checkStart = $now - $checkPeriod;
        $errorsCount = 0;
        while ($index > 0) {
          $index--;
          $data = $db->fetch($index);
          if (!$data) {
            break;
          }
          $data = json_decode($data, true);
          if ($data["time"] < $checkStart) {
            break;
          }
          if ($data["ip"] === $ip && !$data["success"]) {
            $errorsCount++;
          }
        }
        if ($errorsCount >= $allowedErrors) {
          $detected = true;
          $blacklist[$ip] = array(
            "expires" => $now + $penalty
          );
          $db->replace("blacklist", json_encode($blacklist));
        }
      }
      $this->loginAttemptsLog->close();
      return $detected;
    }
}
