<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\messages;

use privmx\pki\keystore\KeyStore;

class KeyStoreMessage extends MessageBase
{
    private $vrf = null;
    private $tree = null;
    private $path = array();
    private $leaf = null;
    private $serverKeystore = null;

    /**
     * @param TreeMessage tree, required
     * @param VRFMessage vrf, required
     * @param array<AuthPathNodeMessage> path, required
     * @param LeafMessage|NULL leaf, required
     * @param KeyStore serverKeystore, required
     */
    public function __construct($tree = null, $vrf = null, $path = null, $leaf = null, $serverKeystore = null)
    {
        if( MessageBase::isEmptyConstructor(array($tree, $vrf, $path, $leaf, $serverKeystore)) )
            return;

        $this->setVRF($vrf)->setTree($tree)->setPath($path)->setServerKeystore($serverKeystore);
        if( $leaf !== null )
            $this->setLeaf($leaf);
    }

    public function setTree($tree)
    {
        if( !($tree instanceof TreeMessage) )
            $tree = TreeMessage::decode($tree);

        $this->tree = $tree;
        return $this;
    }

    public function getTree()
    {
        return $this->tree;
    }

    public function setVRF($vrf)
    {
        if( !($vrf instanceof VRFMessage) )
            $vrf = VRFMessage::decode($vrf);

        $this->vrf = $vrf;
        return $this;
    }

    public function getVRF()
    {
        return $this->vrf;
    }

    public function setLeaf($leaf)
    {
        if( !($leaf instanceof LeafMessage) )
            $leaf = LeafMessage::decode($leaf);

        $this->leaf = $leaf;
        return $this;
    }

    public function getLeaf()
    {
        return $this->leaf;
    }

    public function setPath($path)
    {
        if( !is_array($path) )
            $this->incorrectFieldType("path");
        foreach($path as $node)
        {
            if( !($node instanceof AuthPathNodeMessage) )
                $node = AuthPathNodeMessage::decode($node);

            array_push($this->path, $node);
        }
        return $this;
    }

    public function getPath()
    {
        return $this->path;
    }

    public function setServerKeystore($keystore)
    {
        $keystore = MessageBase::fromByteBuffer($keystore);
        if( is_string($keystore) )
            $keystore = KeyStore::decode($keystore);

        if( !($keystore instanceof KeyStore) )
            $this->incorrectFieldType("serverKeystore");

        $this->serverKeystore = $keystore;
        return $this;
    }

    public function getServerKeystore()
    {
        return $this->serverKeystore;
    }

    public function validate()
    {
        if( $this->getTree() === null )
            $this->missingField("tree");

        if( $this->getVRF() === null )
            $this->missingField("vrf");

        if( $this->getServerKeystore() === null )
            $this->missingField("serverKeystore");
    }

    public function psonSerialize()
    {
        $this->validate();
        $result = array(
            "tree" => $this->getTree()->psonSerialize(),
            "vrf" => $this->getVRF()->psonSerialize(),
            "serverKeystore" => MessageBase::toByteBuffer(
                $this->getServerKeystore()->encode("binary")
            )
        );

        $tmp = $this->getPath();
        if( count($tmp) > 0 )
            $result["path"] = MessageBase::serializeArray($tmp);

        $leaf = $this->getLeaf();
        if( $leaf !== null )
            $result["leaf"] = $leaf->psonSerialize();

        return $result;
    }

    public function psonUnserialize($pson)
    {
        $pson = (array)$pson;
        if( isset($pson["tree"]) )
            $this->setTree($pson["tree"]);

        if( isset($pson["vrf"]) )
            $this->setVRF($pson["vrf"]);

        if( isset($pson["leaf"]) )
            $this->setLeaf($pson["leaf"]);

        if( isset($pson["path"]) && is_array($pson["path"]) )
            $this->setPath($pson["path"]);

        if( isset($pson["serverKeystore"]) )
            $this->setServerKeystore($pson["serverKeystore"]);

        $this->validate();
    }

    public function getKeystore()
    {
        $leaf = $this->getLeaf();
        if( $leaf === null )
            return null;
        return $leaf->getKeystore();
    }

    public function verifyVRF($name, KeyStore $serverKeystore)
    {
        if( $name === null )
            return true; // warning

        return $this->getVRF()->verify($name, $serverKeystore);
    }

    public function verifyAuthPath()
    {
        $index = $this->getVRF()->getValue();
        $leaf = $this->getLeaf();
        $tree = $this->getTree();
        $path = $this->getPath();
        $count = count($path);
        if( $count === 0 )
            return false;

        $authindex = $path[0]->authindex($index);
        $match = $authindex->equals($index);

        if( !$match && $leaf !== null )
            return false;

        $hash = $path[0]->hash($authindex);
        if( $match && !($leaf !== null && $leaf->getIndex()->equals($index) && $leaf->getHash() === $hash) )
            return false;

        for($i = 1; $i < $count; ++$i)
        {
            $previndex = $authindex;
            $authindex = $path[$i]->authindex($index);
            if( !$authindex->isPrefixOf($previndex) )
                return false;

            $dir = $index->bit($authindex->length());
            $interhash = $path[$i]->interhash($hash, $dir);
            $hash = $path[$i]->hash($authindex, $interhash);
        }

        return $tree->getRoot() === $hash;
    }

    public function verify($name = null, $serverKeystore = null)
    {
        if( $serverKeystore === null )
            $serverKeystore = $this->getServerKeystore();
        $leaf = $this->getLeaf();
        if( $name === null && $leaf !== null )
            $name = $leaf->getName();

        return (
            $this->verifyVRF($name, $serverKeystore) &&
            $this->getTree()->verify($serverKeystore) &&
            ($leaf !== null ? $leaf->verify() : true) &&
            $this->verifyAuthPath()
        );
    }
}

?>
