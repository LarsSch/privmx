<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\keystore;

use Elliptic\EC;
use \Exception;

abstract class KeyPairBase extends Packet
{
    private static $oids = [
        "2B8104000A" => "secp256k1",
        "2B06010401DA470F01" => "ed25519",
        "2A8648CE3D030107" => "p256",
        "2B81040022" => "p384",
        "2B81040023" => "p521"
    ];

    private static function ec2oid($ec)
    {
        $ec = strtolower($ec);
        foreach(self::$oids as $oid => $ecName)
        {
            if( $ec === $ecName )
                return $oid;
        }

        throw new Exception("Unknown elliptic curve name {$ec}");
    }

    private static function oid2ec($oid)
    {
        $oid = strtoupper($oid);
        if( !isset(self::$oids[$oid]) )
            throw new Exception("Unknown oid {$oid}");

        return self::$oids[$oid];
    }

    protected static function decodeBody($data, $hasPrivkey)
    {
        $version = ord($data[0]);
        if( $version !== Packet::VERSION )
            throw new Exception("Unsupported packet version {$version}");

        $data = substr($data, 1);
        $result = array();
        $result["timestamp"] = unpack("Nuint32", $data)["uint32"];
        $result["keyAlgorithm"] = ord($data[4]);

        $length = ord($data[5]);
        $oid = bin2hex(substr($data, 6, $length));
        $result["ecName"] = self::oid2ec($oid);

        $data = substr($data, $length + 6);
        $length = ceil( unpack("nuint16", $data)["uint16"] / 8 );

        $ec = new EC($result["ecName"]);
        $result["key"] = $ec->keyFromPublic(substr($data, 2, $length));

        $data = substr($data, $length + 2);
        if( $result["keyAlgorithm"] === Algorithm::ECDH )
        {
            if( ord($data[0]) !== 0x3 || ord($data[1]) !== 0x1 )
                throw new Exception("Incorrect KDF params");
            $result["hashAlgorithm"] = ord($data[2]);
            $result["symmetricAlgorithm"] = ord($data[3]);
            $data = substr($data, 4);
        }

        if( $hasPrivkey )
        {
            $s2kType = ord($data[0]);
            if( $s2kType !== 0 )
                throw new Exception("Unsupported S2K type {$s2kType}");
            $data = substr($data, 1);
            $length = ceil( unpack("nuint16", $data)["uint16"] / 8 );
            $priv = $ec->keyFromPrivate(bin2hex( substr($data, 2, $length) ));

            $priv->pub = $result["key"]->pub;
            /*
            if( !$priv->getPublic()->eq($result["key"]->getPublic()) )
                throw new Exception("Decoded private key doesn't match public");
            */
            $result["key"] = $priv;
        }

        $validate = $result["key"]->validate();
        if( !$validate["result"] )
        {
            $reason = $validate["reason"] ? $validate["reason"] : "";
            throw new Excetpion("Invalid key: {$reason}");
        }

        return $result;
    }

    protected $privTag = Packet::SECRET_KEY;
    protected $pubTag = Packet::PUBLIC_KEY;
    protected $keyAlgorithm = Algorithm::ECDSA;
    public $signatures = [];
    protected $timestamp = 0;
    protected $ecName = "secp256k1";
    public $keyPair = null;

    // KDF params
    protected $hashAlgorithm = Algorithm::MD5;
    protected $symmetricAlgorithm = Algorithm::PLAIN;

    public function __construct($options = null)
    {
        $this->timestamp = isset($options["timestamp"]) ? $options["timestamp"] : time();
        if( isset($options["keyAlgorithm"]) )
            $this->keyAlgorithm = $options["keyAlgorithm"];

        if( $this->keyAlgorithm !== Algorithm::ECDSA && 
            $this->keyAlgorithm !== Algorithm::ECDH )
        {
            $algorithm = $this->keyAlgorithm;
            throw new Exception("Unsupported key algorithm {$algorithm}");
        }

        if( isset($option["ecName"]) )
            $this->ecName = $options["ecName"];

        if( isset($options["key"]) )
            $this->keyPair = $options["key"];
        else
        {
            $ec = new EC($this->ecName);
            $this->keyPair = $ec->genKeyPair();
        }

        if( isset($options["hashAlgorithm"]) )
            $this->hashAlgorithm = $options["hashAlgorithm"];

        if( isset($options["symmetricAlgorithm"]) )
            $this->symmetricAlgorithm = $options["symmetricAlgorithm"];
    }

    public abstract function getPrimaryUserId();
    public abstract function getRevokeSignature();
    public abstract function getBindSignature();
    public abstract function getKeyById($id);
    public abstract function isValidRevokeKey($key);

