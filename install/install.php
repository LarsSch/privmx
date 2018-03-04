<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

class InstallerUtils {
  public static function endsWith($haystack, $needle) {
    return $needle === "" || substr($haystack, -strlen($needle)) === $needle;
  }
}

class Installer {
  
  const MIN_PHP_VERSION = "5.4.0";
  
  private $supportedDbaHandlers = array("qdbm", "gdbm", "db4", "ldba");
  
  private $ioc = null;
  public $rootPath;
  public $serverPath;
  public $tmpFilePath;
  public $configFilePath;
  public $keysFilePath;
  public $keysFileUnderServerDir;
  public $menuItemsPath;
  public $urls;
  public $packInfo;
  public $keys;
  public $customMenuItems;
  public $rawConfig;
  
  public function __construct() {
    $this->rootPath = dirname(__DIR__);
    $this->serverPath = $this->rootPath . "/server";
    $this->tmpFilePath = $this->serverPath . "/installtmp";
    $this->configFilePath = $this->serverPath . "/config.php";
    $this->keysFilePath = $this->serverPath . "/keys.php";
    $this->menuItemsPath = $this->serverPath . "/config-custommenuitems.php";
    
    $this->urls = $this->buildUrls();
    
    $defaultsPath = __DIR__ . "/install_defaults.php";
    if (file_exists($defaultsPath)) {
      $defaultConfig = require($defaultsPath);
    } else {
      $defaultConfig = array();
    }
    
    $this->rawConfig = $this->buildRawConfig($defaultConfig);
    $this->customMenuItems = array(array(
      "title" => $this->urls["hostUrl"],
      "icon" => "fa-external-link",
      "action" => $this->urls["mainHostUrl"]
    ));
    $this->keysFilePath = $this->rawConfig["keys"];
    $this->keysFileUnderServerDir = $this->fileIsUnderDir($this->keysFilePath, $this->serverPath);
    if( file_exists($this->keysFilePath) )
      require($this->keysFilePath);
    global $_PRIVMX_GLOBALS;
    if( isset($_PRIVMX_GLOBALS["keys"]) )
      $this->keys = $_PRIVMX_GLOBALS["keys"];
    else
      $this->keys = array();
    $this->loadPackInfo();
  }
  
  private function fileIsUnderDir($file, $dir) {
    $fileDir = dirname($file);
    if ($fileDir[strlen($fileDir) - 1] != DIRECTORY_SEPARATOR) {
      $fileDir .= DIRECTORY_SEPARATOR;
    }
    if ($dir[strlen($dir) - 1] != DIRECTORY_SEPARATOR) {
      $dir .= DIRECTORY_SEPARATOR;
    }
    return $fileDir == $dir;
  }

  private function sendRequest($url, $extResult = false, $ignoreSSLCertificates = false) {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_AUTOREFERER, true);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    if( $ignoreSSLCertificates || (isset($this->rawConfig["verifySSLCertificates"]) &&
        $this->rawConfig["verifySSLCertificates"] === false) )
    {
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    }
    if (isset($this->rawConfig["verifySSLCertificatesNames"]) && $this->rawConfig["verifySSLCertificatesNames"] === false) {
      curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }

