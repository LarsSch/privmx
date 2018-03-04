<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki;

use \Exception;
use \PSON\ByteBuffer;
use \privmx\pki\keystore\KeyStore;
use \privmx\pki\keystore\IPkiData;
use \privmx\pki\keystore\PkiData;
use \privmx\pki\keystore\Signature;
use \privmx\pki\keystore\SignatureType;
use \privmx\pki\messages\VRFMessage;
use \privmx\pki\messages\TreeMessage;
use \privmx\pki\messages\HistoryMessage;
use \privmx\pki\messages\MessageBase;
use \privmx\pki\messages\KeyStoreMessage;
use \privmx\pki\messages\TreeSignaturesMessage;

use \GuzzleHttp\Promise;

class PrivmxPKI
{
    const TREE_EXPIRATION_TIME = 3900000; // 1h 5min
    
    private $config;
    private $cache;
    private $clientFactory;
    private $options;
    private $cosigners;
    private $cosignersProvider;
    private $client;
    private $id;

    public function __construct($config, IRpcClientFactory $clientFactory = null, $cosignersProvider = null)
    {
        $this->config = $config;
        if( $clientFactory === null )
            $clientFactory = new RpcClientFactory();
        $this->clientFactory = $clientFactory;
        $this->cosignersProvider = $cosignersProvider;
        if (isset($config["privmx.pki.cache_factory"])) {
            $dbFactory = $config["privmx.pki.cache_factory"];
        }
        else {
            $path = isset($config["privmx.pki.cache_path"]) ? $config["privmx.pki.cache_path"] : "data/cache.dbm";
            $engine = isset($config["privmx.pki.cache_engine"]) ? $config["privmx.pki.cache_engine"] :
                (isset($config["privmx.pki.dbengine"]) ? $config["privmx.pki.dbengine"] : "db4");
            $lockFlag = isset($config["privmx.pki.cache_lockflag"]) ? $config["privmx.pki.cache_lockflag"] :
                (isset($config["privmx.pki.dblockflag"]) ? $config["privmx.pki.dblockflag"] : "l");
            $dbFactory = new DatabaseFactory($path, $engine, $lockFlag);
        }
        $this->cache = new KVStore($dbFactory);
        $this->client = null;
        $this->id = 0;
    }

    private function getTree($revision = null)
    {
        $tree = new Tree($this->config);
        if( $revision !== null )
            $tree->checkout($revision);
        return $tree;
    }

    private function getVRFMessage($name, VRFFactory $factory)
    {
        $this->cache->setNamespace("vrf." . $factory->getVRFKeyId());
        $idx = hash("sha256", $name, true);
        $result = $this->cache->fetch($idx);
        if( $result === false )
        {
            $result = $factory->getVRF($name, true);
            $this->cache->save($idx, $result->encode());
        }
        else
            $result = VRFMessage::decode($result);

        return $result;
    }

    private function get($array, $name, $default = null)
    {
        if( !is_array($array) || !isset($array[$name]) )
            return $default;
        return $array[$name];
    }

    public function setOptions($options)
    {
        $this->options = $this->overwriteOptions($options);
        $this->cache->setNamespace("pki.config");
        return $this->cache->save("options", $this->options);
    }

    public function getOptions()
    {
        if( $this->options )
            return $this->options;

        $this->cache->setNamespace("pki.config");
        $this->options = $this->cache->fetch("options");
        if( !$this->options )
            return array();
        return $this->options;
    }

    private function overwriteOptions($options)
    {
        if( $options === null )
            return $this->getOptions();
        return array_merge($this->getOptions(), $options);
    }

    private function getDomainFromPHP()
    {
        $default = $_SERVER["SERVER_NAME"];
        $port = $_SERVER["SERVER_PORT"];
        if ($port !== "80") {
            $default .= ":" . $port;
        }
        return $default;
    }

    private function domain()
    {
        if (isset($this->config["privmx.pki.domains"])) {
            return $this->config["privmx.pki.domains"][0];
        }
        return $this->getDomainFromPHP();
    }
    
    private function isMyDomain($domain) {
        if (isset($this->config["privmx.pki.domains"])) {
            return in_array($domain, $this->config["privmx.pki.domains"]);
        }
        return $this->getDomainFromPHP() == $domain;
    }

    private function remoteCall($domain, $method, $params)
    {
        $client = $this->clientFactory->getClient($domain);
        return $client->call($method, $params);
    }