    public function isRevoked()
    {
        return $this->getRevokeSignature() !== null;
    }

    public function getRevokeTime()
    {
        $signature = $this->getRevokeSignature();
        if( $signature === null )
            return -1;
        return $signature->getTimestamp();
    }

    public function getPublic($compact = false, $enc = "binary")
    {
        if( $enc === "hex" || $compact === "hex" )
            $enc = "hex";
        return $this->keyPair->getPublic($compact === true, $enc);
    }

    public function getPrivate($enc = "bn") // default returns BN
    {
        return $this->keyPair->getPrivate($enc);
    }

    public function setPrivate($key)
    {
        $key = $this->keyPair->ec->keyFromPrivate($key);
        if( $key->getPublic("hex") !== $this->getPublic("hex") )
            throw new Exception("Private key doesn't match public");
        $this->keyPair = $key;
    }

    public function removePrivate()
    {
        if( $this->getPrivate() === null )
            return;

        $pub = $this->getPublic();
        $this->keyPair = $this->keyPair->ec->keyFromPublic($pub);
    }

    private function getPublicMpi()
    {
        $oid = hex2bin(self::ec2oid($this->ecName));
        $pubkey = hex2bin( $this->getPublic("hex") );
        $length = strlen($pubkey) * 8;
        $byte = ord($pubkey[0]);
        for($i = 7; $i > 0; --$i)
        {
            if( $byte & (1 << $i) )
                break;
            --$length;
        }
        return chr(strlen($oid)) . $oid . pack("n", $length) . $pubkey;
    }

    private function encodePublic()
    {
        return chr(Packet::VERSION) . pack("N", $this->timestamp) .
            chr($this->keyAlgorithm) .$this->getPublicMpi();
    }

    public function getFingerprint($enc = "hex", $unhashed = false)
    {
        $data = $this->encodePublic();
        $fingerprint = chr(0x99) . pack("n", strlen($data)) . $data;
        if( !$unhashed )
            $fingerprint = hash("sha1", $fingerprint, true);

        return $enc === "hex" ? strtoupper( bin2hex($fingerprint) ) : $fingerprint;
    }

    public function getKeyId($enc = "hex")
    {
        $id = substr($this->getFingerprint("binary"), -8);
        return $enc === "hex" ? strtoupper( bin2hex($id) ) : $id;
    }

    public function getFlags()
    {
        $signature = $this->getBindSignature();
        if( $signature === null )
            return 0;

        return $signature->getKeyFlags();
    }

    private function encodeKDF()
    {
        if( $this->keyAlgorithm !== Algorithm::ECDH )
            return "";

        return chr(0x3) . chr(0x1) . chr($this->hashAlgorithm) .
            chr($this->symmetricAlgorithm);
    }

    private function encodeS2K()
    {
        $privKey = $this->getPrivate();
        if( $privKey === null )
            return "";
        $arr = $privKey->toArray();
        $checksum = 0;
        $result = chr(0) . pack("n", $privKey->bitLength());
        $checksum += ord($result[1]) % 0x10000;
        $checksum += ord($result[2]) % 0x10000;
        foreach($arr as $byte)
        {
            $checksum = ($checksum + $byte) % 0x10000;
            $result .= chr($byte);
        }

        $result .= pack("n", $checksum);
        return $result;
    }

    public function hasPrivate()
    {
        return $this->getPrivate() !== null;
    }

    public function hasAnyPrivate()
    {
        return $this->hasPrivate();
    }

    protected function getTag()
    {
        return $this->hasPrivate() ? $this->privTag : $this->pubTag;
    }

    protected function getArmor()
    {
        return $this->hasPrivate() ? "PRIVATE KEY BLOCK" : "PUBLIC KEY BLOCK";
    }

    protected function getBody()
    {
        return $this->encodePublic() . $this->encodeKDF() . $this->encodeS2K();
    }

    public function encodeRaw()
    {
        return parent::encodeRaw() . Packet::concat($this->signatures);
    }

    public function verify($msg, $signature)
    {
        if( $signature instanceof Signature )
            return $signature->verify(array("msg" => $msg), $this);

        return $this->keyPair->verify($msg, $signature);
    }

    public function sign($msg)
    {
        if( is_string($msg) )
            return $this->keyPair->sign($msg);

        return Signature::create($msg, $this);
    }

    public function validate()
    {
        foreach($this->signatures as $signature)
        {
            switch($signature->type)
            {
                case SignatureType::SUBKEY_REVOCATION_SIGNATURE:
                    $key = $this->getKeyById($signature->getIssuerId());
                    if( !$key || !$signature->verify(array("subkey" => $this), $key) )
                        return false;
                    break;
                case SignatureType::KEY_REVOCATION_SIGNATURE:
                    if( !$signature->verify(array("key" => $this), $this) )
                        return false;
                    break;
            }
        }
        return true;
    }
}

