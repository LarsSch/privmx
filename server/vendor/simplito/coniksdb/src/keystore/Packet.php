<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\keystore;

use \Exception;
use \JsonSerializable;

abstract class Packet implements JsonSerializable
{
    // Tags
    const PUBLIC_KEY_ENCRYPTED_SESSION_KEY                = 0x01;
    const SIGNATURE                                       = 0x02;
    const SYMMETRIC_KEY_ENCRYPTED_SESSION_KEY             = 0x03;
    const ONE_PASS_SIGNATURE                              = 0x04;
    const SECRET_KEY                                      = 0x05;
    const PUBLIC_KEY                                      = 0x06;
    const SECRET_SUBKEY                                   = 0x07;
    const COMPRESSED_DATA                                 = 0x08;
    const SYMMETRICALLY_ENCRYPTED_DATA                    = 0x09;
    const MARKER_PACKET                                   = 0x0A;
    const LITERAL_DATA                                    = 0x0B;
    const TRUST_PACKET                                    = 0x0C;
    const USER_ID                                         = 0x0D;
    const PUBLIC_SUBKEY                                   = 0x0E;
    const USER_ATTRIBUTE                                  = 0x11;
    const SYM_ENCRYPTED_AND_INTEGRITY_PROTECTED_DATA      = 0x12;
    const MODIFICATION_DETECTION_CODE                     = 0x13;
    const PKI_DATA_TYPE                                   = 0x3C;
    const ATTACHMENT_POINTER                              = 0x3D;
    const ATTACHMENT_DATA                                 = 0x3E;

    const VERSION                                         = 0x04;
    
    // PkiDataType
    const TYPE_KEYSTORE                                   = 0x01;
    const TYPE_DOCUMENT                                   = 0x02;

    private static function crc24($data)
    {
        $crc = 0xB704CE;
        $poly = 0x1864CFB;

        for($i = 0; $i < strlen($data); $i++)
        {
            $crc ^= ord($data[$i]) << 16;
            for($j = 0; $j < 8; $j++)
            {
                $crc <<= 1;
                if( $crc & 0x1000000 )
                    $crc ^= $poly;
            }
        }

        return chr(($crc >> 16) & 0xFF) .
            chr(($crc >> 8) & 0xFF) .
            chr($crc & 0xFF);
    }

    private static function unarmor($data)
    {
        $lines = explode("\n", $data);
        $pos = 0;
        for($pos = 0; $pos < count($lines); $pos++)
            if( strlen( trim($lines[$pos]) ) === 0 )
                break;

        $lines = array_slice($lines, $pos + 1);
        for($pos = count($lines) - 1; $pos >= 0; $pos--)
        {
            $trimed = trim($lines[$pos]);
            if( strlen($trimed) > 0 && $trimed[0] === "=" )
                break;
        }

        $crc = base64_decode( substr(trim($lines[$pos]), 1) );
        $lines = implode("\n", array_slice($lines, 0, $pos) );

        $data = base64_decode($lines);

        if( $crc !== Packet::crc24($data) )
            throw new Exception("Incorrect CRC 24");

        return $data;
    }

    private static function toBin($data)
    {
        //hex
        if( ctype_xdigit($data) )
            return hex2bin($data);

        // armored
        if( substr($data, 0, 5) === "-----" )
            return Packet::unarmor($data);

        // base64
        $decoded = base64_decode($data, true);
        if( $decoded )
            return $decoded;

        // binary
        return $data;
    }

    protected static function decodePacket($data)
    {
        $data = Packet::toBin($data);

        $tag = ord($data[0]);
        $data = substr($data, 1);
        $length = 0;
        //old packet type
        if( ($tag & 0x40) === 0 )
        {
            switch($tag & 0x3)
            {
                case 0:
                    $length = ord($data[0]);
                    $data = substr($data, 1);
                    break;
                case 1:
                    $length = unpack("nuint16", $data)["uint16"];
                    $data = substr($data, 2);
                    break;
                case 2:
                    $length = unpack("Nuint32", $data)["uint32"];
                    $data = substr($data, 4);
                    break;
                default:
                    throw new \Exception("Unsupported packet length");
            }
            $tag = ($tag & 0x3F) >> 2;
        }
        //new packet type
        else
        {
            $byte = ord($data[0]);
            if( $byte == 0xFF )
            {
                $data = substr($data, 1);
                $length = unpack("Nuint32", $data)["uint32"];
                $data = substr($data, 4);
            }
            else if( ($byte & 0xC0) === 0xC0 )
            {
                $length = (($byte - 192) << 8) + 192 + ord($data[1]);
                $data = substr($data, 2);
            }
            else
            {
                $length = $byte;
                $data = substr($data, 1);
            }
            $tag = $tag & 0x3F;
        }

        $data_len = strlen($data);
        if ($data_len < $length) {
            throw new Exception("Truncated data, have {$data_len} expects {$length}");
        }
        $body = substr($data, 0, $length);
        $additional = substr($data, $length);

        return array($tag, $body, $additional);
    }

    private static function enarmor($data, $marker)
    {
        $marker = strtoupper($marker);
        return "-----BEGIN PGP " . $marker . "-----\n\n" .
            implode("\n", str_split( base64_encode($data), 64 )) .
            "\n=" . base64_encode( Packet::crc24($data) ) .
            "\n-----END PGP " . $marker . "-----\n";
    }

    protected static function concat($packets)
    {
        $result = "";
        foreach($packets as $packet)
            $result .= $packet->encodeRaw();

        return $result;
    }

    protected static function isTag($tag, $expected)
    {
        $old = ($tag & 0x40) === 0;
        $tag = $tag & 0x3F;

        return (
            (!$old && $tag === $expected) || // new packet format
            ($old && ($tag >> 2) === $expected)
        );
    }

    protected static function oneOfTags($tag, $tags)
    {
        foreach($tags as $expected)
        {
            if( self::isTag($tag, $expected) )
                return true;
        }
        return false;
    }

    protected function getArmor()
    {
        return "MESSAGE";
    }

    protected abstract function getTag();
    protected abstract function getBody();

    public function encode($type = "armored")
    {
        $raw = $this->encodeRaw();
        switch($type)
        {
            case "binary":
                return $raw;
            case "hex":
                return bin2hex($raw);
            case "base64":
                return base64_encode($raw);
            default: // armored
                return Packet::enarmor($raw, $this->getArmor());
        }
    }

    protected function encodeRaw()
    {
        $body = $this->getBody();
        $header = chr(0xC0 | $this->getTag());
        $length = strlen($body);
        if( $length < 192 )
            $header .= chr($length);
        else if( $length < 8383 )
        {
            $length -= 192;
            $header .= (
                chr(($length >> 8) + 192) .
                chr($length & 0xFF)
            );
        }
        else
            $header .= chr(0xFF) . pack("N", $length);

        return $header . $body;
    }

    public function jsonSerialize()
    {
        return $this->encode();
    }
}

?>
