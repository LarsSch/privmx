<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

use io\privfs\config\Config;

class Engine {
    
    private $config;
    private $assetsPrefix = "";
    
    public function __construct(Config $config) {
        $this->config = $config;
    }
    
    public function addCrossDomainHeaders() {
        $origin = "*";
        if (isset($_SERVER['HTTP_ORIGIN'])) {
            $origin = $_SERVER['HTTP_ORIGIN'];
        }
        if ($this->config->isCrossDomainAjax()) {
            $domains = $this->config->getCorsDomains();
            if (in_array($origin, $domains, true) || in_array($origin, $this->config->getHosts(), true)) {
                header("Allow: OPTIONS,GET,POST");
                header("Access-Control-Allow-Origin: " . $origin);
                header("Access-Control-Allow-Credentials: true");
                header("Access-Control-Allow-Headers: Content-Type");
                header("Vary: Origin");
            }
        }
    }
    
    public function optionsResponse() {
        http_response_code(200);
        $this->addCrossDomainHeaders();
        die();
    }
    
    public function setHeaders($contentType = null, $responseCode = null) {
        if (is_null($responseCode)) {
            $responseCode = 200;
        }
        http_response_code($responseCode);
        if (!is_null($contentType)) {
            header("Content-Type: " . $contentType);
        }
        $this->addCrossDomainHeaders();
    }
    
    public function rawResponse($data, $contentType = null, $responseCode = null) {
        $this->setHeaders($contentType, $responseCode);
        print($data);
        die();
    }
    
    public function rawJsonResponse($data, $responseCode = null) {
        $this->rawResponse($data, "application/json", $responseCode);
    }
    
    public function jsonResponse($data, $responseCode = null) {
        $this->rawJsonResponse(is_null($data) ? "" : json_encode($data), $responseCode);
    }
    
    public function jsonRpcSuccessResponse($id, $result, $responseCode = null) {
        $this->jsonResponse(array(
            "jsonrpc" => "2.0",
            "id" => $id,
            "result" => $result
        ), $responseCode);
    }
    
    public function jsonRpcErrorResponse($id, $code, $responseCode = null) {
        global $_PRIVMX_GLOBALS;
        $error = $_PRIVMX_GLOBALS["error_codes"][$code];
        $this->jsonResponse(array(
            "jsonrpc" => "2.0",
            "id" => null,
            "error" => array(
                "code" => $error["code"],
                "message" => $error["message"]
            )
        ), $responseCode);
    }
    
    public function redirect($url) {
        header('Location: ' . $url);
        die();
    }
    
    public function getRequestContentType() {
        if (isset($_SERVER["HTTP_CONTENT_TYPE"])) {
            return $_SERVER["HTTP_CONTENT_TYPE"];
        }
        if (function_exists("getallheaders")) {
            $headers = getallheaders();
            if (isset($headers["Content-Type"])) {
                return $headers["Content-Type"];
            }
        }
        return null;
    }

    public function validateProtocol()
    {
        if( !$this->config->isForceHttps() )
            return;

        $protocol = "http";
        if( !empty($_SERVER['HTTPS']) && $_SERVER["HTTPS"] != "off" )
            $protocol = "https";
        else if( isset($_SERVER['HTTP_X_FORWARDED_PROTO']) )
            $protocol = $_SERVER['HTTP_X_FORWARDED_PROTO'];

        if( $protocol === "http" )
        {
            $url = "https://" . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"];
            $this->redirect($url);
        }
    }
}
