<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\keystore;

use Exception;

abstract class Subpacket
{
    private static function encodeLength($length)
    {
        if( $length < 192 )
            return chr($length);

        if( $length < 8383 )
        {
            $length -= 192;
            return chr(($length >> 8) + 192) . chr($length & 0xFF);
        }

        return chr(0xFF) . pack("N", $length);
    }

    private static function decodeSubpacket($data)
    {
        $byte = ord($data[0]);
        $length = 0;
        $type = 0;
        if( $byte < 192 )
        {
            $length = $byte - 1;
            $type = ord($data[1]);
            $data = substr($data, 2);
        }
        else if( $byte < 255 )
        {
            $length = (($byte - 192) << 8) + ord($data[1]) + 191;
            $type = ord($data[2]);
            $data = substr($data, 3);
        }
        else
        {
            $data = substr($data, 1);
            $length = unpack("Nuint32", $data)["uint32"];
            $type = ord($data[4]);
            $data = substr($data, 5);
        }
        $body = substr($data, 0, $length);
        $data = substr($data, $length);

        return array($type, $body, $data);
    }

    public static function decode($data)
    {
        $result = array();

        while( strlen($data) > 0 )
        {
            list($type, $body, $data) = Subpacket::decodeSubpacket($data);

            switch($type)
            {
                case SignatureSubpacketType::ISSUER:
                    array_push($result, new IssuerSubpacket($body));
                    break;
                case SignatureSubpacketType::SIGNATURE_CREATION_TIME:
                    $time = unpack("Nuint32", $body)["uint32"];
                    array_push($result, new SignatureCreationTimeSubpacket($time));
                    break;
                case SignatureSubpacketType::REVOCABLE:
                    array_push($result, new RevocableSubpacket( ord($body[0]) ));
                    break;
                case SignatureSubpacketType::KEY_FLAGS:
                    $flags = hexdec(bin2hex($body));
                    array_push($result, new KeyFlagsSubpacket($flags));
                    break;
                case SignatureSubpacketType::CONIKS_DB_ID:
                    $timestamp = unpack("Nuint32", $body)["uint32"];
                    $body = substr($body, 4);
                    array_push($result, new ConiksDbIdSubpacket($body, $timestamp));
                    break;
                case SignatureSubpacketType::REVOCATION_KEY:
                    $algorithm = ord($body[1]);
                    $sensitive = ord($body[0]);
                    $fingerprint = substr($body, 2);
                    array_push($result, new RevocationKeySubpacket($algorithm, $fingerprint, $sensitive));
                    break;
                case SignatureSubpacketType::EMBEDDED_SIGNATURE:
                    list($signature) = Signature::decode($body);
                    array_push($result, new EmbeddedSignatureSubpacket($signature));
                    break;
                default:
                    array_push($result, new GenericSubpacket($type, $body));
                    break;
            }
        }

        return $result;
    }

    public $type = 0;

    protected abstract function getBody();
    public function encode()
    {
        $body = $this->getBody();
        $length = strlen($body) + 1;
        return Subpacket::encodeLength($length) . chr($this->type) . $body;
    }
}

class IssuerSubpacket extends Subpacket
{
    public $type = SignatureSubpacketType::ISSUER;

    public $keyId;

    function __construct($keyId)
    {
        $this->keyId = $keyId;
    }

    protected function getBody()
    {
        return $this->keyId;
    }
}

class SignatureCreationTimeSubpacket extends Subpacket
{
    public $type = SignatureSubpacketType::SIGNATURE_CREATION_TIME;
    public $timestamp;

    function __construct($timestamp=null)
    {
        if($timestamp == null)
            $timestamp = time();
        $this->timestamp = $timestamp;
    }

    protected function getBody()
    {
        return pack('N', $this->timestamp);
    }
}

class KeyFlagsSubpacket extends Subpacket
{
    public $type = SignatureSubpacketType::KEY_FLAGS;
    public $flags;

    function __construct($flags)
    {
        $this->flags = $flags;
    }

