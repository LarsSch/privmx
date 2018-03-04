<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\data;

use io\privfs\core\Utils;
use GuzzleHttp\Psr7\ServerRequest;

class Api {
    private $ioc;
    private $logger;
    
    public function __construct($ioc) {
        $this->ioc = $ioc;
        $this->logger = \io\privfs\log\LoggerFactory::get($this);
    }
    
    public function api() {
        $this->logger->debug("Api request, only versions method allowed!");
        $engine = $this->ioc->getEngine();
        $engine->validateProtocol();

        if ($_SERVER["REQUEST_METHOD"] == "OPTIONS") {
            $engine->optionsResponse(); // die
        }

        $id = null;
        if ( $_SERVER["REQUEST_METHOD"] == "POST" )
        {
            $request = ServerRequest::fromGlobals();
            $rpc_request = json_decode($request->getBody()->getContents(), true);
            if( isset($rpc_request["id"]) )
                $id = $rpc_request["id"];

            if( isset($rpc_request["method"]) && $rpc_request["method"] == "versions" )
            {
                $versions = array();
                $files = scandir(__DIR__ . "/../../api/");
                foreach($files as $file)
                {
                    if( $file[0] === "v" && is_dir($file) )
                        array_push($versions, $file);
                }
                $engine->jsonRpcSuccessResponse($id, $versions); // die
            }
            else if (isset($rpc_request["method"]) && $rpc_request["method"] == "versionsWithTest" &&
                    isset($rpc_request["params"]) && gettype($rpc_request["params"]) == "array" &&
                    isset($rpc_request["params"]["test"]) && gettype($rpc_request["params"]["test"]) == "string") {
                
                $test = $this->ioc->getAntiSelfRequestCache()->get($rpc_request["params"]["test"]);
                $versions = array();
                $files = scandir(__DIR__ . "/../../api/");
                foreach($files as $file)
                {
                    if( $file[0] === "v" && is_dir($file) )
                        array_push($versions, $file);
                }
                $engine->jsonRpcSuccessResponse($id, array("versions" => $versions, "test" => $test)); // die
            }
        }

        $engine->jsonRpcErrorResponse($id, "INVALID_REQUEST");
    }

    public function v2_0()
    {
        $this->logger->debug("Api request v 2.0");
        $engine = $this->ioc->getEngine();
        $engine->validateProtocol();

        if ($_SERVER["REQUEST_METHOD"] == "OPTIONS") {
            $this->logger->notice("OPTIONS request");
            $engine->optionsResponse();
        }

        global $_PRIVMX_GLOBALS;
        if ($_SERVER["REQUEST_METHOD"] != "POST") {
            $this->logger->notice("Request method is not OPTIONS or POST, rejecting 405");
            $engine->jsonResponse(array(
                "jsonrpc" => "2.0",
                "id" => null,
                "error" => $_PRIVMX_GLOBALS["error_codes"]["ONLY_POST_METHOD_ALLOWED"]
            ), 405);
        }

        $contentType = $engine->getRequestContentType();
        if( isset($contentType) )
        {
            if( !Utils::startsWith($contentType, "application/octet-stream") )
            {
                $this->logger->notice("Content-Type is not 'application/octet-stream', rejecting 400");
                $engine->jsonResponse(array(
                    "msg" => "Invalid transport type"
                ), 400);
            }
        }

        $endpoint = $this->ioc->getServerEndpoint();
        $this->logger->notice("Processing api request v2.0");
        $engine->setHeaders("application/octet-stream");
        $endpoint->execute();
    }
}
