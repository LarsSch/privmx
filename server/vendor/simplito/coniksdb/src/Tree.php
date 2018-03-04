<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
namespace privmx\pki;

use \Exception;
use \JsonSerializable;

use privmx\pki\keystore\KeyStore;
use privmx\pki\keystore\PkiData;
use privmx\pki\keystore\IPkiData;
use privmx\pki\keystore\Signature;
use privmx\pki\keystore\SignatureType;

use privmx\pki\messages\MessageBase;
use privmx\pki\messages\VRFMessage;
use privmx\pki\messages\TreeMessage;

interface NodeStorage {
    function load($hash);
    function save(Node $node);
}

interface NodeFactory {
    function createInteriorNode(BitString $index, Node $left, Node $right);
    function createLeafNode(BitString $index, $name, IPkiData $store, Signature $kis, $datahash = null, $hash = null, $revision = null, $prev_revision = null);
}

interface VRFFactory {
    function getVRFKeyId();
    /**
     * @param string $name
     * @param bool $proof
     *
     * @return BitString | VRFMessage
     */
    function getVRF($name, $proof = false);
}

class Tree implements NodeFactory, NodeStorage, JsonSerializable, VRFFactory {
    private static $ServerKeyStoreLeafName = "server:";
    private $config;

    const MAX_MODIFY_DELAY = 300000; // 5m
    const TREE_EXPIRATION_TIME = 3600000; // 1h

    var $hash;       // hash of current tree header (all fields including signature)

    // tree header
    var $version = 0x01000000;  // 4 bytes  protocol version 
    var $prev_tree;            // 32 bytes previous tree hash
    var $root_t;              // 32 bytes hash of the root
    var $t;                  // 8 bytes tstamp 
    var $nonce;             // 8 bytes - 0 by default 

    var $seq;   // 8 bytes
    var $gseq;  // 4 bytes  OPTIONAL - for future usage
    var $seqhash; // xxxx

    var $signature;        // OpenPGP Signature of tree

    var $root;
    var $db;

    private $VRFContext;
    private $VRFKeyId;
    private $keystore;

    /**
     *
     */
    function __construct($config) {
        $this->config = $config;
        $this->VRFContext = null;
        $this->VRFKeyId = null;
        $this->keystore = null;
        
        // TODO: Dirty code below ...
        if (isset($config["privmx.pki.dbfactory"])) {
            $dbFactory = $config["privmx.pki.dbfactory"];
        }
        else {
            $path = isset($config["privmx.pki.dbpath"]) ? $config["privmx.pki.dbpath"] : "data/trie.dbm";
            $engine = isset($config["privmx.pki.dbengine"]) ? $config["privmx.pki.dbengine"] : "db4";
            $lockFlag = isset($config["privmx.pki.dblockflag"]) ? $config["privmx.pki.dblockflag"] : "l";
            $dbFactory = new DatabaseFactory($path, $engine, $lockFlag);
        }
        $this->db = new KVStore($dbFactory);
        $hash = $this->head();
        if ($hash) {
            $this->checkout($hash);
            $this->ensureFreshTree();
        } else {
            $this->init();
        }
    }
    
    public function getSimpleTree() {
        $data = array(
            "hash" => $this->hash,
            "version" => $this->version,
            "prev_tree" => $this->prev_tree,
            "root_t" => $this->root_t,
            "t" => $this->t,
            "signature" => $this->signature->encode("binary"),
            "seq" => $this->seq
        );
        if ($this->nonce !== null) {
            $data["nonce"] = $this->nonce;
        }
        return new SimpleTree($this->db, $data);
    }

    public function getKeyStore()
    {
        if( $this->keystore === null )
        {
            if( !isset($this->config["privmx.pki.server_keystore"]) )
                throw new Exception("Configuration error, missing server privkey \"privmx.pki.server_keystore\"");

            $this->keystore = KeyStore::decode($this->config["privmx.pki.server_keystore"]);
        }
        return $this->keystore;
    }

    public function head() {
        $this->db->setNamespace("");
        return $this->db->fetch("head");
    }

    public function checkout($hash) {
        if( $hash == $this->hash )
            return; // already checked out to requested revision

        $this->db->setNamespace("");
        $arr = $this->db->fetch($hash);
        if( $arr === false )
            throw new Exception("Missing tree for revision " . bin2hex($hash));

        $this->version = $arr["version"];
        $this->hash    = $arr["hash"];
        $this->prev_tree = $arr["prev_tree"];
        $this->root_t  = $arr["root_t"];
        $this->t       = $arr["t"];
        $this->nonce   = $arr["nonce"];
        list($this->signature) = Signature::decode($arr["signature"]);
        $this->seq = $arr["seq"];
        $this->gseq = isset($arr["gseq"]) ? $arr["gseq"] : null;
        $this->seqhash = isset($arr["seqhash"]) ? $arr["seqhash"] : null;

        $this->root    = $this->load($this->root_t);
    }

