<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
namespace privmx\pki;

class InteriorNodeProxy extends InteriorNode {
    var $lefthash;
    var $righthash;

    function __construct(Tree $tree, BitString $prefix, $hash, $revision, $lefthash, $righthash) {
        parent::__construct($tree, $prefix, null, null, $hash, $revision);
        $this->hash    = $hash;
        $this->revision = $revision;
        $this->lefthash = $lefthash;
        $this->righthash = $righthash;
        $this->left    = null;
        $this->right   = null;
    }

    function left() {
        if (!$this->left) {
            $this->left = $this->tree->load($this->lefthash);
        }
        return $this->left;
    }

    function lefthash() {
        if ($this->left) {
            return $this->left->hash();
        }
        return $this->lefthash;
    }

    function right() {
        if (!$this->right) {
            $this->right = $this->tree->load($this->righthash);
        }
        return $this->right;
    }

    function righthash() {
        if ($this->right) {
            return $this->right->hash();
        }
        return $this->righthash;
    }
}
