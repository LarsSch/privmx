<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\messages;

use privmx\pki\keystore\KeyStore;

class HistoryMessage extends MessageBase
{
    private $history = null;
    private $serverKeystore = null;

    /**
     * @param array<TreeMessage> history, required
     * @param KeyStore serverKeystore, required
     */
    public function __construct($history = null, $serverKeystore = null)
    {
        if( MessageBase::isEmptyConstructor(array($history, $serverKeystore)) )
            return;

        $this->setHistory($history)->setServerKeyStore($serverKeystore);
    }

    public function setHistory($history)
    {
        if( !is_array($history) )
            $this->incorrectFieldType("history");

        $tmp = array();
        foreach($history as $tree)
        {
            if( !($tree instanceof TreeMessage) )
                $tree = TreeMessage::decode($tree);

            array_push($tmp, $tree);
        }
        $this->history = $tmp;
        return $this;
    }

    public function getHistory()
    {
        return $this->history;
    }

    public function setServerKeyStore($keystore)
    {
        $keystore = MessageBase::fromByteBuffer($keystore);
        if( is_string($keystore) )
            $keystore = KeyStore::decode($keystore);

        if( !($keystore instanceof KeyStore) )
            $this->incorrectFieldType("serverKeystore");

        $this->serverKeystore = $keystore;
        return $this;
    }

    public function getServerKeyStore()
    {
        return $this->serverKeystore;
    }

    public function validate()
    {
        if( $this->getHistory() === null )
            $this->missingField("history");
        if( $this->getServerKeyStore() === null )
            $this->missingField("serverKeystore");
    }

    public function psonSerialize()
    {
        $this->validate();
        $result = array(
            "serverKeystore" => MessageBase::toByteBuffer(
                $this->getServerKeyStore()->encode("binary")
            ),
            "history" => MessageBase::serializeArray($this->getHistory())
        );

        return $result;
    }

    public function psonUnserialize($pson)
    {
        $pson = (array)$pson;
        if( isset($pson["history"]) )
            $this->setHistory($pson["history"]);

        if( isset($pson["serverKeystore"]) )
            $this->setServerKeyStore($pson["serverKeystore"]);

        $this->validate();
    }

    public function verify(KeyStore $serverKeystore = null, TreeMessage $prev = null)
    {
        if( $serverKeystore === null )
            $serverKeystore = $this->getServerKeyStore();

        $history = $this->getHistory();
        $count = count($history);

        if( $count === 0 )
            return true;

        for($i = $count - 1; $i >= 0; --$i)
        {
            $tree = $history[$i];
            if( !$tree->verify($serverKeystore, $i < $count - 1) || ($prev !== null && $tree->getPrevTree() !== $prev->getHash()) )
                return false;
            $prev = $tree;
        }

        return true;
    }
}

?>