class SubKeyPair extends KeyPairBase
{
    protected $privTag = Packet::SECRET_SUBKEY;
    protected $pubTag = Packet::PUBLIC_SUBKEY;
    private $parent = null;

    public function __construct($parent, $options = null)
    {
        parent::__construct($options);
        $this->parent = $parent;
    }

    public function getPrimaryUserId()
    {
        if( $this->parent === null )
            return "";
        return $this->parent->getPrimaryUserId();
    }

    public function getRevokeSignature()
    {
        foreach($this->signatures as $signature)
        {
            if( $signature->type === SignatureType::SUBKEY_REVOCATION_SIGNATURE )
                return $signature;
        }
        return null;
    }

    public function getBindSignature()
    {
        if( $this->parent === null )
            return null;

        $result = null;
        foreach($this->signatures as $signature)
        {
            if( $signature->type === SignatureType::SUBKEY_BINDING_SIGNATURE &&
                $signature->getIssuerId() === $this->parent->getKeyId() && ($result === null ||
                $result->getTimestamp() < $signature->getTimestamp()) )
            {
                $result = $signature;
            }
        }
        return $result;
    }

    public function getKeyById($id)
    {
        $id = strtoupper($id);
        if( $this->getKeyId() === $id )
            return $this;

        if( $this->parent === null )
            return null;

        return $this->parent->getKeyById($id);
    }

    public function isValidRevokeKey($key)
    {
        if( $this->parent !== null && $this->parent->getKeyId() === $key->getKeyId() )
            return true;

        foreach($this->getBindSignature()->getRevocationKeys() as $fingerprint)
        {
            if( $key->getFingerprint("binary") === $fingerprint )
                return true;
        }
        return false;
    }

    public function bind($flags)
    {
        $options = array(
            "type" => SignatureType::SUBKEY_BINDING_SIGNATURE,
            "data" => array(
                "key" => $this->parent,
                "subkey" => $this
            ),
            "hashed" => array(
                new RevocationKeySubpacket($this->keyAlgorithm, $this->getFingerprint("binary")),
                new KeyFlagsSubpacket($flags)
            )
        );

        if( $flags & KeyFlag::SIGNING )
        {
            $embedded = Signature::create(array(
                "type" => SignatureType::PRIMARY_KEY_BINDING_SIGNATURE,
                "data" => array(
                    "key" => $this->parent,
                    "subkey" => $this
                ),
                "hashed" => array(
                    new KeyFlagsSubpacket($flags)
                )
            ), $this);

            array_push($options["hashed"], new EmbeddedSignatureSubpacket($embedded));
        }

        array_push($this->signatures, Signature::create($options, $this->parent));
    }

    public function validate()
    {
        if( !parent::validate() || $this->parent === null )
            return false;

        $signature = $this->getBindSignature();
        if( $signature === null )
            return false;

        $data = array(
            "key" => $this->parent,
            "subkey" => $this
        );

        if( $signature->getKeyFlags() & KeyFlag::SIGNING )
        {
            $embedded = $signature->getEmbedded();
            if( $embedded === null || !$embedded->verify($data, $this) ||
                $embedded->type !== SignatureType::PRIMARY_KEY_BINDING_SIGNATURE )
            {
                return false;
            }
        }

        return $signature->verify($data, $this->parent);
    }

    public function revoke($self = false)
    {
        if( $this->isRevoked() )
            return;

        $key = $self ? $this : $this->parent;
        $signature = Signature::create(array(
            "type" => SignatureType::SUBKEY_REVOCATION_SIGNATURE,
            "data" => array(
                "subkey" => $this
            )
        ), $key);

        array_splice($this->signatures, 0, 0, array($signature));
    }

    public static function decode($parent, $data)
    {
        list($tag, $body, $additional) = Packet::decodePacket($data);

        if( $tag !== Packet::PUBLIC_SUBKEY && $tag !== Packet::SECRET_SUBKEY )
            throw new Exception("Incorrect tag for Subkey {$tag}");

        $options = KeyPairBase::decodeBody($body, $tag === Packet::SECRET_SUBKEY);
        $result = new SubKeyPair($parent, $options);
        while( strlen($additional) > 0 && Packet::isTag(ord($additional[0]), Packet::SIGNATURE) )
        {
            list($signature, $additional) = Signature::decode($additional);
            array_push($result->signatures, $signature);
        }

        return array($result, $additional);
    }
}

