<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\data;

date_default_timezone_set('UTC');
error_reporting(E_ALL & ~E_NOTICE);

use io\privfs\core\Utils;

class IOC {
    
    protected $config;
    protected $dbManager;
    protected $engine;
    protected $garbageCollector;
    protected $lock;
    protected $mailService;
    protected $nonce;
    protected $privFsUser;
    protected $settings;
    
    //================================================
    
    protected $accessService;
    protected $antiSelfRequestCache;
    protected $api;
    protected $base;
    protected $block;
    protected $blockCleaner;
    protected $cron;
    protected $descriptor;
    protected $domainFilter;
    protected $eventManager;
    protected $message;
    protected $notifier;
    protected $sessionHolder;
    protected $sink;
    protected $srp;
    protected $user;
    protected $validators;
    protected $keyLogin;
    protected $loginsLog;
    protected $loggerFactory;
    protected $serverEndpoint;
    protected $pki;
    protected $privmxClientFactory;
    protected $privmxPkiClientFactory;
    protected $privmxServiceDiscovery;
    protected $anonymousKeys;
    protected $srpLoginAttemptsLog;
    protected $transferSessions;
    protected $callbacks;
    protected $secureForm;
    protected $ticketStore;
    protected $cosignersProvider;
    protected $userStatus;
    
    protected $plugins;
    
    public function __construct($loadPlugins = true) {
        $this->checkConfigExistance(__DIR__ . "/../..");
        $this->loadCustomMenuItems(__DIR__ . "/../..");
        $rootDir = $dir = __DIR__ . "/../../";
        $loader = require($rootDir . "vendor/autoload.php");
        $this->loadPlugins($loader, $rootDir . "plugins/", $loadPlugins);
        // ensure config is initialized
        $this->getConfig();
    }
    
    public function checkConfigExistance($dir) {
        global $_PRIVMX_GLOBALS;
        
        if (!isset($_PRIVMX_GLOBALS["config"])) {
            if (file_exists($dir . "/config.php")) {
                require_once($dir . "/config.php");
            }
            else {
                if (file_exists($dir . "/tmpconfig.php")) {
                    require_once($dir . "/tmpconfig.php");
                }
                else {
                    http_response_code(400);
                    header("Content-Type: " . "application/json");
                    $error_json = json_encode(
                        $_PRIVMX_GLOBALS["error_codes"]["ONLY_POST_METHOD_ALLOWED"]
                    );
                    print('{"jsonrpc":"2.0", "id":null, "error":' . $error_json . '}');
                    die();
                }
            }
        }
        if (isset($_PRIVMX_GLOBALS["config"]["mathlib"])) {
            define('S_MATH_BIGINTEGER_MODE', $_PRIVMX_GLOBALS["config"]["mathlib"]);
        }
    }
    
    public function loadCustomMenuItems($dir) {
        if (file_exists($dir . "/config-custommenuitems.php")) {
            require_once($dir . "/config-custommenuitems.php");
        }
    }
    