    protected function getBody()
    {
        $hex = dechex($this->flags);
        return hex2bin( (strlen($hex) % 2 ? "0" . $hex : $hex) );
    }
}

class RevocationKeySubpacket extends Subpacket
{
    public $type = SignatureSubpacketType::REVOCATION_KEY;
    public $algorithm;
    public $fingerprint;
    public $sensitive;

    function __construct($algorithm, $fingerprint, $sensitive = 0x40)
    {
        $this->algorithm = $algorithm;
        $this->fingerprint = $fingerprint;
        $this->sensitive = $sensitive;
    }

    protected function getBody()
    {
        return chr($this->sensitive) . chr($this->algorithm) . $this->fingerprint;
    }
}

class EmbeddedSignatureSubpacket extends Subpacket
{
    public $type = SignatureSubpacketType::EMBEDDED_SIGNATURE;
    public $signature;

    function __construct(Signature $signature)
    {
        $this->signature = $signature;
    }

    protected function getBody()
    {
        return $this->signature->encode("binary");
    }
}

class RevocableSubpacket extends Subpacket
{
    public $type = SignatureSubpacketType::REVOCABLE;
    public $revocable;

    function __construct($revocable)
    {
        $this->revocable = $revocable;
    }

    protected function getBody()
    {
        return chr($this->revocable);
    }
}

class GenericSubpacket extends Subpacket
{
    public $type;
    public $data;

    function __construct($type, $data)
    {
        $this->type = $type;
        $this->data = $data;
    }

    protected function getBody()
    {
        return $this->data;
    }
}

class ConiksDbIdSubpacket extends Subpacket
{
    public $type = SignatureSubpacketType::CONIKS_DB_ID;
    public $timestamp;
    public $data;

    function __construct($data, $timestamp = null)
    {
        $this->data = ctype_xdigit($data) ? hex2bin($data) : $data;
        if( $timestamp === null )
            $timestamp = time();
        $this->timestamp = $timestamp;
    }

    protected function getBody()
    {
        return pack('N', $this->timestamp) . $this->data;
    }
}

class Signature extends Packet
{
    private static function createSignatureHashedPart(Signature $signature)
    {
        // hashed part
        $data = $signature->getSubpacketData(true);
        $head = chr(Packet::VERSION) . chr($signature->type) .
            chr($signature->keyAlgorithm) . chr($signature->hashAlgorithm);

        return $head . pack("n", strlen($data)) . $data;
    }

    private static function buildHashData(Signature $signature, $data)
    {
        $bin = "";
        $type = $signature->type;
        switch( $type )
        {
            case SignatureType::BINARY_DOCUMENT_SIGNATURE:
                if( isset($data["msg"]) )
                    $bin .= $data["msg"];
                break;
            case SignatureType::STANDALONE_SIGNATURE:
                break;
            case SignatureType::GENERIC_PK_USER_ID_CERTIFICATION:
            case SignatureType::PERSONA_PK_USER_ID_CERTIFICATION:
            case SignatureType::CASUAL_PK_USER_ID_CERTIFICATION:
            case SignatureType::POSITIVE_PK_USER_ID_CERTIFICATION:
                if( !isset($data["key"]) || !isset($data["userId"]) )
                    throw new Exception("Signature type {$type} requires PublicKey and UserID");
                $bin .= $data["key"]->getFingerprint("binary", true);
                $bin .= chr(0xB4) . pack("N", strlen($data["userId"])) . $data["userId"];
                break;
            case SignatureType::PRIMARY_KEY_BINDING_SIGNATURE:
            case SignatureType::SUBKEY_BINDING_SIGNATURE:
                if( !isset($data["key"]) || !isset($data["subkey"]) )
                    throw new Exception("Signature type {$type} requires PublicKey and Subkey");
                $bin .= $data["key"]->getFingerprint("binary", true);
                $bin .= $data["subkey"]->getFingerprint("binary", true);
                break;
            case SignatureType::KEY_REVOCATION_SIGNATURE:
                if( !isset($data["key"]) )
                    throw new Exception("Signature type {$type} requires PublicKey");
                $bin .= $data["key"]->getFingerprint("binary", true);
                break;
            case SignatureType::SUBKEY_REVOCATION_SIGNATURE:
                if( !isset($data["subkey"]) )
                    throw new Exception("Signature type {$type} requires PublicSubkey");
                $bin .= $data["subkey"]->getFingerprint("binary", true);
                break;
            default:
                throw new Exception("Unsupported signature type {$type}");
        }

        $hashed = Signature::createSignatureHashedPart($signature);
        $bin .= $hashed . chr(Packet::VERSION) . chr(0xFF) . pack("N", strlen($hashed));

        return Utils::hashWithAlgorithm($bin, $signature->hashAlgorithm);
    }

