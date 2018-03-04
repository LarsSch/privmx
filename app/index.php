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

$app = file_get_contents(__DIR__ . "/app.html");

$packPath = __DIR__ . "/../pack.json";
if (file_exists($packPath)) {
    $pack = json_decode(file_get_contents($packPath), true);
    if ($pack && is_array($pack) && (isset($pack["displayVersion"]) || isset($pack["version"]))) {
        if (isset($pack["displayVersion"]) && is_string($pack["displayVersion"])) {
            $version = $pack["displayVersion"];
        }
        else if (is_string($pack["version"])) {
            $v = explode(".", $pack["version"]);
            if (count($v) >= 3) {
                $version = implode(".", array_slice($v, 0, 3));
            }
        }
        if ($version) {
            $app = preg_replace("/PRIVFS_VERSION =(.*);/", "PRIVFS_VERSION =\"" . $version . "\";", $app);
        }
    }
}

echo $app;
