<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

$protocol = "http";
if (!empty($_SERVER['HTTPS']) && $_SERVER["HTTPS"] != "off")
    $protocol = "https";
else if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']))
    $protocol = $_SERVER['HTTP_X_FORWARDED_PROTO'];

$config_path = __DIR__ . "/../server/config.php";
if (file_exists($config_path)) {
    if( $protocol === "http" )
    {
        require_once $config_path;

        global $_PRIVMX_GLOBALS;
        if( !empty($_PRIVMX_GLOBALS["config"]["forceHTTPS"]) )
        {
            $url = "https://" . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"];
            header("Location: $url");
            die("");
        }
    }
}
else {
    header("Location: ../install/");
}

require_once(__DIR__."/../server/vendor/autoload.php");
$ioc = new \io\privfs\data\IOC();
$engine = $ioc->getEngine();
$userInfo = array(
    "user" => null,
    "username" => "unknown",
    "hashmail" => "unknown#unknown",
    "keystore" => null,
    "imgBuf" => null,
    "imgUrl" => null,
    "infoBuf" => null,
    "info" => null,
    "displayName" => null
);
if (isset($_REQUEST["token"])) {
    $ioc = new \io\privfs\data\IOC();
    $res = $ioc->getMessage()->verifyMessageToken($_REQUEST["token"]);
    if ($res !== false) {
        $token = $res;
        $res = true;
        try {
            $sink = $ioc->getSink()->sinkGet($token["sid"]);
            if ($sink == null) {
                throw new \Exception("There is no sink");
            }
            $userInfo = $ioc->getUser()->getUserKeystoreInfo($sink["owner"]);
        }
        catch (\Exception $e) {
            error_log($e);
        }
    }
}
else {
    $res = false;
}

$languageDetector = new \io\privfs\core\LanguageDetector("en", array("en", "pl"));
$lang = $languageDetector->detect();
function i18n($id) {
    global $lang, $i18nData;
    return isset($i18nData[$lang][$id]) ? $i18nData[$lang][$id] : $id;
}

require(__DIR__ . "/../app/validate.php");