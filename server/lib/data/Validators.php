<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\data;

use io\privfs\config\Config;
use io\privfs\core\Validator;

class Validators {
    
    public function createObject($spec) {
        return array("type" => "object", "spec" => $spec);
    }
    
    public function createObjectNCTOOE($spec) {
        return array("type" => "object", "noConvertToObjectOnEmpty" => true, "spec" => $spec);
    }
    
    public function createEnum($values) {
        return array("type" => "enum", "values" => $values);
    }
    
    public function createConst($value) {
        return array("type" => "const", "value" => $value);
    }
    
    public function createMap($keySpec, $valSpec) {
        return array("type" => "map", "keySpec" => $keySpec, "valSpec" => $valSpec);
    }
    
    public function optional($spec) {
        return array_merge($spec, array("required" => false));
    }
    
    public function length($spec, $length) {
        return array_merge($spec, array("length" => $length));
    }
    
    public function minLength($spec, $minLength) {
        return array_merge($spec, array("minLength" => $minLength));
    }
    
    public function maxLength($spec, $maxLength) {
        return array_merge($spec, array("maxLength" => $maxLength));
    }
    
    public function rangeLength($spec, $minLength, $maxLength) {
        return array_merge($spec, array("minLength" => $minLength, "maxLength" => $maxLength));
    }
    
    public function binLength($spec, $length) {
        return array_merge($spec, array("binLength" => $length));
    }
    
    public function minBinLength($spec, $minBinLength) {
        return array_merge($spec, array("minBinLength" => $minBinLength));
    }
    
    public function maxBinLength($spec, $maxBinLength) {
        return array_merge($spec, array("maxBinLength" => $maxBinLength));
    }
    
    public function rangeBinLength($spec, $minBinLength, $maxBinLength) {
        return array_merge($spec, array("minBinLength" => $minBinLength, "maxBinLength" => $maxBinLength));
    }
    
    public function min($spec, $min) {
        return array_merge($spec, array("min" => $min));
    }
    
    public function max($spec, $max) {
        return array_merge($spec, array("max" => $max));
    }
    
    public function range($spec, $min, $max) {
        return array_merge($spec, array("min" => $min, "max" => $max));
    }
    
    public function listOf($spec) {
        return array("type" => "array", "spec" => $spec);
    }
    
    public function listOfWithLength($spec, $length) {
        return array("type" => "array", "spec" => $spec, "length" => $length);
    }
    
    public function listOfWithMinLength($spec, $minLength) {
        return array("type" => "array", "spec" => $spec, "minLength" => $minLength);
    }
    
    public function listOfWithMaxLength($spec, $maxLength) {
        return array("type" => "array", "spec" => $spec, "maxLength" => $maxLength);
    }
    
    public function listOfWithRangeLength($spec, $minLength, $maxLength) {
        return array("type" => "array", "spec" => $spec, "minLength" => $minLength, "maxLength" => $maxLength);
    }
    
    public function addField($objSpec, $fieldSpec) {
        return $this->createObject(array_merge($objSpec["spec"], $fieldSpec));
    }
    
    public function oneOf($spec, $errorName = null) {
        $res = array("type" => "oneOf", "spec" => $spec);
        if (!is_null($errorName)) {
            $res["errorName"] = $errorName;
        }
        return $res;
    }
    
    public function maybeEmpty($spec) {
        return $this->oneOf(array($spec, $this->length($this->{$spec["type"]}, 0)), isset($spec["errorName"]) ? $spec["errorName"] : null);
    }
    
    public function maybeEmptyString($spec) {
        return $this->oneOf(array($spec, $this->length($this->string, 0)), isset($spec["errorName"]) ? $spec["errorName"] : null);
    }
    
    public function extend($spec, $extension) {
        return array_merge($spec, $extension);
    }
    
    public function primitive($name) {
        return array("type" => $name);
    }
    
    public function error($spec, $name) {
        return array_merge($spec, array("errorName" => $name));
    }
    
    public function alternativeBuffer($spec) {
        $clone = array_merge($spec, $this->buffer);
        $errorName = null;
        if( isset($spec["errorName"]) )
            $errorName = $spec["errorName"];
        return $this->oneOf(array($spec, $clone), $errorName);
    }
    
    public function withAttachments($spec, $attachmentsSpec) {
        return array_merge($spec, array("attachments" => $attachmentsSpec));
    }
    
    public function attachments($type, $spec) {
        return array("type" => $type, "spec" => $spec);
    }
    
