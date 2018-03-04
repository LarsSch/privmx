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

class InteriorNode extends Node {
    protected $left;
    protected $right;

    function __construct(Tree $tree, BitString $prefix, $left, $right, $hash = null, $revision = null) {
        parent::__construct($tree, $prefix, $hash, $revision);
        $this->left  = $left;
        $this->right = $right;
    }

    function left() {
        return $this->left;
    }

    function lefthash() {
        return $this->left()->hash();
    }

    function right() {
        return $this->right;
    }

    function righthash() {
        return $this->right()->hash();
    }

    function hashdata() {
        return $this->lefthash() . $this->righthash();
    }

    function rehash() {
        if ($this->hash) {
            return $this;
        }
        $left  = $this->left()->rehash();
        $right = $this->right()->rehash();
        $node  = $this->tree->createInteriorNode($this->index(), $left, $right);
        return $node->withNewHash();
    }

    function commit($revision = null, array & $changes = null) {
        if ($this->hash == null) {
            return $this->rehash()->commit($revision, $changes);
        }
        if ($this->revision) {
            return $this;
        }
        if ($revision == null) {
            $revision = $this->hash;
        }
        $left  = $this->left()->commit($revision, $changes);
        $right = $this->right()->commit($revision, $changes);
        $node  = $this->tree->createInteriorNode($this->index(), $left, $right, $this->hash, $revision);
        if ($changes !== null) {
            $changes[] = $node;
        }
        return $node;
    }

    function reset() {
        $left  = $this->left()->reset();
        $right = $this->right()->reset();
        $node  = $this->tree->createInteriorNode($this->index(), $left, $right);
        return $node;
    }

    function insertNode(Node $node) {
        $lcp = $this->index()->lcp($node->index());
        if ($this->index()->equals($lcp)) {
            $dir = $node->index()->bit($this->index()->length());
            if ($dir) {
                // clone with changed right and reseted hash and revision
                return $this->tree->createInteriorNode($this->index(), $this->left(), $this->right()->insertNode($node));
            } else {
                // clone with changed right and reseted hash and revision
                return $this->tree->createInteriorNode($this->index(), $this->left()->insertNode($node), $this->right());
            }
        } else {
            $dir = $this->index()->bit($lcp->length());
            if ($dir) {
                // new node
                return $this->tree->createInteriorNode($lcp, $node, $this);
            } else {
                // new node
                return $this->tree->createInteriorNode($lcp, $this, $node);
            }
        }
    }

    function update(BitString $index, IPkiData $store, Signature $kis) {
        if ( !$this->index()->isPrefixOf($index) )
            throw new Exception("notfound");
        $dir = $index->bit($this->index()->length());
        if ($dir) {
            return $this->tree->createInteriorNode($this->index(), $this->left(), $this->right()->update($index, $store, $kis));
        } else {
            return $this->tree->createInteriorNode($this->index(), $this->left()->update($index, $store, $kis), $this->right());
        }
    }

    function lookup(BitString $index, AuthPath $authpath = null) {
        if ( !$this->index()->isPrefixOf($index) ) {
            if ( $authpath !== null ) {
                $authpath[] = new AuthPathNode($this, $this->innerhash(), false);
            }
            return null;
        }
        
        $dir = $index->bit($this->index()->length());
        if ($dir) {
            $result = $this->right()->lookup($index, $authpath);
            $hash   = $this->lefthash();
        } else {
            $result = $this->left()->lookup($index, $authpath);
            $hash   = $this->righthash();
        }
        if ( $authpath !== null ) {
            $authpath[] = new AuthPathNode($this, $hash);
        }
        return $result;
    }

    function preorder() {
       return array_merge([$this], $this->left()->preorder(), $this->right()->preorder());
    }

    function jsonSerialize() {
        $arr = array(
            "type"  => "interior",
            "hash"  => base64_encode($this->hash()),
            "index" => base64_encode($this->index()->encode()),
            "left"  => base64_encode($this->lefthash()),
            "right" =>  base64_encode($this->righthash()),
            "revision"  => base64_encode($this->revision())
        );
        return $arr;
    }
}