    private function init() {
        $this->root = null;
        // XXX: Initial hash
        $this->hash = str_repeat("\000", 32);
        $this->nonce = "";
        // To jest pierwsze uruchomienie, więc tworzymy pierwszy węzeł w drzewie
        // z kluczem publicznym serwera! 
        $name = self::$ServerKeyStoreLeafName;
        $store = $this->getKeyStore();
        $this->insert($this->getVRF($name), $name, $store->getPublicView(), $store->generateKIS($this->hash));
        $this->seq = -1; // will increment to 0
        $this->commit();
    }
    
    private function calculateSignData() {
        return pack('N', $this->version) .
            $this->prev_tree .
            $this->root_t .
            MessageBase::encodeUint64($this->t)->toBinary() .
            $this->nonce .
            MessageBase::encodeUint64($this->seq)->toBinary();
    }
    
    private function calculateHash() {
        return Utils::hash($this->signature->encode("binary") . $this->calculateSignData());
    }

    private function signTree()
    {
        $bin  = $this->calculateSignData();
        $keystore = $this->getKeyStore();
        $this->signature = $keystore->getPrimaryKey()->sign(array(
            "type" => SignatureType::BINARY_DOCUMENT_SIGNATURE,
            "data" => array("msg" => $bin)
        ));
    }

    public function validateKisConiksSubpacket(Signature $kis)
    {
        $coniksPacket = $kis->getConiksSubpacket();

        if( $coniksPacket === null )
            return false;

        $current = $this->hash; // we want to restore revision after validation
        $this->checkout($this->head());
        if( $coniksPacket->data === $this->hash )
        {
            $this->checkout($current);
            // KIS Subpacket signs head tree
            return true;
        }

        $head_timestamp = $this->t; // head tree timestamp
        $this->checkout($coniksPacket->data); // checkout to version of tree signed by KIS
        $kis_timestamp = $this->t; // kis tree timestamp
        $this->checkout($current);

        // KIS is valid if signed tree timestamp is not older than
        // MAX_MODIFY_DELAY from head timestamp
        return ($head_timestamp - $kis_timestamp) <= self::MAX_MODIFY_DELAY;
    }

    private function initVRFContext()
    {
        if( $this->VRFContext === null )
        {
            // server privkey
            $key = $this->getKeyStore()->getPrimaryKey();
            $this->VRFKeyId = $key->getKeyId("binary");
            $this->VRFContext = new VRF($key->getPrivate());
        }

        return $this->VRFContext;
    }

    public function getVRFKeyId()
    {
        $this->initVRFContext();
        return $this->VRFKeyId;
    }

    public function getVRF($name, $proof = false)
    {
        $ctx = $this->initVRFContext();
        $bin = hex2bin($ctx->vrf($name)->encodeCompressed("hex"));
        $value = new BitString($bin);
        if( $proof !== true )
            return $value;
        list($s, $t) = $ctx->proof($name);
        return new VRFMessage($value, $s, $t);
    }

    function lookup(BitString $index, AuthPath $path = null) {
        $this->db->setNamespace("");
        return $this->db->withContext(function() use ($index, $path) {
            if ($this->root == null) {
                return null;
            }
            return $this->root->lookup($index, $path);
        });
    }

    function insert(BitString $index, $name, IPkiData $store, Signature $kis) {
        if( !$store->validate() )
            throw new Exception("Invalid KeyStore");
        if( !$store->verifyKis($kis) )
            throw new Exception("Invalid KeyStore Integration Signature");

        $leaf = $this->createLeafNode($index, $name, $store, $kis);
        return $this->insertNode($leaf);
    }

    function insertNode(Node $node) {
        if ($this->root == null) {
            $this->root = $node;
            return $this;
        }
        $this->root = $this->root->insertNode($node);
        return $this;
    }

    function update(BitString $index, IPkiData $store, Signature $kis) {
        if ($this->root == null) {
            throw new Exception("notfound");
        }
        $this->root = $this->root->update($index, $store, $kis);
        return $this;
    }

    private function ensureFreshTree()
    {
        $now = Utils::tstamp();
        while( ($now - $this->t) > self::TREE_EXPIRATION_TIME )
        {
            $this->t += self::TREE_EXPIRATION_TIME;
            $this->commit(true);
        }
    }

