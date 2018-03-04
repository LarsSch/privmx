<?php
/*

This file is distributed under the PrivMX Web Freeware License
See https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.

-----

This is a "web installer" for PrivMX WebMail software.
https://privmx.com

Place this file in the root directory of your website and
open it using your web browser.

This web installer:
* checks PHP version and modules available on your web server;
* creates /privmx directory;
* downloads and unzips the latest PrivMX WebMail package in this directory;
* runs the installer script.

*/

$minPhpVersion = "5.4.0";
$modules = array("curl", "openssl", "mcrypt", "gmp", "json", "ctype", "mbstring", "zip");

$assetsLocation = "https://privmx.com/web-installer/";
$downloadUrl = "https://privmx.com/getlatest";
$dir = __DIR__;
$zipPath = joinPaths($dir, "/privmx/privmx.zip");
$extractPath = $dir;
$privmxPath = joinPaths($dir, "/privmx");
$mainInstaller = getMainInstallerUrl();

function asset($path) {
  global $assetsLocation;
  return $assetsLocation . $path;
}

function joinPaths($base, $path) {
  return rtrim($base, "/") . "/" . ltrim($path, "/");
}

function getMainInstallerUrl() {
  $s = explode("/", $_SERVER["REQUEST_URI"]);
  $s = array_slice($s, 0, count($s) - 1);
  array_push($s, "privmx/install/");
  return implode("/", $s);
}

function sendRequest($url) {
  $ch = curl_init();

  curl_setopt($ch, CURLOPT_AUTOREFERER, true);
  curl_setopt($ch, CURLOPT_HEADER, 0);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_USERAGENT, "PrivMX-Web-Installer");

  $data = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error = curl_error($ch);
  if (!$error && $code != 200) {
    $error = "Invalid status code " . $code;
  }
  curl_close($ch);

  return array("data" => $data, "code" => $code, "error" => $error);
}

function testPrivmxRequirements() {
  global $minPhpVersion;
  global $modules;
  return testRequirements($minPhpVersion, $modules);
}

function testRequirements($phpVersion, $modules) {
  $validPhp = version_compare(PHP_VERSION, $phpVersion) >= 0;
  $php = array(
    "type" => "php",
    "valid" => $validPhp,
    "minVersion" => $phpVersion,
    "currentVersion" => PHP_VERSION,
    "msg" => $validPhp ? "" : "You need at least PHP " . $phpVersion . ", but you have PHP " . PHP_VERSION
  );
  $modulesValid = true;
  $modulesChecks = array();
  $invalidModules = array();
  foreach ($modules as $module) {
    $loaded = extension_loaded($module);
    $msg = $loaded ? "" : "missing";
    if (!$loaded) {
      if ($module == "gmp") {
        $module = "bcmath";
        $loaded = extension_loaded($module);
        $msg = $loaded ? "" : "missing";
        if (!$loaded) {
          array_push($invalidModules, "gmp");
          array_push($invalidModules, "bcmath");
          $module = "gmp or bcmath";
        }
      }
      else {
        array_push($invalidModules, $module);
      }
    }
    $modulesValid = $modulesValid && $loaded;
    array_push($modulesChecks, array(
      "type" => "module",
      "module" => $module,
      "valid" => $loaded,
      "msg" => $msg
    ));
  }
  $query = implode(",", $invalidModules);
  foreach ($modulesChecks as &$entry) {
    if (!$entry["valid"] && $entry["msg"] == "missing") {
      $entry["msg"] = "Module not found. <a target='_blank' href='https://privmx.com/faqnomodule?modules=" . $query . "'>Read more in new tab.</a>";
    }
  }
  return array(
    "valid" => $validPhp && $modulesValid,
    "php" => $php,
    "modules" => $modulesChecks,
    "modulesValid" => $modulesValid
  );
}

function checkWritableDirectory($path) {
  $valid = true;
  $msg = "";
  if (!file_exists($path)) {
    $valid = false;
    $msg = "Directory " . $path . " does not exist";
  }
  else if (!is_dir($path)) {
    $valid = false;
    $msg = "No directory under " . $path;
  }
  else if (!is_writable($path)) {
    $valid = false;
    $msg = "Directory " . $path . " is not writable, grant write access and try again.";
  }
  return array(
    "type" => "directory",
    "path" => $path,
    "valid" => $valid,
    "msg" => $msg
  );
}

function checkWritablePath($path) {
  $exists = file_exists($path);
  return array(
    "type" => "writable",
    "path" => $path,
    "valid" => !$exists,
    "msg" => $exists ? (is_file($path) ? "File" : "Directory") . " " . $path . " already exists, remove it and try again." : ""
  );
}

function createDir($path) {
  $created = mkdir($path, 0777);
  return array(
    "type" => "mkdir",
    "path" => $path,
    "valid" => $created,
    "msg" => $created ? "" : "Cannot create directory under path " . $path
  );
}

function jsonResponse($data) {
  header("Content-Type: application/json");
  echo(json_encode($data));
}

function jsonSuccessResponse($msg) {
  jsonResponse(array("result" => $msg));
}

