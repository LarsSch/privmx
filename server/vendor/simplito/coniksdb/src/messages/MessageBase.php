<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\messages;

use Exception;
use PSON\ByteBuffer;
use PSON\StaticPair;

abstract class MessageBase
{
    public abstract function psonUnserialize($pson);
    public abstract function psonSerialize();

    public function encode($type = "pson")
    {
        $array = $this->psonSerialize();
        switch($type)
        {
            case "array":
                return $array;
            default:
                $pson = new StaticPair();
                return $pson->encode($array);
        }
    }

    public static function psonArrayEncode($array)
    {
        $pson = new StaticPair();
        return $pson->encode($array);
    }

    protected function missingField($name)
    {
        throw new Exception("Missing required field \"{$name}\" for " . get_class($this));
    }

    protected function incorrectFieldType($name)
    {
        throw new Exception("Incorrect field type \"{$name}\" for " . get_class($this));
    }

    public static function decode($data)
    {
        if( is_string($data) )
            $data = MessageBase::toByteBuffer($data);

        if( $data instanceof ByteBuffer )
        {
            $pson = new StaticPair();
            $data = $pson->decode($data);
        }

        if( is_object($data) )
            $data = (array)$data;

        $result = new static();
        $result->psonUnserialize($data);

        return $result;
    }

    public static function toByteBuffer($value)
    {
        return ByteBuffer::wrap($value);
    }

    protected static function fromByteBuffer($value)
    {
        if( $value instanceof ByteBuffer )
            return $value->capacity() === 0 ? "" : $value->toBinary();

        return $value;
    }

    public static function encodeUint64($value, $trim = false)
    {
        $bb = new ByteBuffer();
        $bb->writeUint64($value);
        $bb->flip();
        return $bb;
    }

    public static function decodeUint64($value)
    {
        $bb = ByteBuffer::wrap($value);
        return $bb->readUint64();
    }

    protected static function serializeArray(array $array)
    {
        return array_map(function (MessageBase $value) {
            return $value->psonSerialize();
        }, $array);
    }

    protected static function isEmptyConstructor(array $params)
    {
        foreach($params as $param)
        {
            if( $param !== null )
                return false;
        }
        return true;
    }
}

?>
