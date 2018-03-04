<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
namespace PSON;

const T_ZERO       = 0x00; // 0
//                   0x01; // -1
//                   0x02; // 1
//                   ...   // zig-zag encoded varints
const T_MAX        = 0xEF; // -120, max. zig-zag encoded varint

const T_NULL       = 0xF0; // null
const T_TRUE       = 0xF1; // true
const T_FALSE      = 0xF2; // false
const T_EOBJECT    = 0xF3; // {}
const T_EARRAY     = 0xF4; // []
const T_ESTRING    = 0xF5; // ""
const T_OBJECT     = 0xF6; // {...}
const T_ARRAY      = 0xF7; // [...]
const T_INTEGER    = 0xF8; // number (zig-zag encoded varint32)
const T_LONG       = 0xF9; // Long   (zig-zag encoded varint64)
const T_FLOAT      = 0xFA; // number (float32)
const T_DOUBLE     = 0xFB; // number (float64)
const T_STRING     = 0xFC; // string (varint length + data)
const T_STRING_ADD = 0xFD; // string (varint length + data, add to dictionary)
const T_STRING_GET = 0xFE; // string (varint index to get from dictionary)
const T_BINARY     = 0xFF; // bytes (varint length + data)