    public static function create($options, $privkey)
    {
        $result = new Signature();
        if( isset($options["type"]) )
            $result->type = $options["type"];
        if( isset($options["hashed"]) )
            $result->hashedPackets = $options["hashed"];

        $hasPacket = false;
        // Has SignatureCreationTimeSubpacket
        foreach($result->hashedPackets as $subpacket)
        {
            if( $subpacket instanceof SignatureCreationTimeSubpacket )
            {
                $hasPacket = true;
                break;
            }
        }

        if( !$hasPacket )
            array_push($result->hashedPackets, new SignatureCreationTimeSubpacket());

        // Has RevocableSubpacket
        if( $result->type === SignatureType::KEY_REVOCATION_SIGNATURE ||
            $result->type === SignatureType::SUBKEY_REVOCATION_SIGNATURE )
        {
            $hasPacket = false;
            foreach($result->hashedPackets as $subpacket)
            {
                if( $subpacket instanceof RevocableSubpacket )
                {
                    $hasPacket = true;
                    break;
                }
            }

            if( !$hasPacket )
                array_push($result->hashedPackets, new RevocableSubpacket(false));
        }

        // Has IssuerSubpacket
        $hasPacket = false;
        foreach($result->unhashedPackets as $subpacket)
        {
            if( $subpacket instanceof IssuerSubpacket )
            {
                $hasPacket = true;
                break;
            }
        }

        if( !$hasPacket )
            array_push($result->unhashedPackets, new IssuerSubpacket($privkey->getKeyId("binary")));

        if( isset($options["hashAlgorithm"]) )
            $result->hashAlgorithm = $options["hashAlgorithm"];

        $data = isset($options["data"]) ? $options["data"] : null;
        $hash = Signature::buildHashData($result, $data);
        $result->hashPrefix = substr($hash, 0, 2);

        $signature = $privkey->sign(bin2hex($hash));
        $r = implode("", array_map("chr", $signature->r->toArray()));
        $s = implode("", array_map("chr", $signature->s->toArray()));

        $result->signatureData = pack("n", $signature->r->bitLength()) . $r .
            pack("n", $signature->s->bitLength()) . $s;

        return $result;
    }

    public static function decode($data)
    {
        list($tag, $body, $additional) = Packet::decodePacket($data);

        if( $tag !== Packet::SIGNATURE )
            throw new Exception("Incorrect tag for Signature packet {$tag}");

        $result = new Signature();
        $result->type = ord($body[1]);

        if( ord($body[2]) !== Algorithm::ECDSA )
            throw new Exception("Unimplemneted public key algorithm " . ord($body[2]));
        $result->hashAlgorithm = ord($body[3]);

        $body = substr($body, 4);
        $length = unpack("nuint16", $body)["uint16"];
        $result->hashedPackets = Subpacket::decode(substr($body, 2, $length));
        $body = substr($body, $length + 2);

        $length = unpack("nuint16", $body)["uint16"];
        $result->unhashedPackets = Subpacket::decode(substr($body, 2, $length));
        $body = substr($body, $length + 2);

        $result->hashPrefix = substr($body, 0, 2);
        $result->signatureData = substr($body, 2);

        return array($result, $additional);
    }