    public function loadPlugins($loader, $pluginsDir, $load) {
        $this->plugins = array();
        if (! is_dir($pluginsDir)) {
            return;
        }
        $files = scandir($pluginsDir);
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            if ($file == "." || $file == "..") {
                continue;
            }
            $pluginDir = $pluginsDir . $file;
            $className = ucwords($file) . "Plugin";
            $namespace = "io\\privfs\\plugin\\" . $file . "\\";
            $loader->setPsr4($namespace, $pluginDir);
            $fullClassName = "\\" . $namespace . $className;
            if ($load) {
                array_push($this->plugins, new $fullClassName($this));
            }
        }
    }

    public function getPlugins() {
        return $this->plugins;
    }

    public function getPluginByName($name) {
        $className = "io\\privfs\\plugin\\" . $name . "\\" . ucwords($name) . "Plugin";
        foreach ($this->plugins as $plugin) {
            if (get_class($plugin) == $className) {
                return $plugin;
            }
        }
        return null;
    }
    
    public function getConfig() {
        if (is_null($this->config)) {
            $this->config = new \io\privfs\config\Config();
        }
        return $this->config;
    }
    
    public function getDbManager() {
        if (is_null($this->dbManager)) {
            $this->dbManager = new \io\privfs\core\DbManager($this->getConfig());
        }
        return $this->dbManager;
    }
    
    public function getEngine() {
        if (is_null($this->engine)) {
            $this->engine = new \io\privfs\core\Engine($this->getConfig());
        }
        return $this->engine;
    }
    
    public function getGarbageCollector() {
        if (is_null($this->garbageCollector)) {
            $this->garbageCollector = new \io\privfs\core\GarbageCollector(
                $this->getConfig(),
                $this->getLock(),
                $this->getSettings()
            );
        }
        return $this->garbageCollector;
    }
    
    public function getLock() {
        if (is_null($this->lock)) {
            $this->lock = new \io\privfs\core\Lock($this->getDbManager());
        }
        return $this->lock;
    }
    
    public function getMailService() {
        if (is_null($this->mailService)) {
            $this->mailService = new \io\privfs\core\MailService($this->getSettings());
        }
        return $this->mailService;
    }
    
    public function getNonce() {
        if (is_null($this->nonce)) {
            $this->nonce = new \io\privfs\core\Nonce($this->getConfig(), $this->getDbManager());
        }
        return $this->nonce;
    }
    
    public function getSettings() {
        if (is_null($this->settings)) {
            $this->settings = new \io\privfs\core\Settings($this->getDbManager());
        }
        return $this->settings;
    }
    
    //================================================
    
    public function getAccessService() {
        if (is_null($this->accessService)) {
            $this->accessService = new \io\privfs\data\AccessService($this->getUser());
        }
        return $this->accessService;
    }
    
    public function getApi() {
        if (is_null($this->api)) {
            $this->api = new \io\privfs\data\Api($this);
        }
        return $this->api;
    }
    
    public function getBase() {
        if (is_null($this->base)) {
            $this->base = new \io\privfs\data\Base($this->getDbManager());
        }
        return $this->base;
    }
    
    public function getBlock() {
        if (is_null($this->block)) {
            $this->block = new \io\privfs\data\Block(
                $this->getConfig(),
                $this->getDbManager(),
                $this->getNonce(),
                $this->getPrivFsUser(),
                $this->getTransferSessions()
            );
            $this->block->descriptor = $this->getDescriptor();
            $this->block->message = $this->getMessage();
        }
        return $this->block;
    }
    
    public function getBlockCleaner() {
        if (is_null($this->blockCleaner)) {
            $this->blockCleaner = new \io\privfs\data\BlockCleaner(
                $this->getConfig(),
                $this->getDbManager(),
                $this->getTransferSessions()
            );
        }
        return $this->blockCleaner;
    }
    
    public function getDescriptor() {
        if (is_null($this->descriptor)) {
            $this->descriptor = new \io\privfs\data\Descriptor(
                $this->getConfig(),
                $this->getDbManager(),
                $this->getBlock(),
                $this->getAccessService()
            );
        }
        return $this->descriptor;
    }
    
    public function getDomainFilter() {
        if (is_null($this->domainFilter)) {
            $this->domainFilter = new \io\privfs\data\DomainFilter(
                $this->getDbManager()
            );
        }
        return $this->domainFilter;
    }
    
    public function getEventManager() {
        if (is_null($this->eventManager)) {
            $this->eventManager = new \io\privfs\data\EventManager(
                $this->getConfig(),
                $this->getPlugins()
            );
        }
        return $this->eventManager;
    }
    
    public function getCron() {
        return new \io\privfs\data\Cron(
            $this->getGarbageCollector(),
            $this->getBlockCleaner(),
            $this->getNotifier(),
            $this->getEventManager(),
            $this->getNonce()
        );
    }
    
    public function getMessage() {
        if (is_null($this->message)) {
            $this->message = new \io\privfs\data\Message(
                $this->getDbManager(),
                $this->getBlock(),
                $this->getSink(),
                $this->getPrivFsUser(),
                $this->getAccessService(),
                $this->getAnonymousKeys(),
                $this->getDomainFilter(),
                $this->getUserStatus(),
                $this->getNotifier(),
                $this->getConfig(),
                $this->getCallbacks(),
                $this->getSettings(),
                $this->getMailService(),
                $this->getUser()
            );
        }
        return $this->message;
    }
    
    public function getNotifier() {
        if (is_null($this->notifier)) {
            $this->notifier = new \io\privfs\data\Notifier(
                $this->getConfig(),
                $this->getLock(),
                $this->getSettings(),
                $this->getDbManager(),
                $this->getMailService()
            );
        }
        return $this->notifier;
    }
    
    public function getPrivFsUser() {
        if (is_null($this->privFsUser)) {
            $this->privFsUser = new \io\privfs\data\PrivFsUser(
                $this->getPKI()
            );
        }
        return $this->privFsUser;
    }
    
    public function getSessionHolder() {
        if (is_null($this->sessionHolder)) {
            $this->sessionHolder = new \io\privfs\data\SessionHolder();
        }
        return $this->sessionHolder;
    }
    
    public function getSink() {
        if (is_null($this->sink)) {
            $this->sink = new \io\privfs\data\Sink(
                $this->getDbManager(),
                $this->getAccessService(),
                $this->getNonce(),
                $this->getConfig(),
                $this->getLock(),
                $this->getCallbacks()
            );
        }
        return $this->sink;
    }
    
    public function getSrpLoginAttemptsLog() {
        if (is_null($this->srpLoginAttemptsLog)) {
            $this->srpLoginAttemptsLog = new \io\privfs\core\LogDb(
                "srp-login-attempts",
                $this->getDbManager()
            );
        }
        return $this->srpLoginAttemptsLog;
    }
    
    public function getSrp() {
        if (is_null($this->srp)) {
            $this->srp = new \io\privfs\data\Srp(
                $this->getSessionHolder(),
                $this->getUser(),
                $this->getSrpLoginAttemptsLog(),
                $this->getConfig()
            );
        }
        return $this->srp;
    }
    
    public function getUser() {
        if (is_null($this->user)) {
            $this->user = new \io\privfs\data\User(
                $this->getDbManager(),
                $this->getSettings(),
                $this->getConfig(),
                $this->getNonce(),
                $this->getEventManager(),
                $this->getLoginsLog(),
                $this->getMailService(),
                $this->getPKI(),
                $this->getPlugins(),
                $this->getCallbacks()
            );
        }
        return $this->user;
    }
    
    public function getUserSafeProxy($session) {
        return new \io\privfs\data\UserSafeProxy($session, $this->getUser());
    }
    
    public function getValidators() {
        if (is_null($this->validators)) {
            $this->validators = new \io\privfs\data\Validators(
                $this->getConfig()
            );
        }
        return $this->validators;
    }
    
    public function getKeyLogin() {
        if (is_null($this->keyLogin)) {
            $this->keyLogin = new \io\privfs\data\KeyLogin(
                $this->getSessionHolder(),
                $this->getUser(),
                $this->getNonce()
            );
        }
        return $this->keyLogin;
    }
    
    public function getLoginsLog() {
        if (is_null($this->loginsLog)) {
            $this->loginsLog = new \io\privfs\core\LogDb(
                "logins",
                $this->getDbManager()
            );
        }
        return $this->loginsLog;
    }
    
    public function getLoggerFactory() {
        if (is_null($this->loggerFactory)) {
            $this->loggerFactory = \io\privfs\log\LoggerFactory::getInstance();
        }
        return $this->loggerFactory;
    }
    
    public function getPrivmxServiceDiscovery() {
        if (is_null($this->privmxServiceDiscovery)) {
            // TODO: pass config
            $this->privmxServiceDiscovery = new \simplito\PrivMXServiceDiscovery(
                null, $this->getConfig()->verifySSLCertificates()
            );
        }
        return $this->privmxServiceDiscovery;
    }
    
    public function getAntiSelfRequestCache() {
        if (is_null($this->antiSelfRequestCache)) {
            $this->antiSelfRequestCache = new \io\privfs\core\Cache(
                "anti-self-request.db",
                $this->getDbManager(),
                $this->getConfig()
            );
        }
        return $this->antiSelfRequestCache;
    }
    
    public function getPrivmxClientFactory() {
        if (is_null($this->privmxClientFactory)) {
            // TODO: known hosts from config
            $this->privmxClientFactory = new \io\privfs\protocol\PrivmxClientFactory(
                $this->getConfig(),
                $this->getPrivmxServiceDiscovery(),
                $this->getAntiSelfRequestCache()
            );
        }
        return $this->privmxClientFactory;
    }
    
    public function getPrivmxPkiClientFactory() {
        if (is_null($this->privmxPkiClientFactory)) {
            // TODO: known hosts from config
            $this->privmxPkiClientFactory = new \io\privfs\protocol\PrivmxPkiClientFactory(
                $this->getConfig(),
                $this->getPrivmxServiceDiscovery(),
                $this->getAntiSelfRequestCache()
            );
        }
        return $this->privmxPkiClientFactory;
    }
    
    public function getCosignersProvider() {
        if (is_null($this->cosignersProvider)) {
            $this->cosignersProvider = new \io\privfs\data\CosignersProvider(
                $this->getSettings()
            );
        }
        return $this->cosignersProvider;
    }
    
    public function getPKI() {
        if (is_null($this->pki)) {
            $serverConfig = $this->getConfig();
            $dbManager = $this->getDbManager();
            $config = array(
                "privmx.pki.server_keystore" => $serverConfig->getKeystore(),
                "privmx.pki.domains" => $serverConfig->getHosts(),
                "privmx.pki.cache_factory" => $dbManager->getFactory("pki-cache", false),
                "privmx.pki.dbfactory" => $dbManager->getFactory("pki-tree", false),
                "privmx.pki.max_cosigners" => $serverConfig->getMaxCosigners()
            );
            $this->pki = new \privmx\pki\PrivmxPKI(
                $config,
                $this->getPrivmxPkiClientFactory(),
                $this->getCosignersProvider()
            );
        }
        return $this->pki;
    }
    
    public function getServerEndpoint() {
        if (is_null($this->serverEndpoint)) {
            $this->serverEndpoint = new \io\privfs\protocol\ServerEndpoint(
                $this->getSrp(),
                $this->getUser(),
                $this->getConfig(),
                $this->getBlock(),
                $this->getDescriptor(),
                $this->getMessage(),
                $this->getSink(),
                $this->getKeyLogin(),
                $this->getValidators(),
                $this->getSessionHolder(),
                $this->getPKI(),
                $this->getPrivmxClientFactory(),
                $this->getLock(),
                $this->getDbManager(),
                $this->getCron(),
                $this->getDomainFilter(),
                $this->getSecureForm(),
                $this->getUserStatus(),
                $this->getPlugins(),
                $this->getCallbacks()
            );
        }
        return $this->serverEndpoint;
    }
    
    public function getAnonymousKeys() {
        if (is_null($this->anonymousKeys)) {
            $config = $this->getConfig();
            // TODO: use in memory cache ??
            $dbManager = $this->getDbManager();
            $this->anonymousKeys = new \privmx\pki\KVStore($dbManager->getFactory("anonymous"));
        }
        return $this->anonymousKeys;
    }
    
    public function getTransferSessions() {
        if (is_null($this->transferSessions)) {
            $config = $this->getConfig();
            // TODO: use in memory cache ??
            $dbManager = $this->getDbManager();
            $this->transferSessions = new \privmx\pki\KVStore($dbManager->getFactory("transferSession"));
        }
        return $this->transferSessions;
    }

    private function loadCallbacks() {
        $dir = __DIR__ . "/../../callbacks/";
        if( !is_dir($dir) )
            return;
        $files = scandir($dir);
        foreach($files as $file)
        {
            if( !\io\privfs\core\Utils::endsWith($file, ".php") )
                continue;
            require_once($dir . $file);
        }
    }

    public function getCallbacks()
    {
        if( is_null($this->callbacks) )
        {
            $this->loadCallbacks();
            $this->callbacks = new \io\privfs\core\Callbacks();
        }
        return $this->callbacks;
    }
    
    public function getSecureForm() {
        if (is_null($this->secureForm)) {
            $this->secureForm = new \io\privfs\data\SecureForm(
                $this->getDbManager()
            );
        }
        return $this->secureForm;
    }
    
    public function getUserStatus() {
        if (is_null($this->userStatus)) {
            $this->userStatus = new \io\privfs\data\UserStatus(
                $this->getDbManager(),
                $this->getConfig()
            );
        }
        return $this->userStatus;
    }
}
