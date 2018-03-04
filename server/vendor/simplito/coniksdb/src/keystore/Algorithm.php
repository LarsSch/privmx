<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\keystore;

abstract class Algorithm
{
    // Public key algorithms
    const RSA =                     0x01;
    const RSA_ENCRYPT_ONLY =        0x02;
    const RSA_SIGN_ONLY =           0x03;
    const ELGAMAL_ENCRYPT_ONLY =    0x10;
    const DSA =                     0x11;
    const ECDH =                    0x12;
    const ECDSA =                   0x13;
    const ELGAMAL =                 0x14;
    const DIFFIE_HELLMAN =          0x15;

    // Hash algorithms
    const MD5 =                     0x01;
    const SHA1 =                    0x02;
    const RIPEMD160 =               0x03;
    const SHA256 =                  0x08;
    const SHA384 =                  0x09;
    const SHA512 =                  0x0A;
    const SHA224 =                  0x0B;

    // Symmetric algorithms
    const PLAIN                   = 0x00;
    const IDEA                    = 0x01;
    const TRIPLE_DES              = 0x02;
    const CAST5                   = 0x03;
    const BLOWFISH                = 0x04;
    const AES128                  = 0x07;
    const AES192                  = 0x08;
    const AES256                  = 0x09;
    const TWOFISH                 = 0x0A;
}

?>
