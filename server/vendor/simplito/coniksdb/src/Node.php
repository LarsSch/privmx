<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
namespace privmx\pki;

use \JsonSerializable;

abstract class Node implements JsonSerializable {
    protected $tree;
    protected $index;
    protected $hash;
    protected $revision;

    function __construct(Tree $tree, BitString $index = null, $hash = null, $revision = null) {
        if ($index == null) {
            $index = new BitString();
        }
        $this->tree    = $tree;
        $this->index   = $index;
        $this->hash    = $hash;
        $this->revision = $revision;
    }

    function nonce() {
        return $this->tree->nonce;
    }

    function index() {
        return $this->index;
    }

    function hash() {
        return $this->hash;
    }

    function revision() {
        return $this->revision;
    }

    function insert(BitString $index, $data, $pubkey, $signature) {
        $leaf = $this->tree->createLeafNode($index, $data, $pubkey, $signature);
        return $this->insertNode($leaf);
    }

    abstract function insertNode(Node $node);

    abstract function rehash();

    abstract function lookup(BitString $index, AuthPath $path = null);

    abstract function commit($revision = null, array & $changes = null);

    abstract function hashdata();

    function innerhash() {
        return Utils::hash($this->hashdata());
    }

    // Mutable
    protected function withNewHash() {
        $innerhash  = $this->innerhash();
        $this->hash = Utils::hash($this->index()->encode() . $innerhash);
        return $this;
    }

    protected function withHash($hash) {
        $this->hash = $hash;
        return $this;
    }

    protected function withoutHash() {
        $this->hash = null;
        return $this;
    }

    protected function withRevision($revision) {
        $this->revision = $revision;
        return $this;
    }

    protected function withoutRevision() {
        $this->revision = null;
        return $this;
    }

    function __toString() {
        return json_encode($this, JSON_PRETTY_PRINT);
    }
}