    public $type = SignatureType::BINARY_DOCUMENT_SIGNATURE;
    protected $keyAlgorithm = Algorithm::ECDSA;
    protected $hashAlgorithm = Algorithm::SHA256;
    protected $hashedPackets = [];
    protected $unhashedPackets = [];
    protected $hashPrefix = null;
    protected $signatureData = null;

    protected function getTag()
    {
        return Packet::SIGNATURE;
    }

    protected function getArmor()
    {
        return "SIGNATURE";
    }

    private function getSubpacketData($hashed = true)
    {
        $subpackets = $hashed  ? $this->hashedPackets : $this->unhashedPackets;

        $data = "";
        foreach($subpackets as $subpacket)
            $data .= $subpacket->encode();

        return $data;
    }

    protected function getBody()
    {
        // unhashed part
        $data = $this->getSubpacketData(false);

        return Signature::createSignatureHashedPart($this) .
            pack("n", strlen($data)) . $data .
            $this->hashPrefix . $this->signatureData;
    }

    public function verify($data, $pubkey)
    {
        if( $this->getIssuerId("binary") !== $pubkey->getKeyId("binary") )
            return false;

        if( $pubkey->isRevoked() && $pubkey->getRevokeTime() < $this->getTimestamp() )
            return false;

        if( $this->type === SignatureType::SUBKEY_REVOCATION_SIGNATURE ||
            $this->type === SignatureType::KEY_REVOCATION_SIGNATURE )
        {
            if( !isset($data["subkey"]) || !$data["subkey"]->isValidRevokeKey($pubkey) )
                return false;
        }

        $hash = Signature::buildHashData($this, $data);
        if( $this->hashPrefix !== substr($hash, 0, 2) )
            return false;

        $length = ceil( unpack("nuint16", $this->signatureData)["uint16"] / 8 );
        $r = bin2hex( substr($this->signatureData, 2, $length) );
        $tmp = substr($this->signatureData, $length + 2);
        $length = ceil( unpack("nuint16", $tmp)["uint16"] / 2 );
        $s = bin2hex( substr($tmp, 2, $length) );

        return $pubkey->verify(bin2hex($hash), array("r" => $r, "s" => $s));
    }

    public function getIssuerId($enc = "hex")
    {
        foreach($this->unhashedPackets as $subpacket)
        {
            if( $subpacket instanceof IssuerSubpacket )
                return $enc === "hex" ? strtoupper( bin2hex($subpacket->keyId) ) : $subpacket->keyId;
        }

        throw new Exception("Missing Issuer Subpacket");
    }

    public function getTimestamp()
    {
        foreach($this->hashedPackets as $subpacket)
        {
            if( $subpacket instanceof SignatureCreationTimeSubpacket )
                return $subpacket->timestamp;
        }

        throw new Exception("Missing SignatureCreationTimeSubpacket");
    }

    public function getRevocationKeys()
    {
        $result = array();

        foreach($this->hashedPackets as $subpacket)
        {
            if( $subpacket instanceof RevocationKeySubpacket )
                array_push($result, $subpacket->fingerprint);
        }

        return $result;
    }

    public function getKeyFlags()
    {
        foreach($this->hashedPackets as $subpacket)
        {
            if( $subpacket instanceof KeyFlagsSubpacket )
                return $subpacket->flags;
        }
        return 0;
    }

    public function getEmbedded()
    {
        foreach($this->hashedPackets as $subpacket)
        {
            if( $subpacket instanceof EmbeddedSignatureSubpacket )
                return $subpacket->signature;
        }
        return null;
    }

    public function getConiksSubpacket()
    {
        foreach($this->hashedPackets as $subpacket)
        {
            if( $subpacket instanceof ConiksDbIdSubpacket )
                return $subpacket;
        }
        return null;
    }

    public function jsonSerialize()
    {
        return $this->encode("base64");
    }
}

?>
