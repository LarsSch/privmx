<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

use BI\BigInteger;

class ValidatorException extends \Exception {

    private $value;
    private $spec;
    private $couse;
    
    public function __construct($value, $spec, $message, $couse) {
        parent::__construct($message, null, $couse);
        $this->value = $value;
        $this->spec = $spec;
        $this->couse = $couse;
    }
    
    public static function create($value, $spec, $couse) {
        return new ValidatorException($value, $spec, $couse->getMessage(), $couse);
    }
    
    public static function createProxy($message, $couse) {
        return new ValidatorException(null, null, $message . " -> " . $couse->getMessage(), $couse);
    }
    
    public function getRpcErrorName() {
        if (!is_null($this->spec) && isset($this->spec["errorName"])) {
            return $this->spec["errorName"];
        }
        if ($this->couse instanceof ValidatorException) {
            return $this->couse->getRpcErrorName();
        }
        return "INVALID_JSON_PARAMETERS";
    }
    
    public static function getRpcErrorNameFromException($e) {
        if ($e instanceof ValidatorException) {
            return $e->getRpcErrorName();
        }
        return "INVALID_JSON_PARAMETERS";
    }
}

class Validator {
    
    public $spec;
    private $logger;
    
    public function __construct($spec) {
        $this->spec = $spec;
        $this->logger = \io\privfs\log\LoggerFactory::get($this);
    }
    
    public function validateRaw($value) {
        return self::validateValue($value, $this->spec);
    }
    