    $data = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    return $extResult ? array("data" => $data, "error" => $error) : $data;
  }

  
  private function loadPackInfo() {
    $content = file_get_contents($this->rootPath . "/pack.json");
    if ($content && function_exists("json_decode")) {
      $this->packInfo = json_decode($content, true);
      $this->packInfo["displayVersion"] = "unknown";
      if (isset($this->packInfo["version"]) && is_string($this->packInfo["version"])) {
        $version = $this->packInfo["version"];
        $this->packInfo["displayVersion"] = $version;
        $s = explode(".", $version);
        if (count($s) == 4) {
          $this->packInfo["displayVersion"] = implode(".", array_slice($s, 0, 3)) . " (" . $s[3] . ")";
        }
      }
    }
    else {
      $this->packInfo = array("version" => "unknown", "displayVersion" => "unknown");
    }
  }
  
  public function autoLoad() {
    require_once($this->rootPath . "/server/vendor/autoload.php");
  }
  
  private function getIoc() {
    global $_PRIVMX_GLOBALS;
    $_PRIVMX_GLOBALS["config"] = $this->rawConfig;
    return new io\privfs\data\IOC(false);
  }
  
  public function handlePostRequest() {
    $action = isset($_POST['action']) ? $_POST['action'] : "";
    switch ($action) {
      case "install":
        $response = $this->install();
        break;
      default:
        $response = array("error" => "UNKNOWN_ACTION");
        break;
    }
    $this->respond($response);
  }
  
  public function getStartModel() {
    $result = array(
      "php" => $this->testPhpVersion()
    );
    $result["valid"] = $result["php"]["valid"];
    if ($result["valid"]) {
      $result["dirs"] = array("tests" => array(
        $this->testDirectory($this->rootPath),
        $this->testDirectory($this->serverPath),
        $this->testDirectory($this->rawConfig["dataDirectory"])
      ), "valid" => true);
      $configResult = $this->testWritableFile($this->configFilePath);
      if (!$configResult["valid"] && $configResult["exists"]) {
        array_push($result["dirs"]["tests"], $configResult);
      }
      $keysResult = $this->testWritableFile($this->keysFilePath);
      if (!$keysResult["valid"] && !$keysResult["exists"] && !$this->keysFileUnderServerDir) {
        array_push($result["dirs"]["tests"], $keysResult);
      }
      $menuItemsResult = $this->testWritableFile($this->menuItemsPath);
      if (!$menuItemsResult["valid"] && $menuItemsResult["exists"]) {
        array_push($result["dirs"]["tests"], $menuItemsResult);
      }
      foreach($result["dirs"]["tests"] as $x) {
        if (!$x["valid"]) {
          $result["dirs"]["valid"] = false;
          $result["valid"] = false;
          break;
        }
      }
    }
    return $result;
  }
  
  public function getEndModel() {
    $result = array(
      "modules" => $this->testRequiredPhpModules()
    );
    
    $result["valid"] = $result["modules"]["valid"];
    
    if (!$result["valid"]) {
      return $result;
    }
    
    $this->autoLoad();
    
    $result["databaseEngine"] = $this->testDatabaseEngine();
    if (!$result["databaseEngine"]["valid"]) {
      $result["valid"] = false;
    }
    
    if ($result["valid"]) {
      if (!$this->keys["keystore"]) {
        $this->keys["keystore"] = $this->generateKeyStore();
      }
      if( !$this->keys["symmetric"]) {
        $this->keys["symmetric"] = bin2hex(
          \io\privfs\core\Crypto::randomBytes(32)
        );
      }
      global $_PRIVMX_GLOBALS;
      $_PRIVMX_GLOBALS["keys"] = $this->keys;
      $result["pki"] = $this->testPki();
      if (!$result["pki"]["valid"]) {
        $result["valid"] = false;
      }
    }
    
    if ($result["valid"]) {
      $result["connection"] = array("tests" => array(
        $this->testRequestProtocol(),
        $this->testHttpsRequest(),
        $this->testServiceAvailability(),
        $this->testPrivmxConfigurationJson(),
        $this->testServiceDiscovery(),
        $this->testServerDataProtection()
      ), "valid" => true);
      foreach($result["connection"]["tests"] as $x) {
        if (!$x["valid"]) {
          $result["connection"]["valid"] = false;
          $result["valid"] = false;
          break;
        }
      }
    }
    
    return $result;
  }
  
  public function verify() {
    $text = file_get_contents($this->tmpFilePath);
    if (false === $text) {
      http_response_code(404);
      die();
    } else {
      die($text);
    }
  }
  
  public function isInstalled() {
    return file_exists($this->configFilePath);
  }
  
  public function install() {
    $result = array();
    if ($this->isInstalled()) {
      $result["error"] = "PrivMX is already installed";
    } else {
      $startModel = $this->getStartModel();
      if (!$startModel["valid"]) {
        $result["error"] = "Invalid configuration - please restart installer and try again";
      } else {
        $endModel = $this->getEndModel();
        if (!$endModel["valid"]) {
          $result["error"] = "Invalid configuration - please restart installer and try again";
        } else {
          $configContent = \io\privfs\core\Utils::dumpConfig($this->rawConfig);
          $keysContent = \io\privfs\core\Utils::dumpConfig($this->keys, "keys");
          $menuItemsContent = \io\privfs\core\Utils::dumpConfig($this->customMenuItems, "customMenuItems");
          if (!file_exists($this->keysFilePath) && file_put_contents($this->keysFilePath, $keysContent) === false ) {
            $result["error"] = "Configuration cannot be saved (insufficient access rights?). Location: `{$this->keysFilePath}`";
          } else if ( file_put_contents($this->menuItemsPath, $menuItemsContent) === false ) {
            $result["error"] = "Configuration cannot be saved (insufficient access rights?). Location: `{$this->menuItemsPath}`";
          } else if ( file_put_contents($this->configFilePath, $configContent) === false ) {
            $result["error"] = "Configuration cannot be saved (insufficient access rights?). Location: `{$this->configFilePath}`";
          } else {
            $result["success"] = true;
            $ioc = $this->getIoc();
            $urlPattern = $ioc->getConfig()->getAdminInvitationLinkPattern();
            $result["registrationUrl"] = $ioc->getUser()->generateAdminInvitation("Installation script", "User with admin rights created by the installation script", $urlPattern, "Admin")["link"];
            $result["loginUrl"] = $this->getProtocol() . "://" . $this->urls["clientFullUrl"];
            $this->saveDefaultSettings($ioc);
            $pki = $ioc->getPKI();
            $domain = $ioc->getConfig()->getHosts()[0];
            $pki->setCosigner($domain, array(
              "state" => "ACTIVE",
              "hashmail" => "",
              "uuid" => "13a97523-af3a-6ada-c99a-879409fe6c51",
              "keystore" => $pki->getServerKeyStore()->encode("binary")
            ));
          }
        }
      }
    }
    return $result;
  }
  
  private function saveDefaultSettings($ioc) {
    $path = __DIR__ . "/default-data.json";
    if (!file_exists($path)) {
      return;
    }
    $defaultData = json_decode(\io\privfs\core\Utils::getTextFromFile($path), true);
    if (is_null($defaultData)) {
      return;
    }
    $settings = $ioc->getSettings();
    if (isset($defaultData["initData"])) {
      $settings->setSetting("initData", json_encode($defaultData["initData"]));
    }
    if (isset($defaultData["initDataAdmin"])) {
      $settings->setSetting("initDataAdmin", json_encode($defaultData["initDataAdmin"]));
    }
    if (isset($defaultData["notifier"])) {
      $host = $ioc->getConfig()->getHosts()[0];
      foreach ($defaultData["notifier"]["langs"] as $langName => $lang) {
        $defaultData["notifier"]["langs"][$langName]["from"]["email"] = "privmxserver@" . $host;
        $defaultData["notifier"]["langs"][$langName]["body"] .= "\n\n" . $this->getProtocol() . "://" . $this->urls["clientFullUrl"];
      }
      $settings->setSetting("notifier", json_encode($defaultData["notifier"]));
    }
    if (isset($defaultData["invitationMail"])) {
      $host = $ioc->getConfig()->getHosts()[0];
      foreach ($defaultData["notifier"]["langs"] as $langName => $lang) {
        $defaultData["invitationMail"]["langs"][$langName]["from"]["email"] = "privmxserver@" . $host;
      }
      $settings->setSetting("invitationMail", json_encode($defaultData["invitationMail"]));
    }
  }
  
  private function testPhpVersion() {
    return array(
      "label" => "&ge; " . self::MIN_PHP_VERSION,
      "valid" => version_compare(PHP_VERSION, self::MIN_PHP_VERSION) >= 0,
      "comment" => "Your version: " . PHP_VERSION
    );
  }
  
  private function testDirectory($path, $byFile = false) {
    if (is_file($path)) {
      return array(
        "label" => $path,
        "exists" => true,
        "valid" => false,
        "comment" => ($byFile ?  "File is not writable. Parent folder location" : "Location") . " points to an existsing file, folder expected."
      );
    }
    $result = array(
      "label" => $path,
      "exists" => is_dir($path),
      "writable" => is_writable($path),
      "comment" => ""
    );
    $result['valid'] = $result["exists"] && $result["writable"];
    if (!$result["exists"]) {
      $result["comment"] = ($byFile ?  "File is not writable. Parent folder" : "Folder") . " does not exist.";
    } elseif (!$result["writable"]) {
      $result["comment"] = ($byFile ?  "File is not writable. Parent folder" : "Folder") . " is not writable.";
    }
    return $result;
  }
  
  private function testWritableFile($path) {
    if (!file_exists($path)) {
      $res = $this->testDirectory(dirname($path), true);
      $res["label"] = $path;
      $res["exists"] = false;
      return $res;
    }
    if (is_dir($path)) {
      return array(
        "label" => $path,
        "exists" => true,
        "valid" => false,
        "comment" => "Folder already exists in this location. File expected."
      );
    }
    $writable = is_writable($path);
    return array(
      "label" => $path,
      "exists" => true,
      "writable" => $writable,
      "valid" => $writable,
      "comment" => $writable ? "" : "File is not writable."
    );
  }
  
  private function testRequiredPhpModules() {
    $modules = array("curl", "openssl", "gmp", "json", "ctype", "mbstring", "zip");
    $result = array(
      "tests" => array(),
      "valid" => true
    );
    $invalid = array();
    foreach($modules as $name) {
      $m = $this->testPhpModule($name);
      $valid = $m["valid"];
      if ($m["valid"]) {
        if ($name == "gmp") {
          if (isset($this->rawConfig["mathlib"]) && $this->rawConfig["mathlib"] == "bcmath") {
            $result["tests"][] = $m;
            $bc = $this->testPhpModule("bcmath");
            if ($bc["valid"]) {
              $bc["warning"] = "You choose to use slower php-bcmath library. <a target='_blank' href='https://privmx.com/faqgmpbcmath'>Read more in new tab.</a>";
              $result["tests"][] = $bc;
            }
            else {
              array_push($invalid, "bcmath");
              $result["tests"][] = $bc;
              $valid = false;
            }
          }
          else if (isset($this->rawConfig["mathlib"]) && $this->rawConfig["mathlib"] != "gmp") {
            $valid = false;
            $m["valid"] = false;
            $m["comment"] = "You want to use unsupported mathlib (fix it in install_defaults.php)";
            $result["tests"][] = $m;
          }
          else {
            $result["tests"][] = $m;
          }
        }
        else {
          $result["tests"][] = $m;
        }
      }
      else {
        if ($name == "gmp") {
          $bc = $this->testPhpModule("bcmath");
          if ($bc["valid"]) {
            if (!isset($this->rawConfig["mathlib"]) || $this->rawConfig["mathlib"] == "bcmath") {
              $m["warning"] = "We can not find fast php-gmp mathematical library on this server, so PrivMX will be using slower php-bcmath library. <a target='_blank' href='https://privmx.com/faqgmpbcmath'>Read more in new tab.</a>";
              $valid = true;
              $result["tests"][] = $m;
              $result["tests"][] = $bc;
            }
            else {
              $valid = false;
              $m["label"] = "php-gmp or php-bcmath";
              $m["comment"] = "You want to use unsupported mathlib (fix it in install_defaults.php)";
              $result["tests"][] = $m;
            }
          }
          else {
            array_push($invalid, "gmp");
            array_push($invalid, "bcmath");
            $m["label"] = "php-gmp or php-bcmath";
            $result["tests"][] = $m;
          }
        }
        else {
          array_push($invalid, $name);
          $result["tests"][] = $m;
        }
      }
      if ($result["valid"] && !$valid) {
        $result["valid"] = false;
      }
    }
    if (count($invalid) > 0) {
        $query = implode(",", $invalid);
        foreach($result["tests"] as &$test) {
            if (!$test["valid"]) {
                $test["comment"] = "Module not found. <a target='_blank' href='https://privmx.com/faqnomodule?modules=" . $query . "'>Read more in new tab.</a>";
            }
        }
    }
    return $result;
  }
  
  private function testPhpModule($name) {
    $result = array(
      "label" => "php-$name",
      "valid" => $name == "dba" ? function_exists("dba_handlers") : extension_loaded($name),
      "comment" => ""
    );
    if (!$result["valid"]) {
      $result["comment"] = "Module not found. <a target='_blank' href='https://privmx.com/faqnomodule'>Read more in new tab.</a>";
    }
    return $result;
  }
  
  private function testHttpsRequest() {
    $result = array( "label" => "Connecting to other servers via HTTPS", "valid" => true, "comment" => "" );
    $url = "https://privmx.com/packages/https-test-install?v=" . $this->packInfo["version"];
    $res = $this->sendRequest($url, true);
    if ($res["data"] === false) {
      if (strpos($res["error"], "error:14090086") !== false) {
        $res = $this->sendRequest($url, true, true);
        if ($res["data"] === false) {
          $result["warning"] = "Unfortunatelly, the installer was not able to make a test HTTPS connection (to the PrivMX update service).";
        }
        else {
          $this->rawConfig["verifySSLCertificates"] = false;
          $result["warning"] = "It seems that your server's php-curl has problems with verification of SSL certificates. Your PrivMX server won't verify cerificates until you config your php-curl properly. <a target='_blank' href='https://privmx.com/faqhttpscert'>Read more in new tab.</a>";
        }
      }
      else {
        $result["warning"] = "Unfortunatelly, the installer was not able to make a test HTTPS connection (to the PrivMX update service).";
      }
    }
    return $result;
  }
  
  private function testRequestProtocol() {
    $https = $this->getProtocol() == "https";
    $result = array( "label" => "Hosting PrivMX WebMail via HTTPS", "valid" => true, "comment" => "");
    if (!$https) {
      $result["warning"] = "It seems that your website is not configured to use HTTPS. <a target='_blank' href='https://privmx.com/faqhttps'>Read more in new tab.</a>";
    }
    return $result;
  }
  
  private function testServiceAvailability() {
    $random = strval(rand());
    $url = $this->urls["installFullUrl"] . "?verify=true";
    $result = array( "label" => "Self-connectivity test", "valid" => true, "comment" => "" );
    if (false === file_put_contents($this->tmpFilePath, $random)) {
      $result["valid"] = false;
      $result["comment"] = "Cannot create temp file `$this->tmpFilePath`";
      return $result;
    }
    if ($random !== $this->sendRequest($url)) {
      $result["valid"] = false;
      $result["comment"] = "Your server can't connect to `$url`. Please check pre-install PrivMX configuration or settings of your domain. <a target='_blank' href='https://privmx.com/faqselfconn'>Read more in new tab.</a>";
    }
    unlink($this->tmpFilePath);
    return $result;
  }
    
  private function testPrivmxConfigurationJson() {
    $url = $this->urls["privmxJsonUrl"];
    $path = $this->rootPath . "/privmx-configuration.json";
    $result = array(
      "label" => "Creating privmx-configuration.json",
      "valid" => true,
      "comment" => "Created at: <a href='$url' target='_blank'>$url</a>",
      "path" => $path
    );
    $defaultEndpoint = $this->normalizeEndpointUrl($this->urls["serverFullUrl"]);
    $content = json_encode(array(
      "defaultEndpoint" => $defaultEndpoint
    ), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    file_put_contents($path, $content);
    return $result;
  }
  
  private function testServiceDiscovery() {
    $path = $this->rootPath . "/privmx-configuration.json";
    $result = array(
      "label" => "Service discovery test",
      "valid" => true,
      "comment" => "",
      "path" => $path
    );
    $defaultEndpoint = $this->normalizeEndpointUrl($this->urls["serverFullUrl"]);
    $verifySSLCertificates = true;
    if( isset($this->rawConfig["verifySSLCertificates"]) &&
        $this->rawConfig["verifySSLCertificates"] === false )
    {
      $verifySSLCertificates = false;
    }
    $serviceDiscovery = new simplito\PrivMXServiceDiscovery(
      null, $verifySSLCertificates
    );
    $config = $serviceDiscovery->discoverJSON($this->getHost());
    if (! $config) {
      $result["valid"] = false;
      $prefix = $this->getProtocol() . "://" . $this->urls["hostUrl"];
      $result["comment"] = "Unfortunately, the above config file is unreachable (to your server only?) under standard PrivMX service discovery paths.";
      $result["hint"] = "Valid file has been created at `$path`.<br/>Please copy that file to location which will be available under any of the following urls:
      <ul>
        <li>
          {$prefix}/privmx/privmx-configuration.json
        </li>
        <li>
          {$prefix}/privmx-configuration.json
        </li>
        <li>
          {$prefix}/.well-known/privmx-configuration.json
        </li>
      </ul>
      ";
    } else {
      $discoveredEndpoint = $this->normalizeEndpointUrl($config->defaultEndpoint);
      if ($defaultEndpoint !== $discoveredEndpoint) {
        $result["valid"] = false;
        $result["comment"] = "Wrong configuration - `defaultEndpoint` mismatch";
        $result["hint"] = "Your server `defaultEndpoint` should be set to one of the following values:";
        $result["hint"] .= "
          <ul>
            <li>
              `$defaultEndpoint` - RECOMMENDED: primary HTTPS, fallback to HTTP
            </li>
            <li>
              `https:$defaultEndpoint` - only if you want to force using HTTPS
            </li>
            <li>
              `http:$defaultEndpoint` - if you know that you will not support HTTPS at all
            </li>
          </ul>
        ";
      }
    }
    return $result;
  }
  
  private function normalizeEndpointUrl($url) {
    return rtrim(preg_replace('/^http[s]{0,1}:\/\//', '//', $url), '/');
  }
  
  private function testServerDataProtection() {
    $result = array(
      "label" => "Protection of data files",
      "valid" => true,
      "comment" => ""
    );
    $dataPathWildcard = \io\privfs\core\Utils::joinPaths($this->rawConfig["dataDirectory"], "*");
    $tempDataFilePath = \io\privfs\core\Utils::joinPaths($this->rawConfig["dataDirectory"], "temp.txt");
    $tempDataFileUrl = \io\privfs\core\Utils::concatUrl($this->urls["serverFullUrl"], "data/temp.txt");
    $now = "".time();
    file_put_contents($tempDataFilePath, $now);
    if ($now === $this->sendRequest($tempDataFileUrl)) {
      $result["warning"] = "Your encrypted data files ($dataPathWildcard) are available for download for everyone. It seems that included .htaccess file does not work correctly here. Please check later if your server supports .htaccess files or configure the server in another way to deny public access to your encrypted data files. <a target='_blank' href='https://privmx.com/faqhtaccess'>Read more in new tab.</a>";
    }
    unlink($tempDataFilePath);
    return $result;
  }
  
  private function testDatabaseEngine() {
    $engine = $this->rawConfig["databaseEngine"];
    $handlers = $this->getAvailableSupportedDbHandlers();
    $toInstall = implode($this->supportedDbaHandlers, ", ");
    $toChoose = implode($handlers, ", ");
    $result = array(
      "label" => implode(" / ", $this->supportedDbaHandlers),
      "valid" => true,
      "comment" => ""
    );
    if (!count($handlers)) {
      $result["valid"] = false;
      $result["comment"] = "None of supported engines is available, install one of the following: $toInstall.<br/>Recommended engine: {$this->supportedDbaHandlers[0]}";
      return $result;
    }
    if (!$engine) {
      $result["valid"] = false;
      if (count($handlers) == 1) {
        $result["comment"] = "Wrong configuration - `databaseEngine` not set.<br/>Available engine: {$handlers[0]}";
      } else {
        $result["comment"] = "Wrong configuration - `databaseEngine` not set.<br/>Available engines: $toChoose.<br/>Recommended engine: {$handlers[0]}";
      }
      return $result;
    }
    if (!in_array($engine, $handlers)) {
      $result["valid"] = false;
      if (count($handlers) == 1) {
        $result["comment"] = "Wrong configuration - given `databaseEngine` ($engine) is not supported.<br/>Available engine: {$handlers[0]}";
      } else {
        $result["comment"] = "Wrong configuration - given `databaseEngine` ($engine) is not supported.<br/>Available engines: $toChoose.<br/>Recommended engine: {$handlers[0]}";
      }
      return $result;
    }
    if (!$this->testDatabaseAccess()) {
      $result["valid"] = false;
      $result["comment"] = "Cannot create a database file";
    }
    if ($result["valid"]) {
      $result["comment"] = "Your engine: $engine";
      if (!function_exists("dba_handlers")) {
        $result["warning"] = "Your engine: $engine. We can not find fast php-dba database library on this server, so PrivMX will be using slower ldba library. <a target='_blank' href='https://privmx.com/faqldba'>Read more in new tab.</a>";
      }
      else if ($engine == "ldba") {
        $result["warning"] = "Your engine: $engine. You choose to use slower ldba library. <a target='_blank' href='https://privmx.com/faqldba'>Read more in new tab.</a>";
      }
    }
    return $result;
  }
  
  private function testDatabaseAccess() {
    $ioc = $this->getIoc();
    $base = $ioc->getBase();
    try {
      $base->canDatabasesBeOpened();
      return true;
    }
    catch (Exception $e) {
      $logger = \io\privfs\log\LoggerFactory::get("[INSTALL SCRIPT]");
      $logger->error($e);
      return false;
    }
  }
  
  private function testPki() {
    $config = $this->getIoc()->getConfig();
    $private = null;
    try
    {
      $keystore = \privmx\pki\keystore\KeyStore::decode($config->getKeystore());
      $key = $keystore ? $keystore->getPrimaryKey() : null;
      $private = $key ? $key->getPrivate() : null;
    }
    catch(\Exception $e) { /* no-op */ }
    $result = array(
      "label" => "Creating KeyStore",
      "valid" => $private !== null
    );
    $result["comment"] = $result["valid"] ? "" : "Invalid `keystore`";
    return $result;
  }
  
  private function generateKeyStore() {
    $keystore = new \privmx\pki\keystore\KeyStore($this->getHost());
    return $keystore->encode();
  }
  
  private function buildUrls() {
    $host = $this->getHost();
    $protocol = $this->getProtocol();
    $hostUrl = "${protocol}://${host}";
    $mainContextPath = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
    if (InstallerUtils::endsWith($mainContextPath, "/install/index.php")) {
      $mainContextPath = substr($mainContextPath, 0, strlen($mainContextPath) - strlen("install/index.php"));
    } elseif (InstallerUtils::endsWith($mainContextPath, "/install/")) {
      $mainContextPath = substr($mainContextPath, 0, strlen($mainContextPath) - strlen("install/"));
    }
    $mainFullUrl = $hostUrl . $mainContextPath;
    
    $serverContextPath = $mainContextPath . "server/";
    $serverFullUrl = $hostUrl . $serverContextPath;
    
    $clientContextPath = $mainContextPath . "app/";
    $clientFullUrl = $host . $clientContextPath;
    
    $installContextPath = $mainContextPath . "install/";
    $installFullUrl = $hostUrl . $installContextPath;
    $installFullUrlHttps = preg_replace("/^http:/i", "https:", $installFullUrl);
    
    $privmxJsonUrl = $hostUrl . $mainContextPath . "privmx-configuration.json";
    
    return array(
      "hostUrl" => $host,
      "mainHostUrl" => $hostUrl,
      "mainContextPath" => $mainContextPath,
      "mainFullUrl" => $mainFullUrl,
      "serverContextPath" => $serverContextPath,
      "serverFullUrl" => $serverFullUrl,
      "clientContextPath" => $clientContextPath,
      "clientFullUrl" => $clientFullUrl,
      "installContextPath" => $installContextPath,
      "installFullUrl" => $installFullUrl,
      "installFullUrlHttps" => $installFullUrlHttps,
      "privmxJsonUrl" => $privmxJsonUrl
    );
  }

  private function buildRawConfig($defaultConfig) {
    $config = array(
      "hosts" => array($this->getHost()),
      "serverUrl" => $this->urls["hostUrl"],
      "contextPath" => $this->urls["serverContextPath"],
      "dataDirectory" => $this->serverPath . "/data",
      "databaseEngine" => "",
      "keys" => $this->keysFilePath,
      "defaultInvitationLinkPattern" => $this->urls["clientFullUrl"] . "#token={token}",
      "adminInvitationLinkPattern" => $this->urls["clientFullUrl"] . "#token={token}&a=1",
      "appBuildLibPath" => $this->rootPath . "/app/build/lib"
    );
    if( $this->getProtocol() === "https" )
      $config["forceHTTPS"] = true;
    $config = array_merge($config, $defaultConfig);
    
    if (empty($config["databaseEngine"])) {
      $handlers = $this->getAvailableSupportedDbHandlers();
      $config["databaseEngine"] = count($handlers) ? $handlers[0] : "";
    }
    
    return $config;
  }
  
  private function getDbaHandlers() {
      $dbaExists = function_exists("dba_handlers");
      $res = $dbaExists ? dba_handlers() : array();
      array_push($res, "ldba");
      return $res;
  }
  
  private function getAvailableSupportedDbHandlers() {
    return array_values(array_intersect($this->supportedDbaHandlers, $this->getDbaHandlers()));
  }
  
  public function getProtocol() {
    if (!empty($_SERVER['HTTPS']) && $_SERVER["HTTPS"] != "off") {
      return "https";
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
      return $_SERVER['HTTP_X_FORWARDED_PROTO'];
    }
    return "http";
  }
  
  public function getHost() {
    return $_SERVER["HTTP_HOST"];
  }
  
  private function respond($data) {
    header('Content-Type:application/json;charset=utf-8');
    die(json_encode($data));
  }
  
}