    public function getServerKeyStore($private = false)
    {
        $tree = $this->getTree();
        $keystore = $tree->getKeyStore();
        if( $private === true )
            return $keystore;

        return $keystore->getPublicView();
    }

    /**
     * Options:
     * - "domain" string
     * - "noCache" boolean
     *
     * @param string name
     * @param array options
     * @param bytes revision
     *
     * @return KeyStoreMessage
     */
    public function getKeyStore($name, array $options = null, $revision = null, $includeAttachments = false)
    {
        $allowForeign = isset($this->config["privmx.pki.proxy_get_keystore"]) && $this->config["privmx.pki.proxy_get_keystore"] === true;
        return $this->getKeyStoreCore($allowForeign, $name, $options, $revision, $includeAttachments);
    }
    
    public function getKeyStoreCore($alllowForeign, $name, array $options = null, $revision = null, $includeAttachments = false)
    {
        $serverOptions = $this->overwriteOptions($options);
        $domain = $this->get($serverOptions, "domain");
        if( $domain === null || $this->isMyDomain($domain) )
        {
            // get KeyStore from local store
            $tree = $revision === null ? $this->getTree() : $this->getTreeByRevision($revision);
            $vrf = $this->getVRFMessage($name, $tree);
            $path = new AuthPath();
            $leaf = $tree->lookup($vrf->getValue(), $path);

            $serverKeystore = $this->getServerKeyStore();

            $response = new KeyStoreMessage(
                $tree->toMessage(), $vrf, $path->toMessage(),
                $leaf !== null ? $leaf->toMessage() : null,
                $serverKeystore
            );
            if (!is_null($leaf)) {
                $response->getLeaf()->setKeyStore($response->getLeaf()->getKeyStore()->getAttachmentView($includeAttachments));
            }

            return $response;
        }
        
        if (!$alllowForeign) {
            throw new Exception("Disabled fetching keystore from foreign domain {$domain}");
        }

        $params = array(
            "name" => $name,
            "options" => $options,
            "includeAttachments" => $includeAttachments
        );

        if( $revision !== null )
            $params["revision"] = $revision;

        // get KeyStore from remote store
        $promise = $this->remoteCall(
            $domain, "getKeyStore", $params
        );

        Promise\settle($promise);
        $result = $promise->wait();
        if( $promise->getState() !== Promise\Promise::FULFILLED )
            throw new Exception("Cannot get KeyStore from {$domain}");

        $response = KeyStoreMessage::decode($result);
        $keystore = $this->getDomainKeyStore($domain);
        $hash = $response->getTree()->getHash();
        if( !$response->verify($name, $keystore) || !$this->verifyKeyStore($domain, $hash) )
            throw new Exception("Invalid keystore from {$domain}");
        if( $response->getTree()->getTimestamp() + self::TREE_EXPIRATION_TIME < Utils::tstamp() )
            throw new Exception("Invalid keystore from {$domain}, tree head is too old");

        if( $keystore === null )
            $this->saveDomainKeyStore($domain, $response->getServerKeyStore());

        return $response;
    }
    
    public function exists($name) {
        $msg = $this->getKeyStore($name);
        return !is_null($msg->getKeystore());
    }

    private function modifyKeyStore($insert, $name, IPkiData $keystore, Signature $kis, array $options)
    {
        $tree = $this->getTree();
        if( !$tree->validateKisConiksSubpacket($kis) )
            throw new Exception("Invalid ConiksDbIdSubpacket");
        
        $vrf = $this->getVRFMessage($name, $tree);
        if ($insert === "auto") {
            $oldLeaf = $tree->lookup($vrf->getValue(), $path = new AuthPath());
            $insert = is_null($oldLeaf);
        }
        if( $insert === true )
            $tree->insert($vrf->getValue(), $name, $keystore, $kis);
        else
            $tree->update($vrf->getValue(), $keystore, $kis);
        $tree->commit();

        $path = new AuthPath();
        $leaf = $tree->lookup($vrf->getValue(), $path);
        $serverKeystore = $this->getServerKeyStore();

        return new KeyStoreMessage(
            $tree->toMessage(), $vrf, $path->toMessage(),
            $leaf->toMessage(), $serverKeystore
        );
    }

