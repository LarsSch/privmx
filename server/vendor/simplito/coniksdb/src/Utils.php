<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
namespace privmx\pki;

use BN\BN;

class Utils {
    /**
     * Returns the number of milliseconds elapsed since 1 January 1970 00:00:00 UTC till now
     *
     * @return integer The number of milliseconds elapsed since 1 January 1970 00:00:00 UTC till now
     */
    static function tstamp() {
        return floor(1000 * microtime(true));
    }

    /**
     * Returns binary hash (SHA-256) of the given data.
     *
     * @param string $value Data to process.
     * @return string 32-bytes hash of given data
     */
    static function hash($value) {
        return \hash('sha256', $value, true);
    }

    /**
     *
     * @param string $value ...
     * @return BitString Computed index.
     */
    static function index($value) {
        $privkey = Config::serverPrivKey();
        $bn = new BN($privkey->getSecretMultiplier());
        $vrf = new VRF($bn->toString(16));
        $index = new BitString(hex2bin($vrf->vrf($value)->encodeCompressed('hex')));

        return $index;
    }

    static function isMap(array $array)
    {
        $count = count($array);
        if( $count === 0 )
            return false;
        return array_keys($array) !== range(0, $count - 1);
    }
}
