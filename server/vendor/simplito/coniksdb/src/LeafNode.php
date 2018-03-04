<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
namespace privmx\pki;

use \Exception;
use privmx\pki\keystore\IPkiData;
use privmx\pki\keystore\Signature;
use privmx\pki\messages\LeafMessage;

class LeafNode extends Node {
    protected $name;
    protected $datahash;
    protected $prev_revision;
    protected $key_store;
    protected $kis; //keystore integration signature

    function __construct( Tree $tree, BitString $index, $name, IPkiData $store, Signature $kis,
                          $datahash = null, $hash = null, $revision = null, $prev_revision = null)
    {
        parent::__construct($tree, $index, $hash, $revision);
        $this->name = $name;
        $this->key_store = $store;
        $this->kis = $kis;
        $this->datahash = $datahash ? $datahash : Utils::hash($this->key_store->encode("binary"));
        $this->prev_revision = $prev_revision;
    }

    function name()
    {
        return $this->name;
    }

    function key_store() {
        return $this->key_store;
    }

    function kis() {
        return $this->kis;
    }

    function datahash() {
        return $this->datahash;
    }

    function prev_revision() {
        return $this->prev_revision;
    }

    function hashdata() {
        return 
            "leaf" . 
            $this->nonce() . 
            $this->datahash() . 
            $this->kis()->encode("binary") .
            ($this->prev_revision() ? $this->prev_revision() : "");
    }

    function copy() {
        return $this->tree->createLeafNode(
            $this->index(), $this->name, $this->key_store, $this->kis, $this->datahash,
            $this->hash, $this->revision, $this->prev_revision
        );
    }

    function commit($revision = null, array& $changes = null) {
        if ($this->hash == null) {
            return $this->rehash()->commit($revision, $changes);
        }
        if ($this->revision) {
            return $this;
        }
        if ($revision == null) {
            $revision = $this->hash;
        }
        $node = $this->copy()->withRevision($revision);
        if ($changes !== null) {
            $changes[] = $node;
        }
        return $node;
    }

    function rehash() {
        if ($this->hash) {
            return $this;
        }
        return $this->copy()->withoutRevision()->withNewHash();
    }

    function reset() {
        return $this->copy()->withoutRevision()->withoutHash();
    }

    function insertNode(Node $node) {
        $prefix    = $this->index()->lcp($node->index());
        if ($prefix == $this->index()) {
            throw new Exception("exists");
        }
        $direction = $this->index()->bit($prefix->length());
        if ($direction) {
            return $this->tree->createInteriorNode($prefix, $node, $this);
        } else {
            return $this->tree->createInteriorNode($prefix, $this, $node);
        }
    }

    function update(BitString $index, IPkiData $store, Signature $kis) {
        if ( ! $index->equals( $this->index() ) ) {
            throw new Exception("notfound");
        }

        if( !$store->isValidToSave() )
            throw new Exception("Invalid KeyStore");

        if( !$store->verifyKis($kis) )
            throw new Exception("Invalid KeyStore Integration Signature");
        
        if( !$store->isCompatibleWithPrevious($kis, $this->key_store) )
            throw new Exception("KeyStore is incompatible with previous entry");

        if ($this->revision)
            $prev_revision = $this->revision;
        else
            $prev_revision = $this->prev_revision;

        return $this->tree->createLeafNode($this->index(), $this->name(), $store, $kis, null, null, null, $prev_revision);
    }

    function lookup(BitString $index, AuthPath $authpath = null) {
        $match = $index->equals($this->index());
        if ( $authpath !== null ) {
            $authpath[] = new AuthPathNode($this, $this->innerhash(), $match);
        }
        if ( !$match ) {
            return null;
        }
        return $this;
    }

    function preorder() {
       return [$this];
    }

    function jsonSerialize() {
        $arr = array(
            "type" => "leaf",
            "hash" => base64_encode($this->hash()),
            "index" => base64_encode($this->index()->encode()),
            "name" => $this->name,
            "datahash" => base64_encode($this->datahash()),
            "nonce" => base64_encode($this->nonce()),
            "revision" => base64_encode($this->revision()),
            "key_store" => $this->key_store,
            "kis" => $this->kis()
        );
        if ($this->prev_revision)
            $arr["prev_revision"] = base64_encode($this->prev_revision());
        return $arr;
    }

    public function toMessage()
    {
        return new LeafMessage(
            $this->hash(),
            $this->index(),
            $this->name,
            $this->datahash(),
            $this->revision(),
            $this->key_store,
            $this->kis(),
            $this->nonce(),
            $this->prev_revision()
        );
    }
}
