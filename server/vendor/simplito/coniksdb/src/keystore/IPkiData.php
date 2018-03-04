<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\keystore;

interface IPkiData {
    
    function validate();
    function isValidToSave();
    function getAttachmentView($includeAttachments);
    function generateKis($hash);
    function verifyKis(Signature $kis);
    function isCompatibleWithPrevious(Signature $kis, $prev);
}
