<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\keystore;

abstract class SignatureType
{
    const BINARY_DOCUMENT_SIGNATURE               = 0x00;
    const TEXT_DOCUMENT_SIGNATURE                 = 0x01;
    const STANDALONE_SIGNATURE                    = 0x02;
    const GENERIC_PK_USER_ID_CERTIFICATION        = 0x10;
    const PERSONA_PK_USER_ID_CERTIFICATION        = 0x11;
    const CASUAL_PK_USER_ID_CERTIFICATION         = 0x12;
    const POSITIVE_PK_USER_ID_CERTIFICATION       = 0x13;
    const SUBKEY_BINDING_SIGNATURE                = 0x18;
    const PRIMARY_KEY_BINDING_SIGNATURE           = 0x19;
    const SIGNATURE_DIRECTLY_ON_KEY               = 0x1F;
    const KEY_REVOCATION_SIGNATURE                = 0x20;
    const SUBKEY_REVOCATION_SIGNATURE             = 0x28;
    const CERTIFICATION_REVOCATION_SIGNATURE      = 0x30;
    const TIMESTAMP_SIGNATURE                     = 0x40;
    const THIRD_PARTY_CONFIRMATION_SIGNATURE      = 0x50;
}

?>