class KeyPair extends KeyPairBase
{
    public $userIds = [];
    public $subkeys = [];

    public function getPrimaryUserId()
    {
        foreach($this->userIds as $userId)
        {
            if( $userId->validate() )
                return $userId->name;
        }
        return "";
    }

    public function getRevokeSignature()
    {
        foreach($this->signatures as $signature)
        {
            if( $signature->type === SignatureType::KEY_REVOCATION_SIGNATURE )
                return $signature;
        }
        return null;
    }

    public function getBindSignature()
    {
        $result = null;

        foreach($this->userIds as $userId)
        {
            $signature = $userId->getBindSignature();
            if( $signature !== null && ($result === null || $result->getTimestamp() < $signature->getTimestamp()) )
                $result = $signature;
        }

        return $result;
    }

    public function getKeyById($id)
    {
        $id = strtoupper($id);
        if( $this->getKeyId() === $id )
            return $this;

        foreach($this->subkeys as $subkey)
        {
            if( $subkey->getKeyId() === $id )
                return $subkey;
        }

        return null;
    }

    public function isValidRevokeKey($key)
    {
        return $this->getKeyId() === $key->getKeyId();
    }

    public function removePrivate()
    {
        parent::removePrivate();
        foreach($this->subkeys as $subkey)
            $subkey->removePrivate();
    }

    public function validate()
    {
        if( !parent::validate() )
            return false;

        if( count($this->userIds) === 0 )
            return false;

        foreach($this->userIds as $userId)
        {
            if( !$userId->validate() )
                return false;
        }

        foreach($this->subkeys as $subkey)
        {
            if( !$subkey->validate() )
                return false;
        }

        return true;
    }

    public function revoke()
    {
        if( $this->isRevoked() )
            return;

        $signature = Signature::create(array(
            "type" => SignatureType::KEY_REVOCATION_SIGNATURE,
            "data" => array(
                "key" => $this
            )
        ), $this);

        array_splice($this->signatures, 0, 0, array($signature));
    }

    public function addSubkey($flags = 0, $options = null)
    {
        if( !$flags )
            $flags = KeyFlag::CERTIFICATION | KeyFlag::SIGNING;

        if( !$options )
        {
            $options = array(
                "keyAlgorithm" => $this->keyAlgorithm,
                "key" => $this->keyPair->ec->genKeyPair(),
                "ecName" => $this->ecName,
                "hashAlgorithm" => $this->hashAlgorithm,
                "symmetricAlgorithm" => $this->symmetricAlgorithm
            );
        }

        $subkey = new SubKeyPair($this, $options);
        $subkey->bind($flags);
        array_push($this->subkeys, $subkey);

        return $subkey;
    }

    public function addUserId($name, $flags = 0)
    {
        if( !$flags )
            $flags = KeyFlag::CERTIFICATION | KeyFlag::SIGNING;

        $userId = new UserId($this, $name);
        $userId->bind($flags);
        array_push($this->userIds, $userId);

        return $userId;
    }

    public function encodeRaw()
    {
        return parent::encodeRaw() . Packet::concat($this->userIds) . Packet::concat($this->subkeys);
    }

    public function hasAnyPrivate()
    {
        if ($this->hasPrivate()) {
            return true;
        }
        foreach ($this->subkeys as $subkey) {
            if ($subkey->hasPrivate()) {
                return true;
            }
        }
        return false;
    }

    public static function decode($data)
    {
        list($tag, $body, $additional) = Packet::decodePacket($data);

        if( $tag !== Packet::SECRET_KEY && $tag !== Packet::PUBLIC_KEY )
            throw new Exception("Incorrect tag for KeyPair {$tag}");

        $options = KeyPairBase::decodeBody($body, $tag === Packet::SECRET_KEY);
        $result = new KeyPair($options);
        while( strlen($additional) > 0 && Packet::isTag(ord($additional[0]), Packet::SIGNATURE) )
        {
            list($signature, $additional) = Signature::decode($additional);
            array_push($result->signatures, $signature);
        }

        while( strlen($additional) > 0 && Packet::isTag(ord($additional[0]), Packet::USER_ID) )
        {
            list($userId, $additional) = UserId::decode($result, $additional);
            array_push($result->userIds, $userId);
        }

        while( strlen($additional) > 0 && Packet::oneOfTags(ord($additional[0]), array(Packet::SECRET_SUBKEY, Packet::PUBLIC_SUBKEY)) )
        {
            list($subkey, $additional) = SubKeyPair::decode($result, $additional);
            array_push($result->subkeys, $subkey);
        }

        return array($result, $additional);
    }
}

?>
