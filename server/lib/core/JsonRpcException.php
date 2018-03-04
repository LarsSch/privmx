<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

use Exception;

class JsonRpcException extends Exception {

    private $data;

    public function __construct($name, $data = null, $raw = false) {
        if ($raw) {
            parent::__construct($name, $data);
            $this->data = null;
        }
        else {
            global $_PRIVMX_GLOBALS;
            $e = $_PRIVMX_GLOBALS["error_codes"][$name];
            parent::__construct($e["message"], $e["code"]);
            $this->data = $data;
        }
    }

    public function setData($data = null) {
        $this->data = $data;
        return $this;
    }

    public function getData() {
        return $this->data;
    }

    public static function fromRemote($result) {
        if (!is_null($result) && isset($result["code"])) {
            $code = $result["code"];
            if ($code >= 0) {
                global $_PRIVMX_GLOBALS;
                foreach ($_PRIVMX_GLOBALS["error_codes"] as $name => $value) {
                    if ($value["code"] == $code) {
                        throw new JsonRpcException($name, Utils::arrayValue($result, "data", null));
                    }
                }
            }
            throw new \Exception("JsonRpc error: " . Utils::arrayValue($result, "message", ""), $code);
        }
        throw new \Exception("JsonRpc error");
    }
    
    public static function internal($ex) {
        if ($ex instanceof JsonRpcException) {
            throw $ex;
        }
        throw new JsonRpcException("INTERNAL_ERROR");
    }
}