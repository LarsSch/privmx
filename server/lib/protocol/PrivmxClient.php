<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\protocol;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use GuzzleHttp\Psr7\BufferStream;
use privmx\pki\keystore\KeyPair;
use io\privfs\config\Config;

class PrivmxClient implements \privmx\pki\IRpcClient
{
    private static $TICKETS_COUNT = 3;

    private $config = null;
    private $logger = null;

    private $promise = null; // flush/request promise
    private $stream = null;
    private $serverEndpoint = null;
    private $http = null;
    private $proxyEndpoints = array();

    public function __construct($uri, Config $config)
    {
        $this->config = $config;
        $this->logger = \io\privfs\log\LoggerFactory::get($this);
        $this->http = new Client(array(
            "connect_timeout" => 3,
            "timeout" => 60,
            "base_uri" => $uri,
            "verify" => $config->verifySSLCertificates(),
            "allow_redirects" => array(
                "strict" => true // redirect POST => POST
            )
        ));
        $this->stream = new BufferStream();
        $this->serverEndpoint = new PrivmxClientEndpoint($config, $this->stream);
    }

    public function connect(KeyPair $key = null, $hashmail = "", $pasword = "")
    {
        $this->logger->debug("connect");
        $connection = $this->serverEndpoint->getConnection();
        if( $key !== NULL )
        {
            $this->logger->debug("ecdhef handshake");
            $connection->ecdhefHandshake($key);
            $connection->ticketRequest(self::$TICKETS_COUNT);
            if( $hashmail === "" )
            {
                $this->flush();
                return;
            }
        }
        else
        {
            $this->logger->debug("ecdhe handshake");
            $connection->ecdheHandshake();
            $connection->ticketRequest(self::$TICKETS_COUNT);
            $this->flush();
            if( $hashmail === "" )
                return;
            $connection->reset();
            $connection->ticketHandshake();
        }

        $this->logger->debug("srp handshake");
        $connection->srpHandshake($hashmail, $pasword, self::$TICKETS_COUNT);
        // send srp init
        $this->flush();
        // send srp exchange
        $this->flush();
    }

    private function sendRequest()
    {
        $response = $this->http->request("POST", "", array(
            "Content-Type" => "application/octet-stream",
            "body" => $this->stream->getContents() // BufferStream cannot be rewinded on redirect
        ));
        $code = $response->getStatusCode();
        $this->serverEndpoint->flush(); // clear ticketHandshake flag
        if( $code !== 200 )
            throw new Exception("Server return code {$code}");

        return $response->getBody();
    }

    public function flush()
    {
        $promises = array();
        foreach($this->proxyEndpoints as $host => $endpoint)
        {
            $data = $endpoint->flush();
            if( strlen($data) === 0 )
                continue;
            $this->logger->debug("flush proxy for {$host}");
            $promise = $this->call("proxy", array(
                "destination" => $host,
                "encrypt" => false,
                "data" => $data
            ))->then(function($data) use (&$endpoint) {
                $this->logger->debug("proxy resolved, connection process data");
                $endpoint->process($data);
            });
            array_push($promises, $promise);
        }

        $this->logger->debug("flush");
        $error = null;
        try {
            $this->serverEndpoint->process($this->sendRequest());
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            $error = $e;
        }
        if( $this->promise !== null )
        {
            if( $error === null )
                $this->promise->resolve(null);
            else
                $this->promise->reject($error);
            $this->promise = null;
        }

        if( count($promises) > 0 )
            Promise\settle($promises)->wait();
    }

    private function getFlushPromise()
    {
        if( $this->promise === null )
        {
            $this->promise = new Promise\Promise(function() {
                $this->flush();
            });
        }
        return $this->promise;
    }

    public function sendRaw($data)
    {
        $this->stream->write($data);
        return $this->sendRequest()->getContents();
    }

    public function call($method, $params = null)
    {
        try {
            $promise = $this->serverEndpoint->call($method, $params);
        } catch(\Exception $error) {
            $this->logger->error($error->getMessage());
            return new Promise\RejectedPromise($error);
        }
        return $this->getFlushPromise()->then(function() use (&$promise) {
            return $promise;
        });
    }

    private function getProxyEndpoint($host)
    {
        if( !isset($this->proxyEndpoints[$host]) )
        {
            // proxy handshake
            $endpoint = new PrivmxClientEndpoint($this->config);
            $endpoint->getConnection()->ecdheHandshake();
            $endpoint->getConnection()->ticketRequest(self::$TICKETS_COUNT);
            $this->proxyEndpoints[$host] = $endpoint;
            // flush handshake
            $this->flush();
        }
        return $this->proxyEndpoints[$host];
    }

    public function proxy($host, $method, $params = null)
    {
        $endpoint = $this->getProxyEndpoint($host);
        $promise = $endpoint->call($method, $params);
        return $this->getFlushPromise()->then(function() use (&$promise) {
            return $promise;
        });
    }
};

?>
