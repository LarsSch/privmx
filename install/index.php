<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
$minPhpVersion = "5.4.0";
 if (version_compare(PHP_VERSION, $minPhpVersion) >= 0) {
   require(__DIR__ . "/install.php");
   die();
 }
 $rootPath = dirname(__DIR__);
 $host = $_SERVER["HTTP_HOST"];
 $version = "unknown";
 $packJsonPath = $rootPath . "/pack.json";
 if (function_exists("json_decode") && @file_exists($packJsonPath)) {
   $pack = @json_decode(@file_get_contents($packJsonPath), true);
   if ($pack && isset($pack["version"]) && is_string($pack["version"])) {
     $version = $pack["version"];
     $s = explode(".", $version);
     if (count($s) == 4) {
         $version = implode(".", array_slice($s, 0, 3)) . " (" . $s[3] . ")";
     }
   }
 }
 ?>
 <!DOCTYPE html>
 <html lang="en">
  <head>
    <meta charset="utf-8">
    <title>PrivMX WebMail Installer</title>
    <link rel="shortcut icon" href="assets/favicon.ico" />
    <link rel="stylesheet" type="text/css" href="assets/main.css">
  </head>
  <body class="proto-https">
    <div id="content">
      <img id="logo" src="assets/logo.png" alt="" class="fade" />
        
      <div id="sections" class="section">
        <div id="start-section" class="section hide-after-install">
          <div class="title">
            PrivMX WebMail Installer
          </div>
          <div class="info">
            This script will install PrivMX WebMail on <strong><?php echo($host); ?></strong>
          </div>
          <div class="checksum">
            Only the current directory will be modified (<?php echo($rootPath); ?>)
          </div>
          <div class="versions">
            <table>
              <tbody>
                <tr>
                  <td>PrivMX WebMail</td>
                  <td>ver. <?php echo($version); ?></td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="checksum">
            We recommend you to verify checksum of the zip-file you are using.<br/>
            <a href="https://privmx.com/versions#verify" target="_blank" rel="noreferrer">List of checksums and tools for checking them</a>
          </div>
          <div class="section-buttons">
            <button id="next-button"><i class="icon-down"></i> Next</button>
          </div>
        </div>
        <div id="tests-section" class="section hide-after-install">
          <table class="tests">
            <tbody>
              <tr class="test-suite">
                <td colspan="3">Step 1/6 - PHP version</td>
              </tr>
              <tr class="error">
                <td class="test-label">
                  &ge; <?php echo($minPhpVersion); ?>
                </td>
                <td class="test-result">
                  PROBLEM
                </td>
                <td class="test-comment">
                  Your version <?php echo(PHP_VERSION); ?>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <script type="text/javascript">var AUTO_NEXT = true;</script>
    <script type="text/javascript" src="assets/jquery.min.js"></script>
    <script type="text/javascript" src="assets/main.js"></script>
  </body>
</html>