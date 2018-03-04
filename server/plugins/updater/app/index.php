<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

require_once("./Config.php");
require_once("./UpdateService.php");

function rawResponse($data = null, $code = null, $contentType = null) {
    if ($code) {
        http_response_code($code);
    }
    if ($contentType) {
        header("Content-Type: " . $contentType);
    }
    if ($data) {
        print($data);
    }
    die();
}

function codeResponse($code, $stdMsg, $msg = null, $contentType = null) {
    rawResponse("<!DOCTYPE html><html><body><h1>" . $code . " " . $stdMsg . "</h1><hr /><p>" . ($msg ? $msg : "") . "</p></body></html>", $code, $contentType);
}

function response400($msg = null, $contentType = null) {
    codeResponse(400, "Bad Request", $msg, $contentType);
}

function response403($msg = null, $contentType = null) {
    codeResponse(403, "Forbidden", $msg, $contentType);
}

function response404($msg = null, $contentType = null) {
    codeResponse(404, "Not found", $msg, $contentType);
}

function response500($msg = null, $contentType = null) {
    codeResponse(500, "Internal server error", $msg, $contentType);
}

function rawJsonResponse($data, $code = null, $contentType = null) {
    rawResponse($data, $code, $contentType ? $contentType : "application/json");
}

function jsonResponse($data, $code = null, $contentType = null) {
    rawJsonResponse(json_encode($data), $code, $contentType);
}

$updatePath = "../";
$dataPath = $updatePath . "data.php";
$stepsPath = $updatePath . "steps.json";
$completedPath = $updatePath . "completed.txt";

if (!file_exists($dataPath)) {
    response500("No database");
}
if (!isset($_REQUEST["token"])) {
    response403("No token");
}
$data = require($dataPath);
if ($_REQUEST["token"] != $data["token"]) {
    response403("Invalid token");
}
$completed = file_exists($completedPath);
if (isset($_REQUEST["method"])) {
    if ($_REQUEST["method"] == "startUpdate") {
        /*jsonResponse(array(
            "updateID" => "update-id",
            "status" => array(
                "steps" => array(
                    array(
                        "name" => "init",
                        "status" => "COMPLETED"
                    ),
                    array(
                        "name" => "download-zip"
                    )
                )
            )
        ));*/
        $config = new Config($data);
        $service = new \io\privfs\plugin\updater\UpdateService($config);
        $response = $service->start();
        jsonResponse($response);
    }
    else if ($_REQUEST["method"] == "getStatus") {
        if (!file_exists($stepsPath)) {
            response500("No steps file");
        }
        if (!is_readable($stepsPath)) {
            response500("Cannot read steps file");
        }
        $data = file_get_contents($stepsPath);
        if ($data === false) {
            response500("Cannot open steps file");
        }
        else {
            rawJsonResponse($data);
        }
    }
    else {
        response400("Not supported method");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <title>PrivMX</title>
    <link href="assets/font-awesome.min.css" media="all" rel="stylesheet" />
    <link href="assets/main.css" media="all" rel="stylesheet" />
  </head>
  <body>
  <?php if ($completed): ?>
      <div id="main">
        
        <img id="logo" src="assets/logotype-wh.png" alt="">
        
        <div id="header">
            Updating PrivMX WebMail to version <?= $data["displayVersion"] ?> (<?= $data["buildId"] ?>) successfully completed.
        </div>
        <div id="finish-info" class="text-center">
          <p>
            <button>Log in</button>
          </p>
        </div>
      </div>
      <script type="text/javascript" src="assets/jquery.min.js"></script>
      <script type="text/javascript">
          $(function() {
              $("#finish-info").find("button").click(function() {
                  document.location = "<?= $data["loginUrl"] ?>";
              });
          });
      </script>
  <?php else: ?>
    <div id="main">
      
      <img id="logo" src="assets/logotype-wh.png" alt="">
      
      <div id="header">
        Updating PrivMX WebMail to version <?= $data["displayVersion"] ?> (<?= $data["buildId"] ?>) ...
        <div class="small custom-info">
          <?= $data["info"] ?>
        </div>
        <div class="small">
          Information about versions: <a href="http://privmx.com/versions" target="_blank" rel="noreferrer">http://privmx.com/versions</a>
        </div>
        <div class="small">
          In case of problems with the update, please visit <a href="https://privmx.com/faqupdate" target="_blank" rel="noreferrer">our FAQ page</a>
        </div>
      </div>
      
      <div id="steps"></div>
      
      <div id="update-error-placeholder"></div>
      
      <div id="finish-info" class="text-center" style="display: none;">
        <strong>Update completed</strong>
        <p>
          <button>Log in again</button>
        </p>
      </div>
      
    </div>
    <script type="text/javascript">
        var UPDATE_ID = "<?= $data["updateId"] ?>";
        var LOGIN_URL = "<?= $data["loginUrl"] ?>";
        var TOKEN = "<?= $data["token"] ?>";
    </script>
    <script type="text/javascript" src="assets/jquery.min.js"></script>
    <script type="text/javascript" src="assets/main.js"></script>
  <?php endif; ?>
  </body>
</html>
