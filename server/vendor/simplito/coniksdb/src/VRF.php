<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
namespace privmx\pki;

use Elliptic\EC\KeyPair;
use Elliptic\EC;
use BN\BN;
use BI\BigInteger;

class VRF
{
    private $ec;
    private $key;
    private $cache = array();

    /**
     * @param KeyPair|BN|string $key
     */
    function __construct($key) {
        if(is_string($key) || ($key instanceof BN) ){
            $this->ec = new EC('secp256k1');

            $this->key = $this->ec->keyFromPrivate($key);
            
            return;
        }
        $this->key = $key;
        $this->ec = $this->key->ec;
    }

    /**
     * @param string $m Message to hash
     * @return BN The bignumber hash of the message
     */
    private function H2($m) {
        return (new BN( hash('sha256', $m), 'hex' ))->umod($this->ec->n);
    }

    /**
     * @param string $m Message to hash
     * @return Point The ECC curve point for the message
     */
    private function H1($m) {
        $mn = $this->H2($m);
        return $this->ec->g->mul($mn);
    }

    /** 
     * @param string $m Message to hash
     * @return Point The ECC curve point for the message
     */
    public function vrf($m) {
        if (isset($this->cache[$m]))
            return $this->cache[$m];
        if ($this->key->getPrivate() == null)
            throw new \Exception("To generate VRF you need private key");
        $h = $this->H1($m);
        $vrf = $h->mul($this->key->getPrivate());
        $this->cache[$m] = $vrf;
        return $vrf;
    }
 
    /**
     * @param string $m Message
     * @return array
     */
    function proof($m, $r = null) {
        if ($this->key->getPrivate() == null)
            throw new \Exception("To generate proof you need private key");
        $h = $this->H1($m);
        if ($r == null)
            $r = new BN( new BigInteger(openssl_random_pseudo_bytes(254), 256) );
        $s = $this->H2(
            $m .
            hex2bin($this->ec->g->mul($r)->encode('hex')) .
            hex2bin($h->mul($r)->encode('hex')) );
        $t = $r->sub($s->mul($this->key->getPrivate()))->umod($this->ec->n);    
        //$t = $math->mod( $math->sub( $r, $math->mul($s, $k)), $n );
        return [$s, $t];
    }
  
    /**
     * @param string $m Message
     * @param mixed  $v VRF of the message
     * @param mixed  $p Proof for VRF
     * @return VRFProof 
     */
    public function verify($m, $v, $p) {
        list($s,$t) = $p;
        $h = $this->H1($m);
        $sv = $this->H2(
            $m .
            hex2bin($this->ec->g->mul($t)->add( 
                $this->key->getPublic()->mul($s)
                )->encode('hex') ) .
            hex2bin($h->mul($t)->add( $v->mul($s))->encode('hex')));
        return $sv->eq($s);
    }
}