function jsonErrorResponse($msg) {
  jsonResponse(array("error" => $msg));
}

$step = isset($_GET["step"]) ? intval($_GET["step"]) : 0;
$step = $step >= 0 && $step <= 2 ? $step : 0;
if ($step == 1) {
    $step1 = testPrivmxRequirements();
}
else if ($step == 2) {
  if (!testPrivmxRequirements()["valid"])  {
    jsonErrorResponse("System check fails. Please rerun web installer.");
  }
  else {
    $step2 = array();
    $step2["rootDir"] = checkWritableDirectory($dir);
    $step2["privmxDir"] = checkWritablePath($privmxPath);
    $step2["createDir"] = createDir($privmxPath);
    $step2["privmxZip"] = checkWritablePath($zipPath);
    if (!$step2["rootDir"]["valid"] || !$step2["privmxDir"]["valid"] || !$step2["privmxZip"]["valid"] || !$step2["createDir"]["valid"]) {
      jsonErrorResponse("Directory " . $privmxPath . " cannot be created. Please check access rights or remove the directory if it already exists.");
    }
    else {
      $zip = sendRequest($downloadUrl);
      if ($zip["error"]) {
        jsonErrorResponse("Cannot download pack from " . $downloadUrl . "<br/>" . $zip["error"]);
      }
      else if (file_put_contents($zipPath, $zip["data"]) === false) {
        jsonErrorResponse("Cannot save downloaded pack to " . $zipPath);
      }
      else {
        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
          jsonErrorResponse("Cannot open pack " . $zipPath);
        }
        else if (!$zip->extractTo($extractPath)) {
          jsonErrorResponse("Cannot extract pack " . $zipPath . " to<br/>" . $extractPath);
        }
        else if (!$zip->close()) {
          jsonErrorResponse("Cannot close pack " . $zipPath);
        }
        else {
          unlink(__FILE__);
          jsonSuccessResponse($mainInstaller);
        }
        unlink($zipPath);
      }
    }
  }
  die();
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <title>PrivMX WebMail Installer</title>
    <link rel="shortcut icon" href="<?php echo(asset("favicon.ico")); ?>" />
    <link rel="stylesheet" type="text/css" href="<?php echo(asset("main.css")); ?>" />
  </head>
  <body>
    <div class="container">
      <div class="logo-col">
        <img src="<?php echo(asset("logo.png")); ?>" alt="" />
      </div>
      <div class="section-col">
        <div id="start-section" class="section hide-after-install">
          <?php if ($step == 0) { ?>
            <div class="title">
             Web Installer
            </div>
            <div class="info">
              This script will check PHP version and modules and then it will download and install PrivMX WebMail
            </div>
            <div class="button-container">
              <a class="button" href="?step=1">[1/2] Check requirements (PHP version and modules)</a>
            </div>
          <?php } else if ($step == 1) { ?>
            <div class="title">
             Web Installer - system check
            </div>
            <div class="tests">
              <div class="header">PHP version</div>
              <div class="row <?php echo($step1["php"]["valid"] ? "success" : "fail"); ?>">
                <div class="name">
                  at least <?php echo($step1["php"]["minVersion"]); ?>
                </div>
                <div class="result">
                  <?php echo($step1["php"]["valid"] ? "OK" : ""); ?>
                  <?php echo($step1["php"]["msg"]); ?>
                </div>
              </div>
              <?php if ($step1["php"]["valid"]) { ?>
                <div class="header">Modules</div>
                <?php foreach ($step1["modules"] as $module) {?>
                  <div class="row <?php echo($module["valid"] ? "success" : "fail"); ?>">
                    <div class="name">
                      <?php echo($module["module"]); ?>
                    </div>
                    <div class="result">
                      <?php echo($module["valid"] ? "OK" : ""); ?>
                      <?php echo($module["msg"]); ?>
                    </div>
                  </div>
                <?php } ?>
              <?php } ?>
            </div>
            <?php if ($step1["valid"]) { ?>
              <div class="info main-info text-success">
                Everything seems OK
              </div>
              <div class="info">
                Installation directory:<br />
                <code><?php echo($privmxPath) ?></code><br />
                This directory will be created and used for downloading and unzipping PrivMX WebMail.
              </div>
              <div id="the-error" class="error-container hide">
              </div>
              <div class="button-container">
                <button id="btn-download-and-install" class="button" data-url="?step=2">
                  <i class="icon-spinner animate-spin"></i>
                  <span data-alt="[2/2] Launching installer script">[2/2] Download and run installer script</span>
                </button>
              </div>
            <?php } else { ?>
              <div class="info main-info">
                Unfortunately your system does not meet PrivMX WebMail requirements.
              </div>
              <div class="button-container">
                <a class="button" href="">Check again</a>
              </div>
            <?php } ?>
          <?php } ?>
        </div>
      </div>
    </div>
    <script type="text/javascript" src="<?php echo(asset("jquery.min.js")); ?>"></script>
    <script type="text/javascript" src="<?php echo(asset("main.js")); ?>"></script>
  </body>
</html>