    public function createAcl($entryType, $aclType, $propertyType, $maxEntryLength, $maxAclLength, $maxAclListLength) {
        $acls = $this->listOfWithMaxLength($this->createObject(array(
            "type" => $this->createEnum(array(0, 1, 2, 3)),
            "property" => $propertyType,
            "list" => $this->listOfWithMaxLength($aclType, $maxAclListLength)
        )), $maxAclLength);
        return $this->createObject(array(
            "defaultAcls" => $this->optional($acls),
            "list" => $this->listOfWithMaxLength($this->oneOf(array(
                $entryType,
                $this->createObject(array(
                    "value" => $entryType,
                    "acls" => $acls
                ))
            )), $maxEntryLength)
        ));
    }
    
    public function __construct(Config $config) {
        $this->config = $config;
        
        $this->string = $this->primitive("string");
        $this->image = $this->primitive("image");
        $this->hex = $this->primitive("hex");
        $this->bi10 = $this->primitive("bi10");
        $this->bi16 = $this->primitive("bi16");
        $this->base64 = $this->primitive("base64");
        $this->base58 = $this->primitive("base58");
        $this->eccPub = $this->primitive("eccpub");
        $this->eccPriv = $this->primitive("eccpriv");
        $this->eccAddr = $this->primitive("eccaddr");
        $this->pkiKeystoreBase64 = $this->primitive("pki.keystore.base64");
        $this->pkiDocumentBase64 = $this->primitive("pki.document.base64");
        $this->pkiSignatureBase64 = $this->primitive("pki.signature.base64");
        $this->email = $this->error($this->primitive("email"), "INVALID_EMAIL");
        $this->hashmail = $this->error($this->primitive("hashmail"), "INVALID_HASHMAIL");
        $this->bool = $this->primitive("bool");
        $this->int = $this->primitive("int");
        $this->float = $this->primitive("float");
        $this->buffer = $this->primitive("buffer");
        
        $this->eccSignature = $this->error($this->binLength($this->base64, 65), "INVALID_SIGNATURE");
        $this->destination = array("destination" => $this->string);
        $this->orderType = $this->createEnum(array("ASC", "DESC"));
        $this->language = $this->error($this->extend($this->length($this->string, 2), array("alpha" => true, "lowercase" => true)),"INVALID_LANGUAGE");
        $this->emptyObject = $this->createObject(array());
        
        $this->username = $this->extend($this->error($this->rangeLength($this->string, 3, 20), "INVALID_USERNAME"), array("regex" => '/^[a-z0-9]+([._-]?[a-z0-9]+)*$/'));
        $this->usernames = $this->listOfWithMaxLength($this->username, $this->config->getMaxUserBulkSize());
        $this->host = $this->error($this->maxLength($this->string, 100), "INVALID_HOST");
        $this->notEmptyHost = $this->minLength($this->host, 1);
        $this->emailEx = $this->config->getEmailRequired() ? $this->email : $this->maybeEmptyString($this->email);
        $this->description = $this->error($this->maxLength($this->string, 1024), "INVALID_DESCRIPTION");
        $this->loginData = $this->error($this->maxLength($this->string, 1024), "INVALID_LOGIN_DATA");
        $this->srpSalt = $this->error($this->binLength($this->hex, 16), "INVALID_SALT");
        $this->srpVerifier = $this->error($this->maxLength($this->bi16, 256), "INVALID_VERIFIER");
        $this->privData = $this->error($this->maxBinLength($this->base64, 8192), "INVALID_PRIV_DATA");
        $this->pin = $this->extend($this->error($this->length($this->string, 4), "INVALID_PIN"), array("numeric" => true));
        $this->timestamp = $this->error($this->bi10, "INVALID_TIMESTAMP");
        $this->nonce = $this->error($this->rangeLength($this->string, 32, 64), "INVALID_NONCE");
        $this->token = $this->error($this->maxLength($this->string, 100), "INVALID_TOKEN");
        $this->tokenOrUsername = $this->maxLength($this->string, 100);
        $this->dataVersion = $this->rangeLength($this->string, 1, 20);
        
        $this->recoveryData = $this->createObject(array(
            "data" => $this->maxBinLength($this->base64, 8192),
            "pub" => $this->eccPub
        ));
        
        $this->userKeystore = $this->withAttachments($this->pkiKeystoreBase64, array(
            "info" => $this->optional($this->attachments("json", $this->createObject(array(
                "name" => $this->optional($this->extend($this->maxLength($this->string, 100), array("empty" => false))),
                "description" => $this->optional($this->maxLength($this->string, 500)),
                "sinks" => $this->optional($this->maxLength($this->listOf($this->createObject(array(
                    "id" => $this->eccPub,
                    "name" => $this->rangeLength($this->string, 1, 255),
                    "description" => $this->optional($this->maxLength($this->string, 255))
                ))), 20))
            )))),
            "image" => $this->optional($this->attachments("binary", $this->maxBinLength($this->image, 160 * 1024))
        )));
        $this->cosignerKeystore = $this->withAttachments($this->pkiKeystoreBase64, array());
        $this->adminsKeystore = $this->withAttachments($this->pkiDocumentBase64, array(
            "cosigners" => $this->attachments("json", $this->createMap($this->host, $this->cosignerKeystore))
        ));
        
        $this->presence = $this->error($this->createObject(array(
            "status" => $this->maxLength($this->string, 255),
            "timestamp" => $this->timestamp
        )), "INVALID_USER_PRESENCE");
        
        $this->presenceAcl = $this->oneOf(array(
            $this->createObject(array(
                "type" => $this->createEnum(array("all", "noone"))
            )),
            $this->createObject(array(
                "type" => $this->createEnum(array("whitelist")),
                "pubs" => $this->listOf($this->eccPub)
            ))
        ));
        
        $this->userPresenceGet = $this->createObject(array(
            "usernames" => $this->usernames,
            "pub58" => $this->eccPub,
            "nonce" => $this->nonce,
            "timestamp" => $this->timestamp,
            "signature" => $this->eccSignature
        ));
        
        //sprawdzić metody które sa proxowane, bo mogą miec inny interfejs
        //sprawdzić w jakich momentach jest robione (object)array()
        //timestamp w presence trzeba sprawdzić
        //przed serializacja przed zapisem do bazy przerobić obiekty userEntry/presence/presenceAcl
        
        $this->bid = $this->error($this->binLength($this->base58, 32), "INVALID_BID");
        $this->blocks = $this->listOf($this->bid);
        if ($this->config->hasBlockCountLimit()) {
            $this->blocks = $this->error($this->maxLength($this->blocks, $this->config->getMaxBlocksCount()), "MAX_COUNT_OF_BLOCKS_EXCEEDED");
        }
        $this->blockData = $this->error($this->string, "INVALID_DATA");
        if ($this->config->hasBlockLengthLimit()) {
            $this->blockData = $this->maxLength($this->blockData, $this->config->getMaxBlocksLength());
        }
        $this->blockTransferSignature = $this->createObject(array(
            "hashmail" => $this->hashmail,
            "pub" => $this->eccPub,
            "nonce" => $this->nonce,
            "timestamp" => $this->timestamp,
            "signature" => $this->eccSignature
        ));
        
        $this->transferId = $this->binLength($this->hex, 16);
        $this->transferIds = $this->rangeLength($this->listOf($this->transferId), 1, 100);
        
        $this->sid = $this->error($this->eccPub, "INVALID_SID");
        $this->mid = $this->error($this->int, "INVALID_MID");
        $this->mids = $this->listOfWithMaxLength($this->mid, $this->config->getMaxMessageBulkSize());
        $this->seq = $this->int;
        $this->modSeq = $this->error($this->int, "INVALID_MOD_SEQ");
        $this->messageFlags = $this->maxLength($this->string, 1024);
        $this->messageTag = $this->rangeLength($this->string, 1, 64);
        $this->messageTags = $this->listOfWithMaxLength($this->messageTag, 20);
        $this->messageExtra = $this->maxBinLength($this->base64, 1024 * 1024);
        $this->messageData = $this->createObject(array(
            "sid" => $this->sid,
            "senderPub58" => $this->eccPub,
            "blocks" => $this->blocks,
            "extra" => $this->messageExtra,
            "signature" => $this->eccSignature
        ));
        
        $this->sinkAcl = $this->error($this->createEnum(array("private", "public", "shared", "anonymous", "low")), "INVALID_SINK_ACL");
        $this->sinkProxyAclProperties = $this->createEnum(array("senderPub58"));
        $this->sinkOptions = $this->createObject(array(
            "removable" => $this->optional($this->bool),
            "verify" => $this->optional($this->createEnum(array(null, "email"))),
            "proxyTo" => $this->optional($this->createAcl($this->sid, $this->sid, $this->sinkProxyAclProperties, 10, 1, 10)),
            "proxyFrom" => $this->optional($this->createAcl($this->sid, $this->sid, $this->sinkProxyAclProperties, 10000, 1, 10)),
            "lowUsers" => $this->optional($this->listOfWithMaxLength($this->username, 10))
        ));
        $this->sinkData = $this->maxLength($this->string, 1024);
        $this->sinkQueryElement = $this->oneOf(array($this->minLength($this->string, 1), null, null, null));
        $this->sinkQueryDate = $this->createObject(array(
            "operand" => $this->createConst("DATE"),
            "relation" => $this->createEnum(array("HIGHER", "HIGHER_EQUAL", "LOWER", "LOWER_EQUAL", "EQUAL", "NOT_EQUAL")),
            "value" => $this->int
        ));
        $this->sinkQueryUnary = $this->createObject(array(
            "value" => &$this->sinkQueryElement,
            "operand" => $this->createEnum(array("NOT", "PREFIX"))
        ));
        $this->sinkQueryBinary = $this->createObject(array(
            "left" => &$this->sinkQueryElement,
            "operand" => $this->createEnum(array("AND", "OR")),
            "right" => &$this->sinkQueryElement,
        ));
        $this->sinkQueryElement["spec"][1] = &$this->sinkQueryUnary;
        $this->sinkQueryElement["spec"][2] = &$this->sinkQueryBinary;
        $this->sinkQueryElement["spec"][3] = &$this->sinkQueryDate;
        
        $this->did = $this->error($this->eccAddr, "INVALID_DID");
        $this->descriptorExtra = $this->maxBinLength($this->base64, 1024 * 1024);
        $this->descriptorData = $this->createObject(array(
            "blocks" => $this->blocks,
            "extra" => $this->descriptorExtra,
            "signature" => $this->eccSignature,
            "dpub58" => $this->eccPub
        ));
        $this->descriptorLockId = $this->error($this->length($this->string, 32), "INVALID_DESCRIPTOR_LOCK");
        
        $this->notificationsEntry = $this->createObject(array(
            "enabled" => $this->bool,
            "email" => $this->maybeEmptyString($this->email),
            "tags" => $this->messageTags,
            "ignoredDomains" => $this->listOfWithMaxLength($this->host, 100)
        ));
        
        $this->initDataKey = $this->optional($this->maxLength($this->string, 20));
        
        $this->loginProperty = $this->optional($this->maxLength($this->string, 150));
        $this->loginProperties = $this->createObjectNCTOOE(array(
            "appVersion" => $this->loginProperty,
            "sysVersion" => $this->loginProperty
        ));
        
        $this->blocksSource = $this->oneOf(array(
            $this->createObject(array(
                "type" => $this->createConst("descriptor"),
                "did" => $this->did
            )),
            $this->createObject(array(
                "type" => $this->createConst("message"),
                "sid" => $this->sid,
                "mid" => $this->mid
            ))
        ));
        
        $this->documentId = $this->binLength($this->hex, 32);
        $this->documentData = $this->maxLength($this->buffer, 1024 * 1024);
        
        $this->mailConfig = $this->createObject(array(
            "defaultLang" => $this->language,
            "langs" => $this->createMap($this->language, $this->createObject(array(
                "from" => $this->createObject(array(
                    "name" => $this->string,
                    "email" => $this->email
                )),
                "isHtml" => $this->bool,
                "subject" => $this->string,
                "body" => $this->string
            )))
        ));
        
        //===========================================================
        
        $this->ping = $this->emptyObject;
        
        //===========================================================
        
        $this->messagePostInit = $this->createObject(array(
            "sid" => $this->sid,
            "signature" => $this->blockTransferSignature,
            "extra" => $this->optional(
                $this->maxLength($this->string, $this->config->getMaxFormsExtraData())
            )
        ));
        
        $this->messagePost = $this->createObject(array(
            "data" => $this->addField($this->messageData, array("senderHashmail" => $this->hashmail)),
            "tags" => $this->messageTags,
            "transferId" => $this->maybeEmpty($this->transferId),
            "extra" => $this->optional(
                $this->maxLength($this->string, $this->config->getMaxFormsExtraData())
            )
        ));
        
        $this->messageCreateInit = $this->createObject(array(
            "sid" => $this->sid
        ));
        
        $this->messageCreate = $this->createObject(array(
            "data" => $this->messageData,
            "flags" => $this->messageFlags,
            "tags" => $this->messageTags,
            "transferId" => $this->maybeEmpty($this->transferId)
        ));
        
        $this->messageCreateAndDeleteInit = $this->createObject(array(
            "dSid" => $this->sid,
            "dMid" => $this->mid,
            "cSid" => $this->sid
        ));
        
        $this->messageCreateAndDelete = $this->createObject(array(
            "data" => $this->messageData,
            "flags" => $this->messageFlags,
            "tags" => $this->messageTags,
            "transferId" => $this->maybeEmpty($this->transferId),
            "sid" => $this->sid,
            "mid" => $this->mid,
            "expectedModSeq" => $this->modSeq
        ));
        
        $this->messageModify = $this->createObject(array(
            "sid" => $this->sid,
            "mid" => $this->mid,
            "flags" => $this->messageFlags,
            "tags" => $this->messageTags,
            "expectedModSeq" => $this->modSeq
        ));
        
        $this->messageModifyTags = $this->createObject(array(
            "sid" => $this->sid,
            "mids" => $this->mids,
            "toAdd" => $this->messageTags,
            "toRemove" => $this->messageTags,
            "expectedModSeq" => $this->modSeq
        ));
        
        $this->messageGet = $this->createObject(array(
            "sid" => $this->sid,
            "mids" => $this->mids,
            "mode" => $this->createEnum(array("DATA", "META", "DATA_AND_META"))
        ));
        
        $this->messageDelete = $this->createObject(array(
            "sid" => $this->sid,
            "mids" => $this->mids,
            "expectedModSeq" => $this->modSeq
        ));
        
        $this->messageReplaceFlags = $this->createObject(array(
            "sid" => $this->sid,
            "mid" => $this->mid,
            "flags" => $this->messageFlags
        ));
        
        //===========================================================
        
        $this->sinkGetAllMy = $this->emptyObject;
        
        $this->sinkCreate = $this->createObject(array(
            "sid" => $this->sid,
            "acl" => $this->sinkAcl,
            "data" => $this->sinkData,
            "options" => $this->sinkOptions
        ));
        
        $this->sinkSave = $this->createObject(array(
            "sid" => $this->sid,
            "acl" => $this->sinkAcl,
            "data" => $this->sinkData,
            "options" => $this->sinkOptions
        ));
        
        $this->sinkDelete = $this->createObject(array(
            "sid" => $this->sid
        ));
        
        $this->sinkSetLastSeenSeq = $this->createObject(array(
            "sid" => $this->sid,
            "lastSeenSeq" => $this->seq
        ));
        
        $this->sinkPoll = $this->createObject(array(
            "updateLastSeen" => $this->bool,
            "sinks" => $this->listOfWithRangeLength($this->createObject(array(
                "sid" => $this->sid,
                "seq" => $this->seq,
                "modSeq" => $this->modSeq
            )), 1, 20)
        ));
        
        $this->sinkClear = $this->createObject(array(
            "sid" => $this->sid,
            "currentModSeq" => $this->modSeq
        ));
        
        $this->sinkInfo = $this->createObject(array(
            "sid" => $this->sid,
            "addMidList" => $this->bool
        ));
        
        $this->sinkQuery = $this->createObject(array(
            "sid" => $this->sid,
            "query" => $this->sinkQueryElement,
            "limit" => $this->int,
            "order" => $this->createObject(array(
                "by" => $this->createEnum(array("SEQ", "DATE")),
                "type" => $this->orderType
            ))
        ));
        
        $this->sinkGetAllByUser = $this->createObject(array(
            "username" => $this->string
        ));
        
        //===========================================================
        
        $this->blockCreate = $this->createObject(array(
            "transferIds" => $this->transferIds,
            "bid" => $this->bid,
            "data" => $this->alternativeBuffer($this->blockData)
        ));
        
        $this->blockAddToSession = $this->createObject(array(
            "transferIds" => $this->transferIds,
            "source" => $this->blocksSource,
            "blocks" => $this->blocks
        ));
        
        $this->blockGet = $this->createObject(array(
            "bid" => $this->bid,
            "source" => $this->blocksSource
        ));
        
        //===========================================================
        
        $this->srpInfo = $this->createObject(array(
        ));
        
        $this->srpInit = $this->createObject(array(
            "I" => $this->string,
            "host" => $this->string,
            "properties" => $this->loginProperties
        ));
        
        $this->srpExchange = $this->createObject(array(
            "sessionId" => $this->string,
            "A" => $this->bi16,
            "M1" => $this->bi16
        ));
        
        //===========================================================
        
        $this->descriptorCreateInit = $this->emptyObject;
        
        $this->descriptorCreate = $this->createObject(array(
            "did" => $this->did,
            "data" => $this->descriptorData,
            "transferId" => $this->transferId
        ));
        
        $this->descriptorUpdateInit = $this->createObject(array(
            "did" => $this->did,
            "signature" => $this->blockTransferSignature
        ));
        
        $this->descriptorUpdate = $this->createObject(array(
            "did" => $this->did,
            "data" => $this->descriptorData,
            "transferId" => $this->transferId,
            "signature" => $this->eccSignature,
            "lockId" => $this->maybeEmpty($this->descriptorLockId),
            "releaseLock" => $this->bool
        ));
        
        $this->descriptorDelete = $this->createObject(array(
            "did" => $this->did,
            "signature" => $this->eccSignature,
            "lockId" => $this->maybeEmpty($this->descriptorLockId)
        ));
        
        $this->descriptorLock = $this->createObject(array(
            "did" => $this->did,
            "lockId" => $this->descriptorLockId,
            "signature" => $this->eccSignature,
            "lockerPub58" => $this->eccPub,
            "lockerSignature" => $this->eccSignature,
            "force" => $this->bool
        ));
        
        $this->descriptorRelease = $this->createObject(array(
            "did" => $this->did,
            "lockId" => $this->descriptorLockId,
            "signature" => $this->eccSignature
        ));
        
        $this->descriptorGet = $this->createObject(array(
            "dids" => $this->listOfWithMaxLength($this->did, $this->config->getMaxDescriptorBulkSize()),
            "includeBlocks" => $this->optional($this->int)
        ));
        
        $this->descriptorCheck = $this->createObject(array(
            "dids" => $this->listOfWithMaxLength($this->createObject(array(
                "did" => $this->did,
                "signature" => $this->maybeEmpty($this->eccSignature)
            )), $this->config->getMaxDescriptorBulkSize())
        ));
        
        //===========================================================
        
        $this->createUser = $this->createObject(array(
            "username" => $this->username,
            "host" => $this->host,
            "srpSalt" => $this->srpSalt,
            "srpVerifier" => $this->srpVerifier,
            "loginData" => $this->loginData,
            "pin" => $this->maybeEmpty($this->pin),
            "token" => $this->token,
            "privData" => $this->privData,
            "keystore" => $this->userKeystore,
            "kis" => $this->pkiSignatureBase64,
            "signature" => $this->eccSignature,
            "email" => $this->emailEx,
            "language" => $this->language,
            "dataVersion" => $this->dataVersion,
            "weakPassword" => $this->optional($this->bool)
        ));
        
        $this->registerInPKI = $this->createObject(array(
            "keystore" => $this->userKeystore,
            "kis" => $this->pkiSignatureBase64
        ));
        
        $this->getUserData = $this->createObject(array(
            "username" => $this->username,
            "host" => $this->host
        ));
        
        $this->getUsersPresence = $this->addField($this->userPresenceGet, array("host" => $this->host));
        
        $this->getUsersPresenceMulti = $this->createObject(array(
            "hosts" => $this->createMap($this->host, $this->userPresenceGet)
        ));
        
        //===========================================================
        
        $this->getBlacklist = $this->emptyObject;
        
        $this->setBlacklistEntry = $this->createObject(array(
            "domain" => $this->notEmptyHost,
            "mode" => $this->createEnum(array(DomainFilter::MODE_ALLOW, DomainFilter::MODE_DENY))
        ));
        
        $this->suggestBlacklistEntry = $this->createObject(array(
            "domain" => $this->notEmptyHost
        ));
        
        $this->deleteBlacklistEntry = $this->createObject(array(
            "domain" => $this->notEmptyHost
        ));
        
        //===========================================================
        
        $this->createLowUser = $this->createObject(array(
            "host" => $this->host,
        ));
        $this->modifyLowUser = $this->createObject(array(
            "username" => $this->username,
            "activated" => $this->optional($this->bool),
            "identityKey" => $this->optional($this->eccPub),
            "srpSalt" => $this->optional($this->srpSalt),
            "srpVerifier" => $this->optional($this->srpVerifier),
            "loginData" => $this->optional($this->loginData),
            "email" => $this->optional($this->email),
            "privData" => $this->optional($this->privData),
            "language" => $this->optional($this->language),
            "dataVersion" => $this->optional($this->dataVersion),
            "notifier" => $this->optional($this->notificationsEntry),
            "recoveryData" => $this->optional($this->recoveryData)
        ));
        $this->deleteLowUser = $this->createObject(array(
            "username" => $this->username
        ));
        
        //===========================================================
        
        $this->getMyData = $this->emptyObject;
        
        $this->setUserPreferences = $this->createObject(array(
            "language" => $this->language,
            "notificationsEntry" => $this->notificationsEntry,
            "contactFormSid" => $this->optional($this->eccPub)
        ));
        
        $this->setUserInfo = $this->createObject(array(
            "data" => $this->userKeystore,
            "kis" => $this->pkiSignatureBase64
        ));
        
        $this->setUserPresence = $this->createObject(array(
            "presence" => $this->presence,
            "acl" => $this->presenceAcl,
            "signature" => $this->eccSignature
        ));
        
        $this->invite = $this->emptyObject;
        
        $this->setCredentials = $this->createObject(array(
            "srpSalt" => $this->srpSalt,
            "srpVerifier" => $this->srpVerifier,
            "privData" => $this->privData,
            "loginData" => $this->loginData,
            "dataVersion" => $this->dataVersion,
            "weakPassword" => $this->optional($this->bool)
        ));
        
        $this->getInitData = $this->emptyObject;
        
        $this->getPrivData = $this->emptyObject;
        
        $this->getRecoveryData = $this->emptyObject;
        
        $this->setRecoveryData = $this->createObject(array(
            "privData" => $this->privData,
            "dataVersion" => $this->dataVersion,
            "recoveryData" => $this->recoveryData
        ));
        
        $this->setPrivData = $this->createObject(array(
            "privData" => $this->privData,
            "dataVersion" => $this->dataVersion
        ));
        
        $this->setContactFormEnabled = $this->createObject(array(
            "enabled" => $this->bool
        ));
        
        //===========================================================
        
        $this->getUsers = $this->emptyObject;
        
        $this->getUser = $this->createObject(array(
            "username" => $this->tokenOrUsername
        ));
        
        $this->removeUser = $this->createObject(array(
            "username" => $this->tokenOrUsername
        ));
        
        $this->addUser = $this->createObject(array(
            "username" => $this->username,
            "pin" => $this->maybeEmpty($this->pin)
        ));
        
        $this->addUserWithToken = $this->createObject(array(
            "creator" => $this->username,
            "username" => $this->maybeEmptyString($this->username),
            "email" => $this->maybeEmptyString($this->email),
            "description" => $this->description,
            "sendActivationLink" => $this->bool,
            "notificationEnabled" => $this->bool,
            "language" => $this->language,
            "linkPattern" => $this->string
        ));
        
        $this->getConfig = $this->emptyObject;
        
        $this->getUserConfig = $this->emptyObject;
        
        $this->getConfigEx = $this->emptyObject;
        
        $this->changePin = $this->createObject(array(
            "username" => $this->username,
            "pin" => $this->maybeEmpty($this->pin)
        ));
        
        $this->changeEmail = $this->createObject(array(
            "username" => $this->tokenOrUsername,
            "email" => $this->emailEx
        ));
        
        $this->changeDescription = $this->createObject(array(
            "username" => $this->tokenOrUsername,
            "description" => $this->description
        ));
        
        $this->changeContactFormEnabled = $this->createObject(array(
            "username" => $this->username,
            "enabled" => $this->bool
        ));
        
        $this->changeSecureFormsEnabled = $this->createObject(array(
            "username" => $this->username,
            "enabled" => $this->bool
        ));
        
        $this->changeIsAdmin = $this->createObject(array(
            "username" => $this->tokenOrUsername,
            "isAdmin" => $this->bool,
            "data" => $this->adminsKeystore,
            "kis" => $this->pkiSignatureBase64
        ));
        
        $this->changeUserData = $this->createObject(array(
            "username" => $this->tokenOrUsername,
            "data" => $this->createObject(array(
                "email" => $this->optional($this->emailEx),
                "description" => $this->optional($this->description),
                "contactFormEnabled" => $this->optional($this->bool),
                "secureFormsEnabled" => $this->optional($this->bool)
            ))
        ));
        
        $this->generateInvitations = $this->createObject(array(
            "username" => $this->username,
            "count" => $this->range($this->int, 1, 100),
            "description" => $this->description,
            "linkPattern" => $this->string
        ));
        
        $this->getFullInitData = $this->createObject(array(
            "key" => $this->initDataKey
        ));
        
        $this->setInitData = $this->createObject(array(
            "key" => $this->initDataKey,
            "data" => $this->createObject(array(
                "mailsDisabled" => $this->optional($this->bool),
                "defaultLang" => $this->language,
                "langs" => $this->createMap($this->language, $this->listOf($this->oneOf(array(
                    $this->createObject(array(
                        "type" => $this->createConst("addContact"),
                        "hashmail" => $this->hashmail
                    )),
                    $this->createObject(array(
                        "type" => $this->createConst("addFile"),
                        "name" => $this->string,
                        "mimetype" => $this->optional($this->string),
                        "content" => $this->base64
                    )),
                    $this->createObject(array(
                        "type" => $this->createConst("sendMail"),
                        "subject" => $this->string,
                        "content" => $this->base64,
                        "attachments" => $this->listOf($this->createObject(array(
                            "name" => $this->string,
                            "mimetype" => $this->string,
                            "content" => $this->base64
                        )))
                    ))
                ))))
            ))
        ));
        
        $this->getNotifierConfig = $this->emptyObject;
        
        $this->setNotifierConfig = $this->createObject(array(
            "config" => $this->mailConfig
        ));
        
        $this->getInvitationMailConfig = $this->emptyObject;
        
        $this->setInvitationMailConfig = $this->createObject(array(
            "config" => $this->mailConfig
        ));
        
        $this->getLoginsPage = $this->createObject(array(
            "beg" => $this->int,
            "end" => $this->int
        ));
        
        $this->getLastLogins = $this->createObject(array(
            "count" => $this->int
        ));
        
        $this->getSmtpConfig = $this->emptyObject;
        
        $this->setSmtpConfig = $this->createObject(array(
            "type" => $this->createEnum(array("php", "smtp")),
            "smtpCfg" => $this->optional($this->createObject(array(
                "host" => $this->string,
                "port" => $this->int,
                "secure" => $this->createEnum(array("", "tls", "ssl")),
                "auth" => $this->bool,
                "username" => $this->optional($this->string),
                "password" => $this->optional($this->string)
            )))
        ));
        
        $this->getForbiddenUsernames = $this->emptyObject;
        
        //===========================================================
        
        $this->keyInit = $this->createObject(array(
            "pub" => $this->eccPub,
            "properties" => $this->loginProperties
        ));
        
        $this->keyExchange = $this->createObject(array(
            "sessionId" => $this->string,
            "nonce" => $this->nonce,
            "timestamp" => $this->timestamp,
            "signature" => $this->eccSignature,
            "K" => $this->base64
        ));
        
        //===========================================================
        
        $this->getKey = $this->emptyObject;
        
        $this->session = $this->createObject(array(
            "sessionId" => $this->string,
            "data" => $this->base64
        ));
        
        $this->key = $this->createObject(array(
            "key" => $this->eccPub,
            "data" => $this->base64
        ));
        
        //=====================PrivmxPKI================================
        
        $this->setPkiDocument = $this->createObject(array(
            "name" => $this->string,
            "data" => $this->adminsKeystore,
            "kis" => $this->pkiSignatureBase64
        ));
        
        $this->pkiOptions = $this->createObject(array(
            "domain" => $this->optional($this->string),
            "noCache" => $this->optional($this->bool)
        ));
        
        $this->getKeyStore = $this->createObject(array(
            "name" => $this->string,
            "includeAttachments" => $this->oneOf(array(
                $this->bool,
                $this->listOfWithMaxLength($this->rangeLength($this->string, 1, 20), 5)
            )),
            "options" => $this->optional($this->pkiOptions),
            "revision" => $this->optional($this->buffer)
        ));
        
        $this->getHistory = $this->createObject(array(
            "revision" => $this->optional($this->buffer),
            "seq" => $this->optional($this->int),
            "timestamp" => $this->optional($this->int)
        ));
        
        $this->signTree = $this->createObject(array(
            "domain" => $this->string,
            "hash" => $this->buffer,
            "sender" => $this->string,
            "signature" => $this->buffer
        ));
        
        $this->getTreeSignatures = $this->createObject(array(
            "domain" => $this->string,
            "hash" => $this->buffer,
            "cosigners" => $this->listOf($this->string)
        ));
        
        $this->cosignerData = $this->createObject(array(
            "state" => $this->createEnum(array("INVITED", "PENDING", "ACTIVE")),
            "uuid" => $this->string,
            "keystore" => $this->cosignerKeystore,
            "hashmail" => $this->hashmail
        ));
        
        $this->setCosigner = $this->createObject(array(
            "domain" => $this->string,
            "data" => $this->cosignerData
        ));
        
        $this->removeCosigner = $this->createObject(array(
            "domain" => $this->string,
            "uuid" => $this->string
        ));
        
        $this->getCosigners = $this->emptyObject;
        
        $this->proxy = $this->createObject(array(
            "destination" => $this->string,
            "encrypt" => $this->bool,
            "data" => $this->oneOf(array(
                $this->buffer,
                $this->createObject(array(
                    "method" => $this->string
                    //params => any[]
                ))
            ))
        ));
        
        $this->createSecureFormToken = $this->createObject(array(
            "sid" => $this->string
        ));
    }
    
    public function get($name) {
        return new Validator($this->{$name});
    }
    
    public function getWithDestination($name) {
        return new Validator($this->addField($this->{$name}, $this->destination));
    }
}