// ============================================================================
$installer = new Installer();
$model = array("valid" => false);

if ($installer->isInstalled()) {
  header('Location: ..');
  die();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $installer->handlePostRequest();
} else {
  if (!empty($_GET["verify"])) {
    $installer->verify();
  }
  else if (isset($_GET["next"])) {
    $model = $installer->getStartModel();
    if ($model["valid"]) {
      $endModel = $installer->getEndModel();
      $model = array_merge($model, $endModel);
    }
  }
}

if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET["testHttps"]) && isset($_GET["callback"]) && is_string($_GET["callback"])) {
  die($_GET["callback"] . "('OK');");
}

function renderTest($model) {
  $testClass = "test-";
  $resultText = "";
  $comment = "";
  $hint = "";
  if (!empty($model["warning"])) {
    $testClass .= "warning";
    $resultText = "WARNING";
    $comment = $model["warning"];
  } else {
    $testClass .= $model["valid"] ? 'ok' : 'error';
    $resultText = $model["valid"] ? "&check; OK" : "PROBLEM";
    if (!empty($model["comment"])) {
      $comment = $model["comment"];
    }
  }
  if (!empty($model["hint"])) {
    $hint = $model["hint"];
  }
?>
  <tr class="<?= $testClass ?>">
    <td class="test-label">
      <?= $model["label"] ?>
    </td>
    <td class="test-result">
      <?= $resultText ?>
    </td>
    <td class="test-comment">
      <?= $comment ?>
    </td>
  </tr>
  <?php if ($hint): ?>
    <tr>
      <td colspan="3">
          <div class="test-hint">
            <?= $hint ?>
          </div>
      </td>
    </tr>
  <?php endif ?>
<?php
}

