<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
namespace privmx\pki;

use \Exception;
use \GuzzleHttp\Client;
use \PSON\StaticPair;
use \PSON\ByteBuffer;

class RpcClient implements IRpcClient
{
    private $uri = "";
    private $http = null;
    private $encoder = null;
    private $id = 0;

    public function __construct($uri)
    {
        $this->uri = $uri;
        $this->http = new Client();
        $this->encoder = new StaticPair();
    }

    public function call($method, $params)
    {
        $rpc = array(
            "id" => ++$this->id,
            "method" => $method,
            "params" => $params
        );

        $body = $this->encoder->encode($rpc)->toBinary();
        return $this->http->requestAsync("POST", $this->uri, array(
            "Content-Type" => "application/octet-stream",
            "body" => $body
        ))->then(function ($response) {
            $uri = $this->uri;
            $code = $response->getStatusCode();
            if( $code !== 200 )
                throw new Exception("Response code {$code} from uri {$uri}");

            $data = ByteBuffer::wrap($response->getBody()->getContents());
            $rpc = (array)$this->encoder->decode($data);

            if( isset($rpc["error"]) && $rpc["error"] !== null )
                throw new Exception("Error from uri {$uri}");

            if( !isset($rpc["result"]) || $rpc["result"] === null )
                throw new Exception("Missing result from uri {$uri}");

            return $rpc["result"];
        });
    }
};

class RpcClientFactory implements IRpcClientFactory
{
    private $clients = array();
    public function getClient($domain)
    {
        if( !isset($this->clients[$domain]) )
            $this->clients[$domain] = new RpcClient($domain);
        return $this->clients[$domain];
    }
};

?>