    public function validate($value) {
        try {
            return $this->validateRaw($value);
        }
        catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            $this->logger->notice($e->getTraceAsString());
            $ex = $e->getPrevious();
            while (!is_null($ex)) {
                $this->logger->notice("Coused: " . $ex->getMessage());
                if (is_null($ex->getPrevious())) {
                    $this->logger->notice($ex->getTraceAsString());
                }
                $ex = $ex->getPrevious();
            }
            $errorName = ValidatorException::getRpcErrorNameFromException($e);
            throw new JsonRpcException($errorName, $e->getMessage());
        }
    }
    public static function validateStringLength($str, $spec) {
        if (isset($spec["minLength"]) || isset($spec["maxLength"]) || isset($spec["length"])) {
            static::validateLength(mb_strlen($str, "utf8"), $spec);
        }
    }
    
    public static function validateLength($length, $spec) {
        if (isset($spec["minLength"]) && $length < $spec["minLength"]) {
            throw new \Exception("Invalid length! Expected min " . $spec["minLength"] . ", get " . $length);
        }
        if (isset($spec["maxLength"]) && $length > $spec["maxLength"]) {
            throw new \Exception("Invalid length! Expected max " . $spec["maxLength"] . ", get " . $length);
        }
        if (isset($spec["length"]) && $length != $spec["length"]) {
            throw new \Exception("Invalid length! Expected exactly " . $spec["length"] . ", get " . $length);
        }
        return $length;
    }
    
    public static function validateBinLength($length, $spec) {
        if (isset($spec["minBinLength"]) && $length < $spec["minBinLength"]) {
            throw new \Exception("Invalid bin length! Expected min " . $spec["minBinLength"]);
        }
        if (isset($spec["maxBinLength"]) && $length > $spec["maxBinLength"]) {
            throw new \Exception("Invalid bin length! Expected max " . $spec["maxBinLength"]);
        }
        if (isset($spec["binLength"]) && $length != $spec["binLength"]) {
            throw new \Exception("Invalid bin length! Expected exactly " . $spec["binLength"]);
        }
        return $length;
    }
    
    public static function validateNumberRange($number, $spec) {
        if (isset($spec["min"]) && $number < $spec["min"]) {
            throw new \Exception("Invalid range! Expected higher than " . $spec["min"]);
        }
        if (isset($spec["max"]) && $number > $spec["max"]) {
            throw new \Exception("Invalid range! Expected lower than " . $spec["max"]);
        }
        return $number;
    }
    
    public static function validateString($value, $spec) {
        if (isset($spec["regex"]) && !preg_match($spec["regex"], $value)) {
            throw new \Exception("Does not match to regex!");
        }
        if (isset($spec["lowercase"]) && (mb_strtolower($value, "UTF-8") == $value) != $spec["lowercase"]) {
            throw new \Exception($spec["lowercase"] ? "Is not lowercase" : "Is lowercase");
        }
        if (isset($spec["uppercase"]) && (mb_strtoupper($value, "UTF-8") == $value) != $spec["uppercase"]) {
            throw new \Exception($spec["uppercase"] ? "Is not uppercase" : "Is uppercase");
        }
        if (isset($spec["alpha"]) && ctype_alpha($value) != $spec["alpha"]) {
            throw new \Exception($spec["alpha"] ? "Is not alphabetic" : "Is alphabetic");
        }
        if (isset($spec["numeric"]) && ctype_digit($value) != $spec["numeric"]) {
            throw new \Exception($spec["numeric"] ? "Is not numeric" : "Is numeric");
        }
        if (isset($spec["alphanumeric"]) && ctype_alnum($value) != $spec["alphanumeric"]) {
            throw new \Exception($spec["alphanumeric"] ? "Is not alphanumeric" : "Is alphanumeric");
        }
        if (isset($spec["empty"]) && (strlen(trim($value)) == 0) != $spec["empty"]) {
            throw new \Exception($spec["empty"] ? "Is not empty" : "Is empty");
        }
        if (isset($spec["not"])) {
            foreach ($spec["not"] as $a) {
                if ($value == $a) {
                    throw new \Exception("Cannot be '{$value}'");
                }
            }
        }
    }
    
    public static function validateValue($value, $spec) {
        try {
            return self::validateValueMain($value, $spec);
        }
        catch (\Exception $e) {
            throw ValidatorException::create($value, $spec, $e);
        }
    }
    
    public static function validateValueMain($value, $spec) {
        if( ($value instanceof stdClass) || (is_object($value) && !($value instanceof \PSON\ByteBuffer)) )
            $value = (array)$value;
        if ($spec["type"] == "string" || $spec["type"] == "hex" || $spec["type"] == "base64" ||
            $spec["type"] == "base58" || $spec["type"] == "eccpub" || $spec["type"] == "eccpriv" ||
            $spec["type"] == "eccaddr" || $spec["type"] == "hashmail" || $spec["type"] == "email" ||
            $spec["type"] == "bi16" || $spec["type"] == "bi10" || $spec["type"] == "pki.keystore.base64" ||
            $spec["type"] == "pki.signature.base64" || $spec["type"] == "pki.document.base64" || $spec["type"] == "image") {
            if (!is_string($value)) {
                throw new \Exception("Expected string " . $spec["type"]);
            }
            static::validateStringLength($value, $spec);
            static::validateString($value, $spec);
            if ($spec["type"] == "image") {
                static::validateBinLength(strlen($value), $spec);
                if (!ImageTypeDetector::isValid($value)) {
                    throw new \Exception("Expected image");
                }
                return $value;
            }
            if ($spec["type"] == "hex") {
                $bin = hex2bin($value);
                if ($bin === false) {
                    throw new \Exception("Expected hex");
                }
                static::validateBinLength(strlen($bin), $spec);
                return array("__orgProp" => "hex", "bin" => $bin, "hex" => $value);
            }
            if ($spec["type"] == "bi16") {
                $bi = BigInteger::createSafe($value, 16);
                if ($bi === false) {
                    throw new \Exception("Expected BigInteger from hex");
                }
                return array("__orgProp" => "hex", "bi" => $bi, "hex" => $value);
            }
            if ($spec["type"] == "bi10") {
                $bi = BigInteger::createSafe($value);
                if ($bi === false) {
                    throw new \Exception("Expected BigInteger from decimal");
                }
                return array("__orgProp" => "dec", "bi" => $bi, "dec" => $value);
            }
            if ($spec["type"] == "base64") {
                $bin = base64_decode($value);
                if ($bin === false) {
                    throw new \Exception("Expected base64");
                }
                static::validateBinLength(strlen($bin), $spec);
                return array("__orgProp" => "base64", "bin" => $bin, "base64" => $value);
            }
            if ($spec["type"] == "base58") {
                $bin = Base58::decode($value);
                if ($bin === false) {
                    throw new \Exception("Expected base58");
                }
                static::validateBinLength(strlen($bin), $spec);
                return array("__orgProp" => "base58", "bin" => $bin, "base58" => $value);
            }
            if ($spec["type"] == "eccpub") {
                $ecc = ECUtils::publicFromBase58DER($value);
                if ($ecc === false) {
                    throw new \Exception("Expected Ecc public key");
                }
                return array("__orgProp" => "base58", "ecc" => $ecc, "base58" => $value);
            }
            if ($spec["type"] == "eccpriv") {
                $ecc = ECUtils::fromWIF($value);
                if ($ecc === false) {
                    throw new \Exception("Expected Ecc private key");
                }
                return array("__orgProp" => "wif", "ecc" => $ecc, "wif" => $value);
            }
            if ($spec["type"] == "eccaddr") {
                if (strlen($value) < 26 || strlen($value) > 35 || !ECUtils::validateAddress($value, "00")) {
                    throw new \Exception("Expected Ecc address");
                }
            }
            if ($spec["type"] == "pki.keystore.base64") {
                $bin = base64_decode($value);
                if ($bin === false) {
                    throw new \Exception("Expected pki.keystore.base64");
                }
                try {
                    $keystore = \privmx\pki\keystore\KeyStore::decode($bin);
                }
                catch (\Exception $e) {
                    throw new \Exception("Expected keystore", null, $e);
                }
                if (is_null($keystore->getPrimaryKey())) {
                    throw new \Exception("Expected pki.keystore.base64");
                }
                if ($keystore->hasAnyPrivate()) {
                    throw new \Exception("Keystore has private keys");
                }
                $attachments = isset($spec["attachments"]) ? static::validatePkiDataAttachments($keystore, $spec["attachments"]) : null;
                return array("__orgProp" => "base64", "keystore" => $keystore, "bin" => $bin, "base64" => $value, "attachments" => $attachments);
            }
            if ($spec["type"] == "pki.document.base64") {
                $bin = base64_decode($value);
                if ($bin === false) {
                    throw new \Exception("Expected pki.data.base64");
                }
                try {
                    $data = \privmx\pki\keystore\DocumentsPacket::decode($bin);
                }
                catch (\Exception $e) {
                    throw new \Exception("Expected DocumentsPacket", null, $e);
                }
                if (count($data->keys) == 0) {
                    throw new \Exception("DocumentsPacket need at least one key");
                }
                $attachments = isset($spec["attachments"]) ? static::validatePkiDataAttachments($data, $spec["attachments"]) : null;
                return array("__orgProp" => "base64", "data" => $data, "bin" => $bin, "base64" => $value, "attachments" => $attachments);
            }
            if ($spec["type"] == "pki.signature.base64") {
                $bin = base64_decode($value);
                if ($bin === false) {
                    throw new \Exception("Expected pki.signature.base64");
                }
                try {
                    $signature = \privmx\pki\keystore\Signature::decode($value);
                    return array("__orgProp" => "base64", "signature" => $signature[0], "additional" => $signature[1], "bin" => $bin, "base64" => $value);
                }
                catch (\Exception $e) {
                    throw new \Exception("Expected signature", null, $e);
                }
            }
            if ($spec["type"] == "hashmail") {
                $parts = explode("#", $value);
                if (count($parts) != 2 || strlen($parts[0]) == 0 || strlen($parts[1]) == 0) {
                    throw new \Exception("Expected hashmail");
                }
            }
            if ($spec["type"] == "email") {
                if (!Utils::isValidEmail($value)) {
                    throw new \Exception("Expected email");
                }
            }
        }
        else if ($spec["type"] == "bool") {
            if (!is_bool($value)) {
                throw new \Exception("Expected bool");
            }
        }
        else if ($spec["type"] == "int") {
            if (!is_int($value)) {
                throw new \Exception("Expected integer");
            }
            static::validateNumberRange($value, $spec);
        }
        else if ($spec["type"] == "float") {
            if (!is_float($value)) {
                throw new \Exception("Expected float");
            }
            static::validateNumberRange($value, $spec);
        }
        else if ($spec["type"] == "array") {
            if (!Utils::isSequence($value)) {
                throw new \Exception("Expected array");
            }
            static::validateLength(count($value), $spec);
            return static::validateArray($value, $spec["spec"]);
        }
        else if ($spec["type"] == "object") {
            if (!is_array($value)) {
                throw new \Exception("Expected object");
            }
            $res = static::validateObject($value, $spec["spec"]);
            return isset($spec["noConvertToObjectOnEmpty"]) ? $res : (empty($res) ? (object)array() : $res);
        }
        else if ($spec["type"] == "enum") {
            if (!in_array($value, $spec["values"])) {
                throw new \Exception("Expected value to be one of enums");
            }
        }
        else if ($spec["type"] == "const") {
            if ($value !== $spec["value"]) {
                throw new \Exception("Expected value to be exactly " . $spec["value"]);
            }
        }
        else if ($spec["type"] == "oneOf") {
            return static::validateOneOf($value, $spec["spec"]);
        }
        else if ($spec["type"] == "map") {
            if (!is_array($value)) {
                throw new \Exception("Expected object");
            }
            static::validateLength(count(array_keys($value)), $spec);
            return static::validateMap($value, $spec["keySpec"], $spec["valSpec"]);
        }
        else if ($spec["type"] == "buffer" ) {
            if( !($value instanceof \PSON\ByteBuffer) )
                throw new \Exception("Expected buffer");
            static::validateLength($value->capacity(), $spec);
            return $value->toBinary();
        }
        else {
            throw new \Exception("Invalid specification type " . $spec["type"]);
        }
        return $value;
    }
    
    public static function validateArray($array, $spec) {
        $res = array();
        foreach ($array as $i => $v) {
            try {
                array_push($res, static::validateValue($v, $spec));
            }
            catch (\Exception $e) {
                throw ValidatorException::createProxy($i, $e);
            }
        }
        return $res;
    }
    
    public static function validateObject($object, $spec) {
        foreach ($spec as $specKey => $valSpec) {
            if (!isset($object[$specKey]) && (!isset($valSpec["required"]) || $valSpec["required"] === true)) {
                throw new \Exception("Key " . $specKey . " is required");
            }
        }
        $res = array();
        foreach ($object as $key => $value) {
            $valSpec = null;
            foreach ($spec as $specKey => $s) {
                if ($specKey == $key) {
                    $valSpec = $s;
                    break;
                }
            }
            if (is_null($valSpec)) {
                throw new \Exception("Unexpected key " . $key);
            }
            try {
                $res[$key] = static::validateValue($value, $valSpec);
            }
            catch (\Exception $e) {
                throw ValidatorException::createProxy($key, $e);
            }
        }
        return $res;
    }
    
    public static function validateOneOf($value, $spec) {
        foreach ($spec as $specOneOf) {
            try {
                return static::validateValue($value, $specOneOf);
            }
            catch (\Exception $e) {
                //Do nothing just go to next spec
            }
        }
        throw new \Exception("Value doesn't match any spec");
    }
    
    public static function validateMap($map, $keySpec, $valSpec) {
        $res = array();
        foreach ($map as $key => $value) {
            try {
                static::validateValue($key, $keySpec);
            }
            catch (\Exception $e) {
                throw ValidatorException::createProxy($key . "(key)", $e);
            }
            try {
                $res[$key] = static::validateValue($value, $valSpec);
            }
            catch (\Exception $e) {
                throw ValidatorException::createProxy($key . "(value)", $e);
            }
        }
        return empty($res) ? (object)array() : $res;
    }
    
    public static function validatePkiDataAttachments($keystore, $spec) {
        if (!\privmx\pki\keystore\AttachmentsStorage::isValidToSave($keystore)) {
            throw new \Exception("Invalid keystore attachments");
        }
        foreach ($keystore->attachmentPointerList as $pointer) {
            if (!isset($spec[$pointer->fileName])) {
                throw new \Exception("Unexpected attachment " . $pointer->fileName);
            }
        }
        $result = array();
        foreach ($spec as $key => $aSpec) {
            $aData = $keystore->getAttachment($key);
            if (is_null($aData)) {
                if (!isset($aSpec["required"]) || $aSpec["required"] === true) {
                    throw new \Exception("Missing attachment '" . $key . "'");
                }
            }
            else {
                if ($aSpec["type"] == "binary") {
                    try {
                        $result[$key] = static::validateValue($aData, $aSpec["spec"]);
                    }
                    catch (\Exception $e) {
                        throw ValidatorException::createProxy($key, $e);
                    }
                }
                else if ($aSpec["type"] == "json") {
                    try {
                        $json = json_decode($aData);
                        if ($json === false) {
                            throw new \Exception("Expected json");
                        }
                        $result[$key] = static::validateValue($json, $aSpec["spec"]);
                    }
                    catch (\Exception $e) {
                        throw ValidatorException::createProxy($key, $e);
                    }
                }
                else {
                    throw new \Exception("Invalid specification type " . $aSpec["type"]);
                }
            }
        }
        return $result;
    }
    
    public static function clean($data) {
        if (is_array($data)) {
            if (isset($data["__orgProp"])) {
                return $data[$data["__orgProp"]];
            }
            $res = array();
            foreach ($data as $key => $value) {
                $res[$key] = static::clean($value);
            }
            return $res;
        }
        return $data;
    }
}
