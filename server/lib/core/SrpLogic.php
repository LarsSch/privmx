<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

use BI\BigInteger;

/*========================*/
/*     LOGIC HELPERS      */
/*========================*/

class Helper {
    
    public static function H($bytes) {
        return hash("sha256", $bytes, false);
    }
    
    public static function PAD($a, $b) {
        $a2 = $a->toBytes();
        $b2 = $b->toBytes();
        $a2length = strlen($a2);
        $b2length = strlen($b2);
        if ($a2length < $b2length) {
            $zeros = implode(array_map("chr", array_fill(0, $b2length - $a2length, 0)));
            return $zeros.$a2;
        }
        else {
            return $a2;
        }
    }
}

/*========================*/
/*         LOGIC          */
/*========================*/

class SrpLogic {
    
    public static function get_k($N, $g) {
        return new BigInteger(Helper::H(Helper::PAD($N, $N).Helper::PAD($g, $N)), 16);
    }
    
    public static function get_b() {
        return new BigInteger(Crypto::randomBytes(64), 256);
    }
    
    public static function get_big_B($g, $N, $k, $b, $v) {
        return $k->mul($v)->add($g->powMod($b, $N))->mod($N);
    }
    
    public static function get_u($A, $B, $N) {
        return new BigInteger(Helper::H(Helper::PAD($A, $N).Helper::PAD($B, $N)), 16);
    }
    
    public static function getServer_S($A, $v, $u, $b, $N) {
        return $A->mul($v->powMod($u, $N))->powMod($b, $N);
    }
    
    public static function get_M1($A, $B, $S, $N) {
        return new BigInteger(Helper::H(Helper::PAD($A, $N).Helper::PAD($B, $N).Helper::PAD($S, $N)), 16);
    }
    
    public static function get_M2($A, $M1, $S, $N) {
        return new BigInteger(Helper::H(Helper::PAD($A, $N).Helper::PAD($M1, $N).Helper::PAD($S, $N)), 16);
    }
    
    public static function get_big_K($S, $N, $binary = false) {
        $K = new BigInteger(Helper::H(Helper::PAD($S, $N)), 16);
        return $binary === true ? $K->toBytes() : $K;
    }
    
    public static function valid_A($A, $N) {
        return !$A->mod($N)->equals(0);
    }
    
    public static function getConfig() {
        $conf = array(
            "N" => new BigInteger("eeaf0ab9adb38dd69c33f80afa8fc5e86072618775ff3c0b9ea2314c9c256576d674df7496ea81d3383b4813d692c6e0e0d5d8e250b98be48e495c1d6089dad15dc7d7b46154d6b6ce8ef4ad69b15d4982559b297bcf1885c529f566660e57ec68edbc3c05726cc02fd4cbf4976eaa9afd5138fe8376435b9fc61d2fc0eb06e3", 16),
            "g" => new BigInteger("2", 16)
        );
        $conf["k"] = SrpLogic::get_k($conf["N"], $conf["g"]);
        return $conf;
    }

    public static function valid_B($B, $N) {
        return !$B->mod($N)->equals(0);
    }

    public static function get_x($s, $I, $P) {
        return new BigInteger(Helper::H($s->toBytes() . hex2bin(Helper::H($I . ":" . $P))), 16);
    }

    public static function get_v($g, $N, $x) {
        return $g->powMod($x, $N);
    }

    public static function get_A($g, $N, $a) {
        return $g->powMod($a, $N);
    }

    public static function getClient_S($B, $k, $v, $a, $u, $x, $N) {
        return $B->sub($k->mul($v))->powMod($a->add($u->mul($x)), $N);
    }

    public static function get_small_a() {
        return new BigInteger(Crypto::randomBytes(64), 256);
    }
}

?>