    private function unpackParams($name, $keystore, $kis, $options)
    {
        if( $name instanceof KeyStore )
        {
            $options = $kis;
            $kis = $keystore;
            $keystore = $name;
            $name = $keystore->getPrimaryUserId();
        }

        if( !($kis instanceof Signature) )
        {
            $options = $this->overwriteOptions($kis);
            $tree = $this->getTree();
            $kis = $keystore->generateKis($tree->hash);
        }
        else
        {
            $options = $this->overwriteOptions($options);
        }

        $keystore = $keystore->getPublicView();

        return array($name, $keystore, $kis, $options);
    }

    /**
     * Possible calls:
     * 1. inserKeyStore($keystore); - requires KeyStore with private key, generates kis, name is primary user id
     * 2. inserKeyStore($name, $keystore); - requires KeyStore with private key, generates kis
     * 3. inserKeyStore($keystore, $options); - same as 1. with options
     * 4. inserKeyStore($name, $keystore, $options); - same as 2. with options
     * 5. inserKeyStore($keystore, $kis); - same as 1., doesn't require KeyStore with private key, doesn't generate kis
     * 6. inserKeyStore($name, $keystore, $kis); - same as 2., doesn't require KeyStore with private key, doesn't generate kis
     * 7. inserKeyStore($keystore, $kis, $options); - same as 5. with options
     * 8. inserKeyStore($name, $keystore, $kis, $options);
     *
     * @param string name
     * @param KeyStore keystore
     * @param Signature kis
     * @param array options - see getKeyStore
     * 
     * @return KeyStoreMessage
     */
    public function insertKeyStore($name, $keystore = null, $kis = null, $options = null)
    {
        list($name, $keystore, $kis, $options) = $this->unpackParams($name, $keystore, $kis, $options);
        return $this->modifyKeyStore(true, $name, $keystore, $kis, $options);
    }

    /**
     * @see insertKeyStore
     */
    public function updateKeyStore($name, $keystore = null, $kis = null, $options = null)
    {
        list($name, $keystore, $kis, $options) = $this->unpackParams($name, $keystore, $kis, $options);
        return $this->modifyKeyStore(false, $name, $keystore, $kis, $options);
    }

    /**
     * @see insertKeyStore
     */
    public function insertOrUpdateKeyStore($name, $keystore = null, $kis = null, $options = null)
    {
        list($name, $keystore, $kis, $options) = $this->unpackParams($name, $keystore, $kis, $options);
        return $this->modifyKeyStore("auto", $name, $keystore, $kis, $options);
    }

    /**
     * @param string revision - stop when reached revision
     * @param integer seq - stop when reached seq
     * @param integer timestamp - stop when reached timestamp
     *
     * @return array
     */
    public function getHistory($revision = "", $seq = -1, $timestamp = 0)
    {
        $tree = $this->getTree()->getSimpleTree();
        $history = $tree->db->withContext(function() use ($tree, $revision, $seq, $timestamp) {
            $history = array();
            while (true) {
                if ($tree->data["hash"] === $revision || $tree->timestamp <= $timestamp || $tree->data["seq"] <= $seq) {
                    break;
                }
                array_push($history, $tree->getSerializedTreeMessage());
                if ($tree->data["seq"] === 0) {
                    break;
                }
                $tree->checkoutPrev();
            }
            return $history;
        });
        return array(
            "serverKeystore" => MessageBase::toByteBuffer($this->getServerKeyStore()->encode("binary")),
            "history" => $history
        );
    }

    /**
     * @param integer timestamp - stop when reached timestamp
     *
     * @return Tree
     */
    public function getTreeByTime($timestamp)
    {
        $tree = $this->getTree();
        while(true)
        {
            if( $tree->t <= $timestamp )
                break;
            if( $tree->seq === 0 )
                break;
            $tree->checkout($tree->prev_tree);
        }
        return $tree;
    }

    /**
     * @param bytes revision
     *
     * @return Tree
     */
    public function getTreeByRevision($revision)
    {
        $tree = $this->getTree();
        while(true)
        {
            if( $tree->hash === $revision )
                break;
            if( $tree->seq === 0 )
                break;
            $tree->checkout($tree->prev_tree);
        }
        return $tree;
    }

    private function getDomainKeyStore($domain)
    {
        $this->cache->setNamespace("cache.keystore.");
        $bin = $this->cache->fetch($domain);
        if( $bin === false )
            return null;
        return KeyStore::decode($bin);
    }

