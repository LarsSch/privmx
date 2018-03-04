<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\keystore;

abstract class KeyFlag
{
    const CERTIFICATION           = 0x01;
    const SIGNING                 = 0x02;
    const ENCRYPT_COMMUNICATION   = 0x04;
    const ENCRYPT_STORAGE         = 0x08;
    const SPLIT_KEY               = 0x10;
    const AUTHENTICATION          = 0x20;
    const GROUP_KEY               = 0x80;
}

?>
