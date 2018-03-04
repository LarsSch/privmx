<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\keystore;

use Exception;

class PkiData {
    
    public static function decode($data) {
        list($pkiDataType) = PkiDataTypePacket::decode($data);
        if ($pkiDataType->type == Packet::TYPE_KEYSTORE) {
            return KeyStore::decode($data);
        }
        if ($pkiDataType->type == Packet::TYPE_DOCUMENT) {
            return DocumentsPacket::decode($data);
        }
        throw new Exception("Unsupported pki data type " . $pkiDataType->type);
    }
}