    private function saveDomainKeyStore($domain, KeyStore $keystore)
    {
        $this->cache->setNamespace("cache.keystore.");
        $bin = $keystore->encode("binary");
        $this->cache->save($domain, $bin);
    }

    private function validateTree($domain, $hash)
    {
        $keystore = $this->getDomainKeyStore($domain);
        $this->cache->setNamespace("foreign.{$domain}.");
        if( $this->cache->fetch($hash) !== false ) {
            return;
        }

        $seq = -1;
        $head = $this->cache->fetch("head");
        if( $head )
        {
            $head = TreeMessage::decode($this->cache->fetch($head));
            $seq = $head->getSeq();
        }

        $promise = $this->remoteCall($domain, "getHistory", array("seq" => $seq));
        Promise\settle($promise);
        $response = $promise->wait();
        if( $promise->getState() !== Promise\Promise::FULFILLED )
            throw new Exception("Cannot load history from {$domain}");
        $result = HistoryMessage::decode($response);
        if (!$result->verify($keystore, $head ?: null)) {
            throw new Exception("Invalid history message");
        }

        $found = false;
        $history = $response->history;
        if( count($history) > 0 ) {
            $map = array("head" => $history[0]->hash->toBinary());
            foreach ($history as $i => $tree)
            {
                $thash = $tree->hash->toBinary();
                if (!$found) {
                    $found = $thash === $hash;
                }
                $map[$thash] = MessageBase::psonArrayEncode($tree)->toBinary();
            }
            $this->cache->saveMultiple($map);
        }
        if( $keystore === null )
            $this->saveDomainKeyStore($domain, $result->getServerKeyStore());

        if( $found === false )
        {
            $hash = bin2hex($hash);
            throw new Exception("Incorrect tree hash {$hash} for {$domain}");
        }
    }

    private function verifyCosigner($domain, $signature, $data)
    {
        $this->loadCosigners();
        if( !isset($this->cosigners[$domain]) )
            return false;

        $keystore = $this->cosigners[$domain]["keystore"];
        if( !($keystore instanceof KeyStore) )
            $keystore = KeyStore::decode($keystore);

        if( !$keystore->getPrimaryKey()->verify($data, $signature) )
            return false;

        ++$this->cosigners[$domain]["response_count"];
        $this->saveCosigners();
        return true;
    }

    /**
     * @param string domain
     * @param string hash
     * @param string cosigner - optional
     * @param Signature signature - optional
     *
     * @return array
     */
    public function signTree($domain, $hash, $cosigner = null, $signature = null)
    {
        if( $cosigner !== null && !$this->verifyCosigner($cosigner, $signature, $domain . $hash) )
            throw new Exception("Invalid cosigner");

        $tree = $this->getTree();
        $keystore = $this->getServerKeyStore(true);
        $key = $keystore->getPrimaryKey();
        if( $this->isMyDomain($domain) )
            $tree->checkout($hash); // fails if tree doesn't exist
        else
            $this->validateTree($domain, $hash);

        $signature = $key->sign(array(
            "type" => SignatureType::BINARY_DOCUMENT_SIGNATURE,
            "data" => array("msg" => $hash)
        ));
        return array(
            "signature" => $signature->encode("base64"),
            "info" => ""
        );
    }

    private function getBinaryParam($params, $name, $default = null)
    {
        $param = $this->get($params, $name, $default);
        if( $param instanceof ByteBuffer )
            return $param->toBinary();
        return $param;
    }

    private function getOptionsParam($params)
    {
        $options = $this->get($params, "options");
        if( $options === null )
            return null;

        return (array)$options;
    }

    private function getKeyStoreParam($params)
    {
        $keystore = $this->getBinaryParam($params, "keystore");
        if( is_string($keystore) )
            $keystore = PkiData::decode($keystore);
        return $keystore;
    }

    private function getSignatureParam($params, $name)
    {
        $signature = $this->getBinaryParam($params, $name);
        if( is_string($signature) )
            list($signature) = Signature::decode($signature);
        return $signature;
    }

    private function incrementCosignerRequests($domain)
    {
        $this->loadCosigners();
        if( !isset($this->cosigners[$domain]) )
            return;
        ++$this->cosigners[$domain]["requests_count"];
        $this->saveCosigners();
    }

