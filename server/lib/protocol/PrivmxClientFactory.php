<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\protocol;

use io\privfs\config\Config;
use \simplito\PrivMXServiceDiscovery;
use io\privfs\core\Utils;

class PrivmxClientFactory implements \privmx\pki\IRpcClientFactory {
    
    const DEFAULT_API_VERSION = "v2.0";
    
    private $config = null;
    private $clients = array();
    private $knownHosts = array();
    private $serviceDiscovery = null;
    private $antiSelfRequestCache;
    
    /**
     * @param Config config
     * @param map<string, KeyStore> known_hosts - map of known hostnames to servers keystores
     */
    public function __construct(Config $config, PrivMXServiceDiscovery $serviceDiscovery, $antiSelfRequestCache, $knownHosts = null) {
        $this->config = $config;
        if ($knownHosts !== null) {
            $this->knownHosts = $knownHosts;
        }
        $this->serviceDiscovery = $serviceDiscovery;
        $this->antiSelfRequestCache = $antiSelfRequestCache;
    }
    
    private function getHostKey($host) {
        if (!isset($this->knownHosts[$host])) {
            return null;
        }
        $keystore = $this->knownHosts[$host];
        return $keystore->getPrimaryKey();
    }
    
    protected function getClientEndpoint($host) {
        $config = $this->serviceDiscovery->discover($host);
        $endpoint = $this->getClientEndpointFromConfig($config);
        if ($endpoint == null) {
            throw new \Exception("Cannot resolve endpoint for: {$host}");
        }
        return $endpoint;
    }
    
    protected function getClientEndpointFromConfig($config) {
        $apiBaseEndpoint = $this->getApiBaseEndpoint($config);
        if ($apiBaseEndpoint == null) {
            return null;
        }
        $urls = $this->resolveApiBaseEndpoint($apiBaseEndpoint);
        $cfg = $this->chooseApiBaseEndpoint($urls);
        
        $version = self::DEFAULT_API_VERSION;
        if ($cfg == null || !isset($cfg["versions"]) || !is_array($cfg["versions"]) || !in_array($version, $cfg["versions"])) {
            return null;
        }
        return $cfg["apiBaseEndpoint"] . $version . "/";
    }
    
    protected function getApiBaseEndpoint($config) {
        if (isset($config->apiBaseEndpoint)) {
            return $this->sanitizeUrl($config->apiBaseEndpoint);
        }
        if (isset($config->defaultEndpoint)) {
            return $this->sanitizeUrl($config->defaultEndpoint) . "api/";
        }
        return null;
    }
    
    protected function sanitizeUrl($url) {
        $url = trim($url);
        return Utils::endsWith($url, "/") ? $url : $url . "/";
    }
    
    protected function resolveApiBaseEndpoint($apiBaseEndpoint) {
        if (Utils::startsWith($apiBaseEndpoint, "//")) {
            return array("https:" . $apiBaseEndpoint, "http:" . $apiBaseEndpoint);
        }
        return array($apiBaseEndpoint);
    }

    private function getVersions($url)
    {
        // SIMPLE JSON RPC WITHOUT ENCRYPTION
        $testId = bin2hex(\io\privfs\core\Crypto::randomBytes(10));
        $testValue = bin2hex(\io\privfs\core\Crypto::randomBytes(10));
        $this->antiSelfRequestCache->set($testId, $testValue);
        $rpc_request = array(
            "id" => 0, "method" => "versionsWithTest",
            "params" => array("test" => $testId), "jsonrpc" => "2.0"
        );
        $client = new \GuzzleHttp\Client(array("connect_timeout" => 3, "timeout" => 60));
        $response = $client->request("POST", $url, array(
            "verify" => $this->config->verifySSLCertificates(),
            "json" => $rpc_request
        ));
        $this->antiSelfRequestCache->remove($testId);
        $code = $response->getStatusCode();
        if( $code !== 200 )
            throw new \Exception("Server return code $code. URL: $url");
        $rpc_response = json_decode($response->getBody()->getContents());
        if ($rpc_response->result->test == $testValue) {
            throw new SelfRequestException();
        }
        return $rpc_response->result->versions;
    }
    
    protected function chooseApiBaseEndpoint($urls) {
        foreach ($urls as $url) {
            try {
                $versions = $this->getVersions($url);
            }
            catch (SelfRequestException $e) {
                throw $e;
            }
            catch (\Exception $e) {
                continue;
            }
            return array("apiBaseEndpoint" => $url, "versions" => $versions);
        }
        return null;
    }
    
    //====================================
    
    public function getRawClient($host, $version = null) {
        $uri = $this->getClientEndpoint($host);
        $client = new PrivmxClient($uri, $this->config);
        return $client;
    }
    
    public function getClient($host) {
        if (!isset($this->clients[$host])) {
            $client = $this->getRawClient($host);
            // Using ecdhef handshake if host keystore is known
            $client->connect($this->getHostKey($host));
            $this->clients[$host] = $client;
        }
        return $this->clients[$host];
    }
}
