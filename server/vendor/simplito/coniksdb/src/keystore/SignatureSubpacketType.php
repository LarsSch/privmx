<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\keystore;

abstract class SignatureSubpacketType
{
    const SIGNATURE_CREATION_TIME             = 0x02;
    const SIGNATURE_EXPIRATION_TIME           = 0x03;
    const EXPORTABLE_CERTIFICATION            = 0x04;
    const THRUST_SIGNATURE                    = 0x05;
    const REGULAR_EXPRESSION                  = 0x06;
    const REVOCABLE                           = 0x07;
    const KEY_EXPIRATION_TIME                 = 0x09;
    const PREFERRED_SYMMETRIC_ALGORITHMS      = 0x0B;
    const REVOCATION_KEY                      = 0x0C;
    const ISSUER                              = 0x10;
    const NOTATION_DATA                       = 0x14;
    const PREFERRED_HASH_ALGORITHMS           = 0x15;
    const PREFERRED_COMPRESSION_ALGORITHMS    = 0x16;
    const KEY_SERVER_PREFERENCES              = 0x17;
    const PREFERRED_KEY_SERVER                = 0x18;
    const PRIMARY_USER_ID                     = 0x19;
    const POLICY_URI                          = 0x1A;
    const KEY_FLAGS                           = 0x1B;
    const SIGNER_USER_ID                      = 0x1C;
    const REASON_FOR_REVOCATION               = 0x1D;
    const FEATURES                            = 0x1E;
    const SIGNATURE_TARGET                    = 0x1F;
    const EMBEDDED_SIGNATURE                  = 0x20;
    // PRIVATE USAGE
    const CONIKS_DB_ID                        = 0x6E;
}

?>