    public function getTreeSignatures($cosigners, $domain, $hash, $allow_more_cosigners = false)
    {
        $max = $this->getMaxCosigners();
        if( $allow_more_cosigners !== true && $max !== 0 && count($cosigners) > $max )
            $cosigners = array_slice($cosigners, 0, $max); // TODO: warning

        $result = new TreeSignaturesMessage($domain, $hash);
        $namespace = "cache.{$domain}.{$hash}.";
        $this->cache->setNamespace($namespace);
        $cached = $this->cache->fetchMultiple($cosigners);
        $params = array(
            "hash" => $hash,
            "domain" => $domain,
            "sender" => $this->domain()
        );
        $signature = $this->getServerKeyStore(true)->getPrimaryKey()->sign(
            array("data" => array("msg" => $domain . $hash))
        );
        $params["signature"] = $signature->encode("binary");
        $promises = array();
        foreach($cosigners as $cosigner)
        {
            if( $this->isMyDomain($cosigner) )
            {
                try {
                    $result->addSignature($cosigner, $this->signTree($domain, $hash));
                } catch(\Exception $error) {
                    // TODO: log error
                }
                continue;
            }

            if( isset($cached[$cosigner]) )
            {
                $result->addSignature($cosigner, json_decode($cached[$cosigner], true));
                continue;
            }
            
            $promise = $this->remoteCall($cosigner, "signTree", $params)->then(
                function($response) use(&$result, $cosigner, $namespace) {
                    $q = $result->addSignature($cosigner, $response);
                    if ($q["signature"]) {
                        $this->cache->setNamespace($namespace);
                        $this->cache->save($cosigner, json_encode($response));
                    }
                    $this->incrementCosignerRequests($cosigner);
                }
            );
            array_push($promises, $promise);
        }

        if( count($promises) > 0 )
            Promise\settle($promises)->wait();

        return $result;
    }

    private function saveCosigners()
    {
        if( !is_array($this->cosigners) )
            $this->cosigners = array();

        $data = array();
        foreach($this->cosigners as $domain => $cosigner)
        {
            $data[$domain] = $cosigner;
            if( $cosigner["keystore"] instanceof KeyStore )
                $data[$domain]["keystore"] = $cosigner["keystore"]->encode("binary");
        }

        $tree = $this->getTree();
        $tree->saveData("cosigners", $data);
    }

    private function loadCosigners()
    {
        if( is_array($this->cosigners) )
            return;

        $tree = $this->getTree();
        $this->cosigners = $tree->fetchData("cosigners");
        if( !is_array($this->cosigners) )
            $this->cosigners = array();
    }

    public function setCosigner($domain, $data)
    {
        if( !isset($data["uuid"]) || !isset($data["state"]) || !isset($data["keystore"]) )
            throw new Exception("Cosigner 'keystore', 'uuid' and 'state' are required!");

        $this->loadCosigners();
        $prev = null;
        if( isset($this->cosigners[$domain]) )
        {
            $prev = $this->cosigners[$domain];
            $data["ip"] = $prev["ip"];
            $data["requests_count"] = $prev["requests_count"];
            $data["response_count"] = $prev["response_count"];
        }

        if( $prev != null && $prev["state"] != "DELETED" && $prev["uuid"] !== $data["uuid"] )
            return false; // only one keystore per domain

        if( !isset($data["ip"]) )
            $data["ip"] = gethostbyname($domain);
        if( !isset($data["requests_count"]) )
            $data["requests_count"] = 0;
        if( !isset($data["response_count"]) )
            $data["response_count"] = 0;

        $data["modification_timestamp"] = time();
        $this->cosigners[$domain] = $data;
        $this->saveCosigners();
        return true;
    }

    public function removeCosigner($domain, $uuid)
    {
        $this->loadCosigners();
        if( !isset($this->cosigners[$domain]) || $this->cosigners[$domain]["uuid"] !== $uuid )
            return;
        $data = $this->cosigners[$domain];
        $data["state"] = "DELETED";
        $this->cosigners[$domain] = $data;
        $this->saveCosigners();
    }

    public function getCosigners($encode = false)
    {
        $this->loadCosigners();
        if( $encode !== true )
            return $this->cosigners;
        $result = $this->cosigners;
        foreach($result as $domain => $cosigner)
        {
            if( $cosigner["keystore"] instanceof KeyStore )
                $cosigner["keystore"] = $cosigner["keystore"]->encode("binary");
        }
        return $result;
    }

