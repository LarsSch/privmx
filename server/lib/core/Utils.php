<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

use BI\BigInteger;

class Utils {
    
    public static function getOrDefault($collection, $key, $default) {
        if(isset($collection[$key])) {
            return $collection[$key];
        }
        else {
            return $default;
        }
    }
    
    public static function startsWith($haystack, $needle) {
        return $needle === "" || strpos($haystack, $needle) === 0;
    }
    
    public static function endsWith($haystack, $needle) {
        return $needle === "" || substr($haystack, -strlen($needle)) === $needle;
    }
    
    public static function timeMicro() {
        $m = microtime();
        $a = explode(" ", $m);
        $int = $a[1] . substr($a[0], 2, 6);
        return new BigInteger($int, 10);
    }
    
    public static function timeMili() {
        return Utils::timeMicro()->div(1000);
    }
    
    public static function biTo64bit($bi) {
        $hex = str_pad($bi->toHex(), 16, "0", STR_PAD_LEFT);
        return hex2bin($hex);
    }
    
    public static function biFrom64bit($binary) {
        $hex = bin2hex($binary);
        return new BigInteger($hex, 16);
    }
    
    public static function isSequence($array) {
        if (!is_array($array)) {
            return false;
        }
        for ($k = 0, reset($array); $k === key($array); next($array)) {
            ++$k;
        }
        return is_null(key($array));
    }
    
    public static function fillTo($buff, $length) {
        if (strlen($buff) < $length) {
            $zeros = implode(array_map("chr", array_fill(0, $length - strlen($buff), 0)));
            return $zeros . $buff;
        }
        if (strlen($buff) > $length) {
            return substr($buff, 0, $length);
        }
        return $buff;
    }
    
    public static function fillTo32($buff) {
        return Utils::fillTo($buff, 32);
    }
    
    public static function hex2bin($str) {
        return self::hex2binS($str, true);
    }
    
    public static $hexChars = "0123456789ABCDEFabcdef";
    
    public static function hex2binS($str, $canBeOdd = false) {
        if (!is_string($str)) {
            return false;
        }
        $len = strlen($str);
        if ($len % 2 == 1) {
            if ($canBeOdd) {
                $str = "0" . $str;
            }
            else {
                return false;
            }
        }
        for ($i = 0; $i < $len; $i++) {
            if (strpos(self::$hexChars, $str[$i]) === false) {
                return false;
            }
        }
        return hex2bin($str);
    }
    
    public static function decStringToBin($decString) {
        return (new BigInteger($decString))->toDec();
    }
    
    public static function getBitmask($json) {
        if (!isset($json["bitmask"])) {
            return false;
        }
        $bitmask = $json["bitmask"];
        if (!is_string($bitmask) || strlen($bitmask) != 2) {
            return false;
        }
        $bitmask = hex2bin($json["bitmask"]);
        if ($bitmask === false) {
            return false;
        }
        return ord($bitmask);
    }
    
    public static function xorData($data, $bitmask) {
        $encoded = "";
        for ($i = 0; $i < strlen($data); $i++) {
            $encoded .= chr(ord($data[$i]) ^ $bitmask);
        }
        return $encoded;
    }
    
    public static function arrayValue($array, $key, $default = false) {
        return array_key_exists($key, $array) ? $array[$key] : $default;
    }
    
    public static function substr($str, $start, $length) {
        return substr($str, $start, $length);
    }
    
    public static function substring($str, $start, $end) {
        return substr($str, $start, $end - $start);
    }
    
    public static function mkdir($pathname, $mode = 0777, $recursive = false) {
        $level = error_reporting();
        error_reporting(0);
        $result = mkdir($pathname, $mode, $recursive);
        error_reporting($level);
        return $result;
    }
    
    public static function getTextFromFile($filePath) {
        $level = error_reporting();
        error_reporting(0);
        $text = file_get_contents($filePath);
        error_reporting($level);
        return $text;
    }
    
    public static function putTextToFile($filePath, $data) {
        $level = error_reporting();
        error_reporting(0);
        $result = file_put_contents($filePath, $data);
        error_reporting($level);
        return $result;
    }
    
    public static function deletFile($filePath) {
        $level = error_reporting();
        error_reporting(0);
        unlink($filePath);
        error_reporting($level);
    }
    
    public static function isGmp($var) {
        if (is_resource($var)) {
            return get_resource_type($var) == "GMP integer";
        }
        if (class_exists("GMP") && $var instanceof \GMP) {
            return true;
        }
        return false;
    }
    
    public static function varDump($var, $ident = "") {
        if (is_null($var)) {
            return "null";
        }
        if (Utils::isGmp($var)) {
            return "gmp_init(" . gmp_strval($var) . ")";
        }
        if (is_string($var)) {
            return "\"" . addslashes($var) . "\"";
        }
        if (is_numeric($var)) {
            return strval($var);
        }
        if (is_bool($var)) {
            return $var ? "true" : "false";
        }
        if (is_object($var)) {
            return "<object>";
        }
        if (Utils::isSequence($var)) {
            $newIdent = $ident . "    ";
            $first = true;
            $result = "array(";
            foreach ($var as $value) {
                $result .= ($first ? "\n" : ",\n") . $newIdent . Utils::varDump($value, $newIdent);
                $first = false;
            }
            return $result . "\n${ident})";
        }
        if (is_array($var)) {
            $newIdent = $ident . "    ";
            $first = true;
            $result = "array(";
            foreach ($var as $key => $value) {
                $result .= ($first ? "\n" : ",\n") . $newIdent ."\"${key}\" => " . Utils::varDump($value, $newIdent);
                $first = false;
            }
            return $result . "\n${ident})";
        }
        return "<unknown type>";
    }
    
