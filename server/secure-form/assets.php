<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

require_once __DIR__ . "/../vendor/autoload.php";

use io\privfs\core\Utils;

class AssetsHandler {
  
  public function __construct() {
    $ioc = new io\privfs\data\IOC(false);
    $config = $ioc->getConfig();
    $engine = $ioc->getEngine();
    
    $allowedFiles = array("privmx-client");
    $appBuildLibPath = $config->rawConfig["appBuildLibPath"];
    
    $engine->addCrossDomainHeaders();
    
    if (isset($_GET["f"]) && in_array($_GET["f"], $allowedFiles, true)) {
      $h = opendir($appBuildLibPath);
      $files = array();
      if ($h) {
        while (false !== $name = readdir($h)) {
          if (Utils::startsWith($name, $_GET["f"]) && Utils::endsWith($name, ".js")) {
            if (isset($_GET["v"])) {
              if ($name === "{$_GET["f"]}-{$_GET["v"]}.js") {
                $this->serveFile(Utils::joinPaths($appBuildLibPath, $name));
              }
            } else {
              $files[] = $name;
            }
          }
        }
        closedir($h);
        usort($files, version_compare);
        if ($name = end($files)) {
          $this->serveFile(Utils::joinPaths($appBuildLibPath, $name));
        }
      }
    }

    $this->notFound();
  }
  
  function serveFile($path) {
    if (is_file($path) && is_readable($path)) {
      header('Content-Type: application/javascript');
      readfile($path);
      exit();
    }
    $this->notFound();
  }

  function notFound() {
    header("HTTP/1.0 404 Not Found");
    exit();
  }
  
}

new AssetsHandler();
