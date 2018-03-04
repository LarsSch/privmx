<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace simplito;

use Doctrine\Common\Cache\FilesystemCache;
use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use GuzzleHttp\Promise\PromiseInterface;

class PrivMXServiceDiscovery {
  
  static $configPaths = array(
    '/privmx/privmx-configuration.json',
    '/.well-known/privmx-configuration.json',
    '/privmx-configuration.json'
  );
  
  const MAX_DNS_HOPS = 5;
  
  private $cache;
  private $dnsHopsCounter = 0;
  private $client;
  
  public function __construct($cacheDir = null, $verifySSLCertificates = true) {
    if (! $cacheDir) {
      $cacheDir =  '/tmp/privmx-service-discovery-cache';
    }
    $this->cache = new FilesystemCache($cacheDir);
    $options = array("connect_timeout" => 3, "timeout" => 10);
    if( $verifySSLCertificates === false )
      $options["verify"] = false;
    $this->client = new Client($options);
  }
  
  public function discover($host, $dnsOnly = false) {
    $host = trim($host);
    $encodedConfig = $this->cache->fetch($host);
    $config = $encodedConfig ? Config::fromJSON($encodedConfig) : null;
    if (! $config) {
      if ($dnsOnly) {
        $config = $this->discoverDNS($host);
      } else {
        $config = $this->discoverJSON($host);
        if (!$config) {
          $config = $this->discoverDNS($host);
        }
      }
      if ($config) {
        $this->cache->save($host, json_encode($config), $config->ttl ? $config->ttl : 600);
      }
    }
    return $config;
  }
  
  private function getConfigLocations($host) {
    $locations = array();
    foreach (self::$configPaths as $path) {
      array_push($locations, 'https://' . $host . $path);
      array_push($locations, 'http://' . $host . $path);
    }
    return $locations;
  }
  
  private function fetchConfig($url) {
    return $this->client->getAsync($url)
      ->then(function($response) use ($url){
        $body = $response->getBody();
        $config = Config::fromJSON($body);
        return new FetchResult($url, $config);
      });
  }
  
  public function discoverJSON($host) {
    $locations = $this->getConfigLocations($host);
    $sync = array_slice($locations, 0, 2);
    $locations = array_slice($locations, 2);
    foreach($sync as $url)
    {
      try
      {
        return $this->fetchConfig($url)->wait()->getConfig();
      }
      catch(\Exception $e) { /* no-op */ }
    }
    $promises = array_map(array($this, 'fetchConfig'), $locations);
    $results = Promise\settle($promises)->wait();
    $httpConfig = null;
    foreach ($results as $result) {
      if ($result['state'] === PromiseInterface::FULFILLED) {
        $fetchResult = $result['value'];
        if ($this->startsWith($fetchResult->getLocation(), 'https://')) {
          return $fetchResult->getConfig();
        } else {
          if (! $httpConfig) {
            $httpConfig = $fetchResult->getConfig();
          }
        }
      }
    }
    return $httpConfig;
  }
  
  public function discoverDNS($host) {
    $records = dns_get_record($host, DNS_TXT);
    if ($records) {
      foreach ($records as $record) {
        $parsed = $this->parseTxtRecord($record);
        if (array_key_exists('v', $parsed) && $parsed['v'] === 'privmx1') {
          if (isset($parsed['redirect'])) {
            if ($this->dnsHopsCounter < PrivMXServiceDiscovery::MAX_DNS_HOPS) {
              $this->dnsHopsCounter++;
              return $this->discover($parsed['redirect']);
            }
          }
          if (isset($parsed['default_endpoint']) || isset($parsed['privmx_endpoint'])) {
            unset($parsed['v']);
            if (empty($parsed['ttl'])) {
              $parsed['ttl'] = $record['ttl'];
            }
            $config = Config::fromArray($parsed);
            return $config;
          }
        }
      }
    }
    return null;
  }
  
  private function parseTxtRecord($record) {
    $parsed = array_map('trim', explode(';', $record['txt']));
    $result = array();
    foreach ($parsed as $value) {
      $v = explode('=', $value);
      $result[$v[0]] = $v[1];
    }
    return $result;
  }
  
  private function startsWith($haystack, $needle) {
    $length = strlen($needle);
    return (substr($haystack, 0, $length) === $needle);
  }
  
}