function renderFixInfo() {
?>
  <div id="fix-info">
    Please fix all above problems and restart installation process.
    <br/>
    <button onclick="window.location.reload()"><i class="icon-arrows-cw"></i> Restart installer</button>
  </div>
<?php
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
        
        <div id="http-warning">
          <div class="title">
            PrivMX WebMail Installer
          </div>
          <div class="main">
            <div class="info-header">INFORMATION</div>
            <div class="info-1">It is recommended to use HTTPS connection to serve web applications.</div>
            <div class="info-2">You are not using https:// connection now...</div>
            <ul>
              <li>switch to https:// and restart installer: <a href="<?= $installer->urls["installFullUrlHttps"] ?>"><?= $installer->urls["installFullUrlHttps"] ?></a> (it will work if you have configured HTTPS on your server) OR
              <li>ignore this issue, continue installation of PrivMX WebMail and take care of HTTPS later <a href="http://privmx.com/faqhttps" target="_blank">read more in new tab</a>.</li>
            </ul>
          </div>
          <div>
            <a class="button" href="<?= $installer->urls["installFullUrlHttps"] ?>">Restart installer using https://</a>
            <button>Ignore it now and continue installation</button>
          </div>
        </div>
      
        <div id="start-section" class="section hide-after-install">
          <div class="title">
            PrivMX WebMail Installer
          </div>
          <div class="info">
            This script will install PrivMX WebMail on <strong><?= $installer->getHost(); ?></strong>
          </div>
          <div class="checksum">
            Only the current directory will be modified (<?= $installer->rootPath ?>)
          </div>
          <div class="versions">
            <table>
              <tbody>
                <tr>
                  <td>PrivMX WebMail</td>
                  <td>ver. <?= $installer->packInfo["displayVersion"] ?></td>
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
              <tr class="test-suite <?= isset($model["php"]) ? '' : 'skipped' ?>">
                <td colspan="3">Step 1/6 - PHP version</td>
              </tr>
              <?php if (isset($model["php"])): ?>
                <?php renderTest($model["php"]); ?>
              <?php endif ?>
            </tbody>
          </table>
          
          <table class="tests">
            <tbody>
              <tr class="test-suite <?= isset($model["dirs"]) ? '' : 'skipped' ?>">
                <td colspan="3">Step 2/6 - File system permissions</td>
              </tr>
              <?php if (isset($model["dirs"])): ?>
                <?php foreach($model["dirs"]["tests"] as $test): ?>
                  <?php renderTest($test); ?>
                <?php endforeach ?>
              <?php endif ?>
            </tbody>
          </table>
          
          <table class="tests">
            <tbody>
              <tr class="test-suite <?= isset($model["modules"]) ? '' : 'skipped' ?>">
                <td colspan="3">Step 3/6 - Required PHP modules</td>
              </tr>
              <?php if (isset($model["modules"])): ?>
                <?php foreach($model["modules"]["tests"] as $test): ?>
                  <?php renderTest($test); ?>
                <?php endforeach ?>
              <?php endif ?>
            </tbody>
          </table>
          
          <table class="tests">
            <tbody>
              <tr class="test-suite <?= isset($model["databaseEngine"]) ? '' : 'skipped' ?>">
                <td colspan="3">Step 4/6 - Database engine</td>
              </tr>
              <?php if (isset($model["databaseEngine"])): ?>
                <?php renderTest($model["databaseEngine"]); ?>
              <?php endif ?>
            </tbody>
          </table>
          
          <table class="tests">
            <tbody>
              <tr class="test-suite <?= isset($model["pki"]) ? '' : 'skipped' ?>">
                <td colspan="3">Step 5/6 - PrivMX PKI Setup</td>
              </tr>
              <?php if (isset($model["pki"])): ?>
                <?php renderTest($model["pki"]); ?>
              <?php endif ?>
            </tbody>
          </table>
          
          <table class="tests">
            <tbody>
              <tr class="test-suite <?= isset($model["connection"]) ? '' : 'skipped' ?>">
                <td colspan="3">Step 6/6 - Service availability</td>
              </tr>
              <?php if (isset($model["connection"])): ?>
                <?php foreach($model["connection"]["tests"] as $test): ?>
                  <?php renderTest($test); ?>
                <?php endforeach ?>
              <?php endif ?>
            </tbody>
          </table>
          
          <?php if (!$model["valid"]): ?>
            <?php renderFixInfo() ?>
          <?php else: ?>
            <div  id="finish-wrapper">
              <div class="success">
                It seems that you can continue
              </div>
              <div class="accept-wrapper">
                <label>
                    <input id="accept-licence" type="checkbox" autocomplete="off" />
                    I accept terms and conditions of the
                    <a href="https://privmx.com/faqlicense?v=<?= $installer->packInfo["version"] ?>" target="_blank" rel="noreferrer">PrivMX Web Freeware License</a>
                </label>
              </div>
              <button id="install-button" autocomplete="off" disabled="disabled">&check; Finish installation</button>
              <div class="muted">
                It will create `<?= $installer->serverPath ?>/config.php` file.
              </div>
            </div>
          <?php endif ?>
          
        </div> <!-- /#tests-section -->
        
        <div id="confirm-section" class="section">
          <div class="on-success">
            <div class="header">&check; Installation completed</div>
            <div class="text">
              <div class="register-info">
                <p>
                  <a href="#" class="button" id="register-button">Activate Admin Account</a>
                </p>
                <p>
                  If you don't want to activate this account now, you can do it later using the following link:<br/>
                  <span id="register-link"></span>
                </p>
              </div>
              <br/>
              (or please <a href="#" id="login-button">login</a>, if you already have an account)
            </div>
          </div>
          <div class="on-error">
            <div class="header">&times; Installation failed</div>
            <div class="text"></div>
          </div>
        </div>
        
      </div> <!-- /#sections -->
      
      
		</div> <!-- /#content -->
    
    <script type="text/javascript">
      var CHECK_HTTPS = <?= (isset($_GET["next"]) || isset($_GET["forceHttp"]) ? "https" : $installer->getProtocol()) == "http" ? 'true' : 'false'; ?>;
      var AUTO_NEXT = <?= isset($_GET["next"]) ? 'true' : 'false' ?>;
    </script>
    <script type="text/javascript" src="assets/jquery.min.js"></script>
		<script type="text/javascript" src="assets/main.js"></script>
	</body>
</html>