    function commit($force = false) {
        if ($this->root == null) {
            return $this;
        }

        $this->root = $this->root->rehash();
        if (!$force && $this->root_t == $this->root->hash())
            return $this;

        // prepare header
        $this->version   = 0x01000000;
        $this->prev_tree = $this->hash;
        $this->root_t    = $this->root->hash();
        // force is used when tree blocks are generated so timestamp should be already set
        if( !$force )
            $this->t = Utils::tstamp();
        ++$this->seq;

        $this->signTree();
        $this->hash = $this->calculateHash();

        $arr = array(
            "hash" => $this->hash,
            "version" => $this->version,
            "prev_tree" => $this->prev_tree,
            "root_t" => $this->root_t,
            "t" => $this->t,
            "nonce" => $this->nonce,
            "signature" => $this->signature->encode("binary"),
            "seq" => $this->seq
        );
        $changes = array();
        $this->root = $this->root->commit($this->hash, $changes);

        $this->db->setNamespace("");
        $this->db->withContext(function($db) use ($arr, $changes) {
            $db->save($this->hash, $arr);
            $db->save("head", $this->hash);
            foreach($changes as $change) {
                $this->save($change);
            }
        }, "w");

        return $this;
    }

    function createInteriorNode(BitString $index, Node $left, Node $right, $hash = null, $revision = null) {
        $node = new InteriorNode($this, $index, $left, $right, $hash, $revision);
        return $node;
    }

    function createLeafNode(BitString $index, $name, IPkiData $store, Signature $kis, $datahash = null, $hash = null, $revision = null, $prev_revision = null) {
        $node = new LeafNode($this, $index, $name, $store, $kis, $datahash, $hash, $revision, $prev_revision);
        return $node;
    }

    function setnonce($nonce) {
        $this->nonce = $nonce;
        if ($this->root != null) {
            $this->root = $this->root->reset();
        }
        return $this;
    }

    function load($hash) {
        $this->db->setNamespace("");
        $arr = $this->db->fetch($hash);
        $hash    = $arr["hash"];
        $revision = $arr["revision"];
        $index   = BitString::decode($arr["index"]);
        if ($arr["type"] == "interior") {
            $lefthash  = $arr["left"]; 
            $righthash = $arr["right"]; 
            $node = new InteriorNodeProxy($this, $index, $hash, $revision, $lefthash, $righthash);
        } elseif ($arr["type"] == "leaf") {
            $name = $arr["name"];
            $datahash = $arr["datahash"];
            $store = PkiData::decode($arr["key_store"]);
            list($kis) = Signature::decode($arr["kis"]);
            $prev_revision = isset($arr["prev_revision"]) ? $arr["prev_revision"] : null;
            $node = $this->createLeafNode($index, $name, $store, $kis, $datahash, $hash, $revision, $prev_revision);
        } else {
            throw new Exception("aaa");
        }
        return $node;
    }

    function save(Node $node) {
        $arr = array(
            "hash" => $node->hash(),
            "index" => $node->index()->encode(),
            "revision" => $node->revision()
        );
        if ($node instanceof InteriorNode) {
            $arr["type"]  = "interior";
            $arr["left"]  = $node->lefthash();
            $arr["right"] = $node->righthash();
        } else if ($node instanceof LeafNode) {
            $arr["type"]  = "leaf";
            $arr["name"] = $node->name();
            $arr["datahash"] = $node->datahash();
            $arr["key_store"] = $node->key_store()->encode("binary");
            $arr["kis"] = $node->kis()->encode("binary");
            $arr["nonce"] = $node->nonce();
            if ($node->prev_revision())
                $arr["prev_revision"] = $node->prev_revision();
        } else {
            throw new Exception("aaa");
        }
        $this->db->setNamespace("");
        $this->db->save($node->hash(), $arr);
    }

    function jsonSerialize() {
        $arr = array(
            "type" => "tree",
            "version" => $this->version,
            "hash" => base64_encode($this->hash),
            "prev_tree" => base64_encode($this->prev_tree),
            "root" => base64_encode($this->root_t),
            "timestamp" => $this->t,
            "nonce" => base64_encode($this->nonce),
            "signature" => $this->signature,
            "seq" => $this->seq
        );
        return $arr;
    }

    function __toString() {
        return json_encode($this->jsonSerialize());
    }

    public function toMessage()
    {
        return new TreeMessage(
            $this->hash,
            $this->version,
            $this->prev_tree,
            $this->root_t,
            $this->t,
            $this->signature,
            $this->seq,
            $this->nonce
        );
    }

    public function saveData($key, $data)
    {
        $this->db->setNamespace("data");
        $this->db->save($key, $data);
    }

    public function fetchData($key)
    {
        $this->db->setNamespace("data");
        return $this->db->fetch($key);
    }
}
