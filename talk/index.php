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

if (count($_GET) < 1) {
    $engine->rawResponse("400 Bad request", null, 400);
}
if (isset($_GET["id"])) {
    $username = $_GET["id"];
}
else {
    reset($_GET);
    $username = key($_GET);
}

$userService = $ioc->getUser();
$user = $userService->getLowUser($username);
if (is_null($user)) {
    $engine->rawResponse("404 Not found", null, 404);
}
$loginData = json_decode($user["loginData"], true);
$hint = $loginData && isset($loginData["hint"]) ? $loginData["hint"] : "";

$languageDetector = new \io\privfs\core\LanguageDetector("en", array("en", "pl"));
$lang = $languageDetector->detect();
function i18n($id) {
    global $lang, $i18nData;
    return isset($i18nData[$lang][$id]) ? $i18nData[$lang][$id] : $id;
}
$hashmail = $username . "#" . $user["hosts"][0];

$userInfo = $userService->getUserKeystoreInfo($user["owner"]);

require(__DIR__ . "/../app/talk.php");