    public static function dumpConfig($var, $name = "config") {
        return "<?php\nglobal \$_PRIVMX_GLOBALS;\n\$_PRIVMX_GLOBALS[\"$name\"] = " . Utils::varDump($var) . ";";
    }
    
    public static function saveConfig($var) {
        $configContent = Utils::dumpConfig($var);
        $files = get_included_files();
        foreach ($files as $file) {
            if (Utils::endsWith($file, "config.php")) {
                return Utils::putTextToFile($file, $configContent);
            }
        }
        return false;
    }
    
    public static function dumpPhpFile($var) {
        return "<?php\nreturn " . Utils::varDump($var) . ";";
    }
    
    public static function parseAcceptLanguageHeader($header) {
        $result = array();
        $entries = explode(",", $header);
        foreach ($entries as $entry) {
            $splittedEntry = explode(";", $entry);
            $tag = $splittedEntry[0];
            $q = 1.0;
            if (count($splittedEntry) > 1 && strpos($splittedEntry[1], "q=") === 0) {
                $q = floatval(substr($splittedEntry[1], 2));
            }
            $splittedTag = explode("-", $tag);
            $lang = $splittedTag[0];
            $country = count($splittedTag) > 1 ? $splittedTag[1] : "";
            array_push($result, array(
                "tag" => $tag,
                "lang" => $lang,
                "country" => $country,
                "q" => $q
            ));
        }
        usort($result, function($a, $b) {
            $cmp = $b["q"] - $a["q"];
            return $cmp == 0 ? 0 : ($cmp < 0 ? -1 : 1);
        });
        return $result;
    }
    
    public static function resolveLang($availableLangs, $defaultLang, $checkRequest = false) {
        if ($checkRequest && isset($_REQUEST["lang"])) {
            foreach ($availableLangs as $lang) {
                if($_REQUEST["lang"] == $lang) {
                    return $lang;
                }
            }
        }
        $entries = isset($_SERVER["HTTP_ACCEPT_LANGUAGE"]) ? Utils::parseAcceptLanguageHeader($_SERVER["HTTP_ACCEPT_LANGUAGE"]) : array();
        foreach ($entries as $entry) {
            foreach ($availableLangs as $lang) {
                if ($entry["lang"] == $lang) {
                    return $lang;
                }
            }
        }
        return $defaultLang;
    }
    
    public static function parseUri($uri = null) {
        $uri = is_null($uri) ? $_SERVER["REQUEST_URI"] : $uri;
        $index = strpos($uri, "?");
        if ($index === false) {
            return array(
                "path" => $uri,
                "search" => ""
            );
        }
        return array(
            "path" => substr($uri, 0, $index),
            "search" => substr($uri, $index)
        );
    }
    
    public static function normalizeUrl($url, $slashAtEnd = false) {
        $url = strtolower($url);
        $index = strpos($url, "#");
        if ($index !== false) {
            $url = substr($url, 0, $index);
        }
        $index = strpos($url, "?");
        if ($index !== false) {
            $url = substr($url, 0, $index);
        }
        if (Utils::endsWith($url, "/")) {
            if (!$slashAtEnd) {
                $url = substr($url, 0, strlen($url) - 1);
            }
        }
        else {
            if ($slashAtEnd) {
                $url = $url . "/";
            }
        }
        return $url;
    }
    
    public static function compareUrl($a, $b) {
        return Utils::normalizeUrl($a) == Utils::normalizeUrl($b);
    }
    
    public static function getDirectorySize($path) {
        $result = 0;
        $files = scandir($path);
        $cleanPath = rtrim($path, '/'). '/';
        
        foreach ($files as $f) {
            if ($f != "." && $f != "..") {
                $currentFile = $cleanPath . $f;
                if (is_dir($currentFile)) {
                    $result += Utils::getDirectorySize($currentFile);
                }
                else {
                    $result += filesize($currentFile);
                }
            }
        }
        return $result;
    }
    
    public static function concatUrl($a, $b) {
        if (Utils::endsWith($a, "/")) {
            $a = substr($a, 0, strlen($a) - 1);
        }
        if (Utils::startsWith($b, "/")) {
            $b = substr($b, 1);
        }
        return $a . "/" . $b;
    }
    
    public static function joinPaths($base, $path) {
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
    
    public static function getClientIp() {
        $names = array(
          "HTTP_CLIENT_IP", "HTTP_X_FORWARDED_FOR",
          "HTTP_X_FORWARDED", "HTTP_FORWARDED_FOR",
          "HTTP_FORWARDED", "REMOTE_ADDR"
        );
        foreach ($names as $name) {
          if (! empty($_SERVER[$name])) {
            return $_SERVER[$name];
          }
        }
        return "";
    }
    
    public static function schemelessUrl($url) {
        if (Utils::startsWith($url, "http:")) {
            return substr($url, 5);
        }
        if (Utils::startsWith($url, "http:")) {
            return substr($url, 6);
        }
        return $url;
    }
    
    public static function isValidEmail($email) {
        $parts = explode("@", $email);
        return count($parts) == 2 && strlen($parts[0]) > 0 && strlen($parts[1]) > 0;
    }
    
    public static function propIs($obj, $name) {
        return isset($obj[$name]) && !!$obj[$name];
    }
}