    private function getMaxCosigners()
    {
        $max = 0;
        if( isset($this->config["privmx.pki.max_cosigners"]) &&
            is_numeric($this->config["privmx.pki.max_cosigners"]) )
        {
            $max = $this->config["privmx.pki.max_cosigners"];
        }
        return $max;
    }

    public function selectCosigners()
    {
        $max = $this->getMaxCosigners();
        $map = array();
        if (is_null($this->cosignersProvider)) {
            $this->loadCosigners();
            foreach($this->cosigners as $domain => $data)
            {
                if( $data["state"] !== "ACTIVE" )
                    continue;
                $map[$domain] = $data["keystore"];
            }
        }
        else {
            $map = $this->cosignersProvider->getCosigners();
        }

        $count = count($map);
        if( $max > 0 && $count > $max )
        {
            $all = $map;
            $map = array();
            $domains = array_keys($all);
            for($i = 0; $i < $max; ++$i)
            {
                $random = rand(0, $count - $i);
                $domain = $domains[$random];
                unset($domains[$random]);
                $map[$domain] = $all[$domain];
            }
        }

        foreach($map as $domain => $keystore)
        {
            if( !($keystore instanceof KeyStore) )
                $map[$domain] = KeyStore::decode($keystore);
        }

        return $map;
    }

    private function verifyKeyStore($domain, $hash)
    {
        $cosigners = $this->selectCosigners();
        $count = count($cosigners);
        if( $count === 0 )
            return true;
        $signatures = $this->getTreeSignatures(
            array_keys($cosigners), $domain, $hash
        );
        return $signatures->verify($cosigners, $domain, $hash);
    }

    public function run($method, $params)
    {
        // RPC endpoint implementation
        $params = (array)$params;
        switch($method)
        {
            case "getKeyStore":
                $name = $this->get($params, "name");
                if( $name === null )
                    throw new Exception("Missing required parameter 'name' for method '{$method}'");
                $result = $this->getKeyStore($name, $this->getOptionsParam($params));
                if( $result !== false )
                    return $result->encode("array");
                return $result;
            case "insertKeyStore":
            case "updateKeyStore":
                $keystore = $this->getKeyStoreParam($params);
                if( $keystore === null )
                    throw new Exception("Missing required parameter 'keystore' for method '{$method}'");
                $name = $this->get($params, "name");
                $kis = $this->getSignatureParam($params, "kis");
                $options = $this->getOptionsParam($params);
                if( $method === "insertKeyStore" )
                    return $this->insertKeyStore($name, $keystore, $kis, $options)->encode("array");
                return $this->updateKeyStore($name, $keystore, $kis, $options)->encode("array");
            case "getHistory":
                $revision = $this->getBinaryParam($params, "revision", "");
                $seq = $this->get($params, "seq", -1);
                $timestamp = $this->get($params, "timestamp", 0);
                return $this->getHistory($revision, $seq, $timestamp)->encode("array");
            case "signTree":
                $domain = $this->get($params, "domain");
                if( $domain === null )
                    throw new Exception("Missing required parameter 'domain' for method '{$method}'");
                $sender = $this->get($params, "sender");
                if( $sender === null )
                    throw new Exception("Missing required parameter 'sender' for method '{$method}'");
                $hash = $this->getBinaryParam($params, "hash");
                if( $hash === null )
                    throw new Exception("Missing required parameter 'hash' for method '{$method}'");
                $cosigner_signature = $this->getSignatureParam($params, "signature");
                if( $cosigner_signature === null )
                    throw new Exception("Missing required parameter 'signature' for method '{$method}'");
                return $this->signTree($domain, $hash, $sender, $cosigner_signature);
            case "getTreeSignatures":
                $cosigners = $this->get($params, "cosigners");
                if( $cosigners === null || !is_array($cosigners) || count($cosigners) < 0 )
                    throw new Exception("Invalid required parameter 'cosigners' for method '{$method}'");
                $hash = $this->get($params, "hash");
                if( $hash === null )
                    throw new Exception("Missing required parameter 'hash' for method '{$method}'");
                $domain = $this->get($params, "domain");
                if( $domain === null )
                    throw new Exception("Missing required parameter 'domain' for method '{$method}'");
                return $this->getTreeSignatures($cosigners, $domain, $hash)->encode("array");
        }
        throw new Exception("Unknown method '{$method}'");
    }
};

?>
