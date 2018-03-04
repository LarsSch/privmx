<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

use Elliptic\EC;

class ECUtils {

    public static function generateRandom($curve = "secp256k1")
    {
        $ec = new EC($curve);
        return $ec->genKeyPair();
    }

    public static function toPublic($keyPair)
    {
        $hex = $keyPair->getPublic(true, "hex");
        return $keyPair->ec->keyFromPublic($hex, "hex");
    }

    public static function fromWIF($wif, $curve = "secp256k1")
    {
        $hex = Base58::decodeWithChecksumToHex($wif);
        if( $hex === false || (strlen($hex) != 66 && strlen($hex) != 68) )
            return false;

        $ec = new EC($curve);
        return $ec->keyFromPrivate(substr($hex, 2, 64), "hex");
    }

    public static function toWIF($keyPair, $network = "80", $compressed = true)
    {
        $priv = $network . str_pad($keyPair->getPrivate("hex"), 64, "0", STR_PAD_LEFT);
        if( $compressed )
            $priv .= "01";

        return Base58::encodeHexWithChecksum($priv);
    }

    public static function publicFromBase58DER($base58, $curve = "secp256k1")
    {
        $hex = Base58::decodeWithChecksumToHex($base58);
        if ($hex === false)
            return false;

        $ec = new EC($curve);
        return $ec->keyFromPublic($hex, 'hex');
    }

    public static function publicToBase58DER($keyPair, $compressed = true)
    {
        $hex = $keyPair->getPublic($compressed, "hex");
        return Base58::encodeHexWithChecksum($hex);
    }

    public static function validateAddress($address, $network = "00") {
        $networkBin = Utils::hex2bin($network);
        if( $networkBin === false || strlen($networkBin) != 1 )
            return false;

        $bin = Base58::decodeWithChecksum($address);
        return $bin !== false && strlen($bin) == 21 && $bin[0] == $networkBin[0];
    }

    public static function verifySignature($pubkey, $signature, $data)
    {
        if( strlen($signature) != 65 )
            return false;

        $r = bin2hex( substr($signature, 1, 32) );
        $s = bin2hex( substr($signature, 33) );

        return $pubkey->verify(bin2hex($data), array("r" => $r, "s" => $s));
    }

    public static function toBase58Address($key, $network = "00")
    {
        $networkBin = Utils::hex2bin($network);
        if( $networkBin === false || strlen($networkBin) != 1 )
            return false;

        $data = Utils::hex2bin( $key->getPublic(true, "hex") );
        $hash160 = hash("ripemd160", hash("sha256", $data, true), true);
        return Base58::encodeWithChecksum($networkBin . $hash160);
    }
}

?>
