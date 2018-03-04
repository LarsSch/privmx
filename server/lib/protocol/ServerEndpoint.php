<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\protocol;

use Exception;
use ReflectionMethod;
use PSON\ByteBuffer;
use io\privfs\data\Srp;
use io\privfs\data\Cron;
use io\privfs\core\Lock;
use io\privfs\data\Sink;
use io\privfs\data\User;
use io\privfs\data\Block;
use io\privfs\data\Message;
use io\privfs\data\KeyLogin;
use io\privfs\core\DbManager;
use io\privfs\data\Descriptor;
use io\privfs\data\DomainFilter;
use io\privfs\data\Validators;
use io\privfs\data\SessionHolder;
use io\privfs\data\SecureForm;
use io\privfs\data\UserStatus;
use io\privfs\config\Config;
use io\privfs\jsonrpc\Raw;
use privmx\pki\PrivmxPKI;
use privmx\pki\keystore\Signature;

use io\privfs\core\JsonRpcException;

use GuzzleHttp\Psr7\ServerRequest;
use io\privfs\core\Callbacks;
use Monolog\Logger;

class ServerEndpoint
{
    private $config;
    private $validators;

    private $srp;
    private $user;
    private $keyLogin;
    private $block;
    private $descriptor;
    private $message;
    private $sink;
    private $sessionHolder;
    private $pki;
    private $clientFactory;
    private $lock;
    private $dbmanager;
    private $cron;
    private $domainFilter;
    private $secureForm;
    private $userStatus;
    private $userStatusRefreshed;
    private $userLevels;

    private $connection;
    private $session;

    private $plugins; // plugins callbacks
    private $callbacks; // plugins callbacks

    private $logger;

    // PERMISSIONS
    const KEY_LOGIN_ENABLED                 = 0x01;
    const REGISTRATION_ENABLED              = 0x02;
    const INTERSERVER_COMMUNICATION_ENABLED = 0x04;
    const CHANGE_CRETENTIALS_ENABLED        = 0x08;
    const SESSION_ESTABLISHED               = 0x10;
    const USER_AUTHENTICATED                = 0x20;
    const ADMIN_PERMISSIONS                 = 0x40;
    const ALLOW_WITH_MAINTENANCE            = 0x80;
    const LOW_USER_AUTHENTICATED            = 0x100;

    public function __construct(
        Srp $srp, User $user, Config $config, Block $block, Descriptor $descriptor,
        Message $message, Sink $sink, KeyLogin $keyLogin, Validators $validators,
        SessionHolder $sessionHolder, PrivmxPKI $pki, PrivmxClientFactory $clientFactory,
        Lock $lock, DbManager $dbmanager, Cron $cron, DomainFilter $domainFilter,
        SecureForm $secureForm, UserStatus $userStatus, $plugins,
        Callbacks $callbacks
    )
    {
        $this->logger = \io\privfs\log\LoggerFactory::get($this);

        $this->config = $config;
        $this->validators = $validators;

        $this->srp = $srp;
        $this->user = $user;
        $this->keyLogin = $keyLogin;
        $this->block = $block;
        $this->descriptor = $descriptor;
        $this->message = $message;
        $this->sink = $sink;
        $this->sessionHolder = $sessionHolder;
        $this->pki = $pki;
        $this->clientFactory = $clientFactory;
        $this->lock = $lock;
        $this->dbmanager = $dbmanager;
        $this->cron = $cron;
        $this->domainFilter = $domainFilter;
        $this->secureForm = $secureForm;
        $this->userStatus = $userStatus;
        $this->userStatusRefreshed = false;
        $this->callbacks = $callbacks;
        $this->userLevels = array(
            "low" => 5,
            "user" => 10,
            "admin" => 15
        );

        $this->connection = new PrivmxConnection(
            $config, ConnectionEnd::SERVER, ConnectionType::ONE_SHOT,
            $validators, $srp, $keyLogin
        );
        $this->connection->setKeyStore($pki->getServerKeyStore(true));
        $this->connection->setOutputStream(new \GuzzleHttp\Psr7\BufferStream());
        // TODO: use cache to store session, otherwise php is trying to send headers but content (handshakes, etc.) are sent first
        //$this->connection->setOutputStream(new \GuzzleHttp\Psr7\LazyOpenStream("php://output", "w"));
        $this->connection->app_frame_handler = $this;

        $this->session = NULL;

        $this->plugins = array();
        foreach($plugins as $plugin)
            $plugin->registerEndpoint($this);
    }

    // XXX: this isn't required when connection output stream is php://output
    private function flush()
    {
        print($this->connection->output->getContents());
    }

    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Options:
     *  - name string, bound method name, default $method
     *  - validator Validator, params validator, default $this->validators->get(name)
     *  - permissions array | number, method permissions, default none
     *  - withSession bool, bind session to object (call setSession), default false
     *
     * @param object $object - object to call method on, required
     * @param string $method - method name, required
     * @param array $options, optional
     */
    public function bind($object, $method, array $options = null) {
        $name = $method;
        if( is_array($options) && isset($options["name"]) )
            $name = $options["name"];

        $validator = null;
        if( is_array($options) && isset($options["validator"]) )
            $validator = $options["validator"];
        else
            $validator = $this->validators->get($name);

        $permissions = 0;
        if( is_array($options) && isset($options["permissions"]) )
            $permissions = $options["permissions"];

        $withSession = is_array($options) && isset($options["withSession"]) && $options["withSession"] === true;
        $reflection = new ReflectionMethod($object, $method);
        $this->plugins[$name] = function($params) use ($validator, $permissions, $reflection, $object, $withSession, $name) {
            $params = $validator->validate((array)$params);
            $this->assertMethod($name, $permissions);
            if( $withSession )
            {
                $session = $this->getSession();
                if( $session === null )
                    throw new Exception("Failed assert SESSION_ESTABLISHED for method '{$name}'");

                if( !method_exists($object, "setSession") )
                    throw new Exception("Cannot set session on " . get_class($object));

                $object->setSession($session);
            }
            // fix arguments order
            $arguments = array();
            foreach($reflection->getParameters() as $param) {
                $n = $param->getName();
                if( isset($params[$n]) )
                {
                    array_push($arguments, $params[$n]);
                    continue;
                }
                if( $param->isOptional() )
                    break;
                throw new Exception("Missing required parameter {$n} for method {$name}");
            }
            return $reflection->invokeArgs($object, $arguments);
        };
    }

    private function validatorName($method)
    {
        switch($method)
        {
            case "register":
                return "createUser";
        }
        return $method;
    }

    private function validate($method, $params)
    {
        $name = $this->validatorName($method);
        $this->logger->debug("validate - method: {$method}, validator name: {$name}");
        $validator = $this->validators->get($name);
        if (is_null($validator->spec)) {
            throw new JsonRpcException("METHOD_NOT_FOUND");
        }
        $this->logger->debug("validator", (array)$validator);
        return $validator->validate((array)$params);
    }

    private function getSession()
    {
        if( $this->session !== NULL )
            return $this->session;

        $session_id = $this->connection->getSessionId();
        if( $session_id === NULL || $session_id === "" )
        {
            $this->logger->debug("No session established");
            return NULL;
        }

        $this->logger->debug("Session id: {$session_id}");
        $session = $this->sessionHolder->restoreSession($session_id);
        if( is_null($session) )
            throw new Exception("Unknown session");
        
        if( $session["state"] !== "exchange" )
            throw new Exception("Invalid session state");
        
        $this->session = $session;
        return $this->session;
    }
    
    private function getUsernameFromSession() {
        $session = $this->getSession();
        return $session === NULL || !isset($session["user"]["I"]) ? null : $session["user"]["I"];
    }

    private function assertMethod($method, $permissions)
    {
        if( is_array($permissions) )
        {
            // OR
            $exception = NULL;
            foreach($permissions as $permission)
            {
                try
                {
                    $this->assertMethod($method, $permission);
                    return;
                }
                catch(Exception $e)
                {
                    $exception = $e;
                }
            }
            throw $exception;
        }

        // AND
        if( $this->config->isMaintenanceModeEnabled() )
        {
            $this->logger->debug("maintenance is enabled");
            if( !($permissions & self::ALLOW_WITH_MAINTENANCE) )
                throw new Exception("Failed assert ALLOW_WITH_MAINTENANCE for method '{$method}'");
        }

        if( $permissions & self::KEY_LOGIN_ENABLED )
        {
            $enabled = $this->config->isKeyLoginEnabled();
            $this->logger->debug("isKeyLoginEnabled: " . ($enabled ? "yes" : "no"));
            if( !$enabled )
                throw new Exception("Failed assert KEY_LOGIN_ENABLED for method '{$method}'");
        }

        if( $permissions & self::REGISTRATION_ENABLED )
        {
            $enabled = $this->config->isRegistrationEnabled();
            $this->logger->debug("isRegistrationEnabled: " . ($enabled ? "yes" : "no"));
            if( !$enabled )
                throw new Exception("Failed assert REGISTRATION_ENABLED for method '{$method}'");
        }

        if( $permissions & self::INTERSERVER_COMMUNICATION_ENABLED )
        {
            $enabled = $this->config->isInterServerCommunicationEnabled();
            $this->logger->debug("isInterServerCommunicationEnabled: " . ($enabled ? "yes" : "no"));
            if( !$enabled )
                throw new Exception("Failed assert INTERSERVER_COMMUNICATION_ENABLED for method '{$method}'");
        }

        if( $permissions & self::CHANGE_CRETENTIALS_ENABLED )
        {
            $enabled = $this->config->isChangeCredentialsEnabled();
            $this->logger->debug("isChangeCredentialsEnabled: " . ($enabled ? "yes" : "no"));
            if( !$enabled )
                throw new Exception("Failed assert CHANGE_CRETENTIALS_ENABLED for method '{$method}'");
        }

        if( $permissions & self::SESSION_ESTABLISHED )
        {
            if( $this->getSession() === NULL )
                throw new Exception("Failed assert SESSION_ESTABLISHED for method '{$method}'");
        }

        if( $permissions & self::LOW_USER_AUTHENTICATED )
        {
            $session = $this->getSession();
            if ($session === NULL || !isset($session["user"]["I"]) || !isset($session["user"]["type"]) || $this->userLevels[$session["user"]["type"]] < 5) {
                throw new Exception("Failed assert USER_AUTHENTICATED for method '{$method}'");
            }
            $user = $session["user"]["I"];
            $this->logger->debug("Logged as: {$user}");
        }

        if( $permissions & self::USER_AUTHENTICATED )
        {
            $session = $this->getSession();
            if ($session === NULL || !isset($session["user"]["I"]) || !isset($session["user"]["type"]) || $this->userLevels[$session["user"]["type"]] < 5) {
                throw new Exception("Failed assert USER_AUTHENTICATED for method '{$method}'");
            }
            $user = $session["user"]["I"];
            $this->logger->debug("Logged as: {$user}");
        }

        if( $permissions & self::ADMIN_PERMISSIONS )
        {
            $session = $this->getSession();
            if( $session === NULL || !isset($session["user"]["isAdmin"]) || $session["user"]["isAdmin"] !== true )
                throw new Exception("Failed assert ADMIN_PERMISSIONS for method '{$method}'");
            $this->logger->debug("User has ADMIN_PERMISSIONS");
        }
    }

    private function run($method, $params)
    {
        switch($method)
        {
            case "proxy":
                if (!$this->config->getPublicProxy()) {
                    $this->assertMethod($method, self::USER_AUTHENTICATED);
                }
                $res = $this->proxy($params["destination"], $params["encrypt"], $params["data"]);
                return is_string($res) ? new Raw($res, true) : $res;
                
            case "ping":
                return "pong";

            // Srp
            case "srpInfo":
                return $this->srp->info();

            // User
            case "getConfig":
                return $this->user->getConfig();
            case "register":
                $this->assertMethod($method, self::REGISTRATION_ENABLED);
                return $this->user->createUser(
                    $params["username"], $params["host"], $params["srpSalt"], $params["srpVerifier"],
                    $params["loginData"], $params["pin"], $params["token"], $params["privData"],
                    $params["keystore"], $params["kis"], $params["signature"], $params["email"], $params["language"],
                    $params["dataVersion"], !empty($params["weakPassword"])
                );
            case "registerInPKI":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->user->registerInPKI($this->getSession()["user"]["I"], $params["keystore"], $params["kis"]);
            case "getUsersPresence":
                $this->assertMethod($method, array(self::INTERSERVER_COMMUNICATION_ENABLED, self::SESSION_ESTABLISHED));
                return $this->user->getUsersPresence(
                    $params["usernames"], $params["host"], $params["pub58"],
                    $params["nonce"], $params["timestamp"], $params["signature"]
                );
            case "getUsersPresenceMulti":
                $this->assertMethod($method, array(self::INTERSERVER_COMMUNICATION_ENABLED, self::SESSION_ESTABLISHED));
                return $this->getUsersPresenceMulti($params["hosts"]);
            case "getMyData":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->user->getMyData($this->getSession()["user"]["I"]);
            case "setUserPreferences":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->user->setUserPreferences(
                    $this->getSession()["user"]["I"], $params["language"], $params["notificationsEntry"],
                    isset($params["contactFormSid"]) ? $params["contactFormSid"] : null
                );
            case "invite":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->user->invite($this->getSession()["user"]["I"]);
            case "setUserPresence":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->user->setUserPresence(
                    $this->getSession()["user"]["I"], $params["presence"],
                    $params["acl"], $params["signature"]
                );
            case "setUserInfo":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->user->setUserInfo($this->getSession()["user"]["I"], $params["data"], $params["kis"])->encode("array");
            case "getInitData":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->user->getInitData($this->getSession()["user"]["I"]);
            case "getPrivData":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->user->getPrivData($this->getSession()["user"]["I"]);
            case "setCredentials":
                // USER_AUTHENTICATED && CHANGE_CRETENTIALS_ENABLED
                $this->assertMethod($method, self::USER_AUTHENTICATED | self::CHANGE_CRETENTIALS_ENABLED);
                return $this->user->setCredentials(
                    $this->getSession()["user"]["I"], $params["srpSalt"], $params["srpVerifier"],
                    $params["privData"], $params["loginData"], $params["dataVersion"], !empty($params["weakPassword"])
                );
            case "getRecoveryData":
                $this->assertMethod($method, self::USER_AUTHENTICATED | self::CHANGE_CRETENTIALS_ENABLED);
                return $this->user->getRecoveryData($this->getSession()["user"]["I"]);
            case "setRecoveryData":
                $this->assertMethod($method, self::USER_AUTHENTICATED | self::CHANGE_CRETENTIALS_ENABLED);
                return $this->user->setRecoveryData(
                    $this->getSession()["user"]["I"], $params["privData"], $params["recoveryData"],
                    $params["dataVersion"]
                );
            case "setPrivData":
                $this->assertMethod($method, self::USER_AUTHENTICATED | self::CHANGE_CRETENTIALS_ENABLED);
                return $this->user->setPrivData(
                    $this->getSession()["user"]["I"], $params["privData"], $params["dataVersion"]
                );
            case "setContactFormEnabled":
                $this->assertMethod($method, self::USER_AUTHENTICATED | self::CHANGE_CRETENTIALS_ENABLED);
                return $this->user->setContactFormEnabled(
                    $this->getSession()["user"]["I"], $params["enabled"]
                );
            case "getUsers":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->user->getUsers();
            case "getUser":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->user->getUserEx($params["username"]);
            case "removeUser":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->user->removeUser($this->getSession()["user"]["I"], $params["username"]);
            case "addUser":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->user->addUser($params["username"], $params["pin"]);
            case "addUserWithToken":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->user->addUserWithToken(
                    $params["creator"], $params["username"], $params["email"], $params["description"],
                    $params["sendActivationLink"], $params["notificationEnabled"], $params["language"], $params["linkPattern"]
                );
            case "getUserConfig":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->user->getUserConfig();
            case "getConfigEx":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->user->getConfigEx();
            case "changePin":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->user->changePin($params["username"], $params["pin"]);
            case "changeEmail":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->user->changeEmail($params["username"], $params["changeEmail"]);
            case "changeDescription":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->user->changeDescription($params["username"], $params["description"]);
            case "changeContactFormEnabled":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->user->changeContactFormEnabled($params["username"], $params["enabled"]);
            case "changeSecureFormsEnabled":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->user->changeSecureFormsEnabled($params["username"], $params["enabled"]);
            case "changeUserData":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->user->changeUserData($this->getSession()["user"]["I"], $params["username"], $params["data"]);
            case "generateInvitations":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->user->generateInvitations(
                    $params["username"], $params["count"], $params["description"], $params["linkPattern"]
                );
            case "getFullInitData":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                // optional param
                if( is_array($params) && isset($params["key"]) )
                    return $this->user->getFullInitData($params["key"]);
                return $this->user->getFullInitData();
            case "setInitData":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->user->setInitData($params["data"], isset($params["key"]) ? $params["key"] : "");
            case "getNotifierConfig":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->user->getNotifierConfig();
            case "setNotifierConfig":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->user->setNotifierConfig($params["config"]);
            case "getInvitationMailConfig":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->user->getInvitationMailConfig();
            case "setInvitationMailConfig":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->user->setInvitationMailConfig($params["config"]);
            case "getLoginsPage":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->user->getLoginsPage($params["beg"], $params["end"]);
            case "getLastLogins":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->user->getLastLogins($params["count"]);
            case "changeIsAdmin":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->user->changeIsAdmin(
                    $this->getSession()["user"]["I"], $params["username"], $params["isAdmin"],
                    $params["data"], $params["kis"]
                )->encode("array");
            case "getForbiddenUsernames":
                return User::getForbiddenUsernames();
            
            //LowUser
            case "createLowUser":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->user->createLowUser($this->getSession()["user"]["I"], $params["host"]);
            case "modifyLowUser":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->user->modifyLowUser($this->getSession()["user"]["I"], $params);
            case "deleteLowUser":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->user->deleteLowUser($this->getSession()["user"]["I"], $params["username"]);
            
            // Block
            case "blockGet":
                $this->assertMethod($method, array(self::INTERSERVER_COMMUNICATION_ENABLED, self::SESSION_ESTABLISHED));
                return $this->block->blockGet($this->getUsernameFromSession(), $params["bid"], $params["source"]);
            case "blockCreate":
                $this->assertMethod($method, array(self::INTERSERVER_COMMUNICATION_ENABLED, self::SESSION_ESTABLISHED));
                return $this->block->blockCreate($params["transferIds"], $params["bid"], $params["data"]);
            case "blockAddToSession":
                $this->assertMethod($method, array(self::INTERSERVER_COMMUNICATION_ENABLED, self::SESSION_ESTABLISHED));
                return $this->block->blockAddToSession(
                    $this->getUsernameFromSession(), $params["transferIds"], $params["source"], $params["blocks"]
                );
            // Descriptor
            case "descriptorCheck":
                $this->assertMethod($method, array(self::INTERSERVER_COMMUNICATION_ENABLED, self::SESSION_ESTABLISHED));
                return $this->descriptor->descriptorCheck($params["dids"]);
            case "descriptorGet":
                $this->assertMethod($method, array(self::INTERSERVER_COMMUNICATION_ENABLED, self::SESSION_ESTABLISHED));
                return $this->descriptor->descriptorGet($params["dids"], $params['includeBlocks']);
            case "descriptorLock":
                $this->assertMethod($method, array(self::INTERSERVER_COMMUNICATION_ENABLED, self::SESSION_ESTABLISHED));
                return $this->descriptor->descriptorLock(
                    $params["did"], $params["lockId"], $params["signature"],
                    $params["lockerPub58"], $params["lockerSignature"], $params["force"]
                );
            case "descriptorRelease":
                $this->assertMethod($method, array(self::INTERSERVER_COMMUNICATION_ENABLED, self::SESSION_ESTABLISHED));
                return $this->descriptor->descriptorRelease($params["did"], $params["lockId"], $params["signature"]);
            case "descriptorUpdateInit":
                $this->assertMethod($method, array(self::INTERSERVER_COMMUNICATION_ENABLED, self::SESSION_ESTABLISHED));
                return $this->descriptor->descriptorUpdateInit($params["did"], $params["signature"]);
            case "descriptorUpdate":
                $this->assertMethod($method, array(self::INTERSERVER_COMMUNICATION_ENABLED, self::SESSION_ESTABLISHED));
                return $this->descriptor->descriptorUpdate(
                    $params["did"], $params["data"], $params["transferId"], $params["signature"],
                    $params["lockId"], $params["releaseLock"]
                );
            case "descriptorDelete":
                $this->assertMethod($method, array(self::INTERSERVER_COMMUNICATION_ENABLED, self::SESSION_ESTABLISHED));
                return $this->descriptor->descriptorDelete($params["did"], $params["signature"], $params["lockId"]);
            case "descriptorCreateInit":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->descriptor->descriptorCreateInit($this->getSession()["user"]["I"]);
            case "descriptorCreate":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->descriptor->descriptorCreate(
                    $this->getSession()["user"]["I"], $params["did"], $params["data"], $params["transferId"]
                );

            //DomainFilter
            case "getBlacklist":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->domainFilter->getBlacklist();
            case "setBlacklistEntry":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->domainFilter->setBlacklistEntry($this->getSession()["user"]["I"], $params["domain"], $params["mode"]);
            case "suggestBlacklistEntry":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->domainFilter->suggestBlacklistEntry($this->getSession()["user"]["I"], $params["domain"]);
            case "deleteBlacklistEntry":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->domainFilter->deleteBlacklistEntry($this->getSession()["user"]["I"], $params["domain"]);

            // Message
            case "messagePostInit":
                $this->assertMethod($method, array(self::INTERSERVER_COMMUNICATION_ENABLED, self::SESSION_ESTABLISHED));
                $extra = isset($params["extra"]) ? $params["extra"] : "";
                return $this->message->messagePostInit($params["sid"], $params["signature"], $extra);
            case "messagePost":
                $this->assertMethod($method, array(self::INTERSERVER_COMMUNICATION_ENABLED, self::SESSION_ESTABLISHED));
                $extra = isset($params["extra"]) ? $params["extra"] : "";
                return $this->message->messagePost($params["data"], $params["tags"], $params["transferId"], $extra);
            case "messageDelete":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->message->messageDelete(
                    $this->getSession()["user"]["I"], $params["sid"], $params["mids"],
                    $params["expectedModSeq"]
                );
            case "messageModify":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->message->messageModify(
                    $this->getSession()["user"]["I"], $params["sid"], $params["mid"],
                    $params["flags"], $params["tags"], $params["expectedModSeq"]
                );
            case "messageCreateAndDeleteInit":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->message->messageCreateAndDeleteInit(
                    $this->getSession()["user"]["I"], $params["dSid"], $params["dMid"], $params["cSid"]
                );
            case "messageCreateAndDelete":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->message->messageCreateAndDelete(
                    $this->getSession()["user"]["I"], $params["data"], $params["flags"], $params["tags"],
                    $params["transferId"], $params["sid"], $params["mid"], $params["expectedModSeq"]
                );
            case "messageGet":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->message->messageGet(
                    $this->getSession()["user"]["I"], $params["sid"], $params["mids"], $params["mode"]
                );
            case "messageCreateInit":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->message->messageCreateInit( $this->getSession()["user"]["I"], $params["sid"]);
            case "messageCreate":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->message->messageCreate(
                    $this->getSession()["user"]["I"], $params["data"], $params["flags"], $params["tags"],
                    $params["transferId"]
                );
            case "messageModifyTags":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->message->messageModifyTags(
                    $this->getSession()["user"]["I"], $params["sid"], $params["mids"], $params["toAdd"],
                    $params["toRemove"], $params["expectedModSeq"]
                );
            case "messageReplaceFlags":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->message->messageReplaceFlags(
                    $this->getSession()["user"]["I"], $params["sid"], $params["mid"], $params["flags"]);

            // Sink
            case "sinkGetAllMy":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->sink->sinkGetAllMy($this->getSession()["user"]["I"]);
            case "sinkCreate":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->sink->sinkCreate(
                    $this->getSession()["user"]["I"], $params["sid"], $params["acl"], $params["data"],
                    $params["options"]
                );
            case "sinkSave":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->sink->sinkSave(
                    $this->getSession()["user"]["I"], $params["sid"], $params["acl"], $params["data"],
                    $params["options"]
                );
            case "sinkDelete":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->sink->sinkDelete($this->getSession()["user"]["I"], $params["sid"]);
            case "sinkSetLastSeenSeq":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->sink->sinkSetLastSeenSeq(
                    $this->getSession()["user"]["I"], $params["sid"], $params["lastSeenSeq"]
                );
            case "sinkPoll":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->sink->sinkPoll(
                    $this->getSession()["user"]["I"], $params["sinks"], $params["updateLastSeen"]
                );
            case "sinkClear":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->sink->sinkClear(
                    $this->getSession()["user"]["I"], $params["sid"], $params["currentModSeq"]
                );
            case "sinkInfo":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->sink->sinkInfo(
                    $this->getSession()["user"]["I"], $params["sid"], $params["addMidList"]
                );
            case "sinkQuery":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                return $this->sink->sinkQuery(
                    $this->getSession()["user"]["I"], $params["sid"], $params["query"],
                    $params["limit"], $params["order"]
                );
            case "sinkGetAllByUser":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->sink->sinkGetAllMy($params["username"]);
            
            // PKI
            case "getKeyStore":
                $options = isset($params["options"]) ? $params["options"] : null;
                $revision = isset($params["revision"]) ? $params["revision"] : null;
                $prefix = explode(":", $params["name"])[0];
                if ($prefix == "admin") {
                    if ($params["includeAttachments"] !== false) {
                        $this->assertMethod($method, self::USER_AUTHENTICATED);
                    }
                }
                else if ($prefix != "user" && $prefix != "server") {
                    $this->assertMethod($method, self::USER_AUTHENTICATED);
                }
                return $this->pki->getKeyStore($params["name"], $options, $revision, $params["includeAttachments"])->encode("array");
            case "setPkiDocument":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->user->setPkiDocument($params["name"], $params["data"], $params["kis"])->encode("array");
            case "getHistory":
                $params = (array)$params;
                $revision = isset($params["revision"]) ? $params["revision"] : "";
                $seq = isset($params["seq"]) ? $params["seq"] : -1;
                $timestamp = isset($params["timestamp"]) ? $params["timestamp"] : 0;
                $msg = $this->pki->getHistory($revision, $seq, $timestamp);
                return $msg;
            case "signTree":
                list($cosigner_signature) = Signature::decode($params["signature"]);
                return $this->pki->signTree(
                    $params["domain"], $params["hash"], $params["sender"],
                    $cosigner_signature
                );
            case "setCosigner": // only admin can change server cosigners
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                $params["data"]["keystore"] = $params["data"]["keystore"]["base64"];
                $this->pki->setCosigner($params["domain"], $params["data"]);
                return $this->pki->getCosigners(true);
            case "removeCosigner":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                $this->pki->removeCosigner($params["domain"], $params["uuid"]);
                return $this->pki->getCosigners(true);
            case "getCosigners":
                $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                return $this->pki->getCosigners(true);
            case "getTreeSignatures":
                $isAdmin = false;
                try {
                    $this->assertMethod($method, self::ADMIN_PERMISSIONS);
                    $isAdmin = true; // admin can ask for all cosigners when verifying integration
                } catch(\Exception $e) { /* no op */ }
                return $this->pki->getTreeSignatures(
                    $params["cosigners"], $params["domain"], $params["hash"], $isAdmin
                )->encode("array");

            // Secure form
            case "createSecureFormToken":
                $this->assertMethod($method, self::USER_AUTHENTICATED);
                $username = $this->getSession()["user"]["I"];
                $sink = $this->sink->sinkGet($params["sid"]);
                if (! $sink) {
                    throw new JsonRpcException("SINK_DOESNT_EXISTS");
                }
                if ($sink["owner"] != $username) {
                    throw new JsonRpcException("ACCESS_DENIED");
                }
                return $this->secureForm->createToken($params["sid"]);
        }

        throw new Exception("Unknown method '{$method}'");
    }

    private function logException(Exception $e, $frame = "")
    {
        $this->logger->error("Error - " . $e->getMessage());
        $this->logger->notice("Trace:\n" . $e->getTraceAsString());
        if( $frame !== "" )
            $this->logger->debug("Frame:\n" . Utils::hexdump($frame));
    }
    
    private function prepareDataToLogCore($data) {
        if (is_string($data)) {
            return $data;
        }
        try {
            $json = json_encode($data);
            if ($json === false) {
                $json = "cannot-encode-json-" . json_last_error();
            }
        }
        catch(\Exception $e) {
            $json = "cannot-encode-json-exception";
        }
        return $json;
    }
    
    private function prepareDataToLog($data) {
        if (!$this->logger->isHandling(Logger::INFO)) {
            return array();
        }
        $res = $this->prepareDataToLogCore($data);
        return array("_" => strlen($res) > 1024 ? substr($res, 0, 1024) . "..." : $res);
    }
    
    private function handleFrame($frame)
    {
        $response = array("id" => $frame["id"], "jsonrpc" => "2.0");
        try
        {
            $method = $frame["method"];
            $params = $frame["params"];
            $session = $this->getSession();
            $this->logger->notice("[${frame['id_']}] frame start " . ($session === NULL || !isset($session["user"]["I"]) ? "(no session) " : "(" . $session["user"]["I"] . ") ") . $method);
            $this->logger->info("[${frame['id_']}] frame params", $this->prepareDataToLog($params));
            $result = null;

            if( isset($this->plugins[$method]) )
                $result = $this->plugins[$method]($params);
            else
            {
                $params = $this->validate($method, $params);
                $result = $this->run($method, $params);
            }
            if( $result instanceof Raw )
            {
                $data = $result->getData();
                $binary = $result->isBinary();
                $response["result"] = $binary ? ByteBuffer::wrap($data) : json_decode($data);
            }
            else {
                $response["result"] = $result;
            }
            $response["error"] = null;
        }
        catch(JsonRpcException $e)
        {
            $this->logException($e);
            $response["error"] = array(
                "data" => array(
                    "error" => array(
                        "code" => $e->getCode(),
                        "data" => $e->getData()
                    )
                ),
                "msg" => $e->getMessage(),
                //"trace" => $e->getTraceAsString()
            );
            $response["result"] = null;
        }
        catch(Exception $e)
        {
            global $_PRIVMX_GLOBALS;
            $this->logException($e);
            $response["error"] = array(
                "data" => array(
                    "error" => $_PRIVMX_GLOBALS["error_codes"]["INTERNAL_ERROR"]
                ),
                "msg" => $e->getMessage(),
                //"trace" => $e->getTraceAsString()
            );
            $response["result"] = null;
        }
        $this->logger->notice("[${frame['id_']}] frame response " . (is_null($response["error"]) ? "success" : "failure"));
        $this->logger->info("[${frame['id_']}] frame response data", $this->prepareDataToLog(is_null($response["error"]) ? $response["result"] : $response["error"]));
        return $response;
    }

    public function __invoke(PrivmxConnection $conn, $frame_data)
    {
        try
        {
            $this->refreshUserStatus();
            $frame = (array)Utils::pson_decode($frame_data, PrivmxConnection::$dict);
            $this->logger->debug("received", array("frame" => $frame));
            if( !isset($frame["id"]) || !isset($frame["method"]) || !isset($frame["params"]) ) {
                throw new Exception("Invalid frame");
            }
            $frame["id_"] = substr($frame["id"], -4);
            $response = $this->handleFrame($frame);
            $encoded = Utils::pson_encode($response, PrivmxConnection::$dict);
            $conn->send($encoded);
        }
        catch(Exception $e)
        {
            $this->logException($e, $frame_data);
            $conn->send($e->getMessage(), ContentType::ALERT);
        }
    }
    
    public function refreshUserStatus() {
        if ($this->userStatusRefreshed) {
            return;
        }
        $this->userStatusRefreshed = true;
        try {
            $username = $this->getUsernameFromSession();
            if ($username === NULL) {
                return;
            }
            $this->userStatus->refreshUser($username);
        }
        catch (Exception $e) {
            //session has no logged user => do nothing
        }
    }

    public function execute()
    {
        $this->lock->reader();

        $this->logger->notice("Processing request");
        try
        {
            $request = ServerRequest::fromGlobals();
            $this->connection->process($request->getBody());
        }
        catch(Exception $e)
        {
            $this->logException($e);
            $this->connection->send($e->getMessage(), ContentType::ALERT);
        }
        // XXX: this isn't required when connection output stream is php://output
        $this->flush();

        $this->lock->release();
        $this->dbmanager->close();

        if( $this->config->isCronEnabled() )
            $this->cron->execute();
        
        $this->callbacks->trigger("afterRequest");
    }

    public function getUsersPresenceMulti($hosts) {
        $res = array();
        foreach ($hosts as $host => $request) {
            if ($this->config->hostIsMyself($host)) {
                $res[$host] = $this->user->getUsersPresence($request["usernames"], $host, $request["pub58"],
                    $request["nonce"], $request["timestamp"], $request["signature"]);
            }
            else if (!$this->config->isInterServerCommunicationEnabled()) {
                throw new JsonRpcException("METHOD_NOT_FOUND");
            }
            else {
                try {
                    $res[$host] = $this->proxy($host, true, array("method" => "getUsersPresence", "params" => array(
                        "host" => $host,
                        "usernames" => $request["usernames"],
                        "pub58" => $request["pub58"]["base58"],
                        "nonce" => $request["nonce"],
                        "timestamp" => $request["timestamp"]["dec"],
                        "signature" => $request["signature"]["base64"]
                    )));
                }
                catch (\Exception $e) {
                    $this->logException($e);
                }
            }
        }
        return $res;
    }

    /**
     * @param string $host - destination server hostname
     * @param bool $encrypt - encrypt communication between servers
     * @param string | array $data - if encrypt is false then raw data to send,
     *  else method and parameters to call on remote server
     */
    public function proxy($host, $encrypt, $data)
    {
        $this->logger->debug("Proxy {$host}", array("data" => $data, "encrypt" => $encrypt));
        if( $encrypt === is_string($data) )
            throw new Exception("Incorrect data parameter");

        $host = $this->extractHost($host);

        if( $encrypt === false )
        {
            try {
                $client = $this->clientFactory->getRawClient($host);
            }
            catch (SelfRequestException $e) {
                if (!in_array($host, $this->config->getHosts()))  {
                    throw new \Exception("Forbidden self request to unsupported domain");
                }
                $ioc = new \io\privfs\data\IOC();
                $stream = new \GuzzleHttp\Psr7\BufferStream();
                $stream->write($data);
                $endpoint = $ioc->getServerEndpoint();
                $endpoint->connection->process($stream);
                return new \io\privfs\jsonrpc\Raw($endpoint->connection->output->getContents(), true);
            }
            return $client->sendRaw($data);
        }

        $data = (array)$data;
        $params = isset($data["params"]) ? $data["params"] : null;
        try {
            $client = $this->clientFactory->getClient($host);
        }
        catch (SelfRequestException $e) {
            if (!in_array($host, $this->config->getHosts()))  {
                throw new \Exception("Forbidden self request to unsupported domain");
            }
            return $this->run($data["method"], $params);
        }
        $promise = $client->call($data["method"], $params)->otherwise(function($error) use ($host) {
            $this->logger->debug("Proxy error from {$host}", array("error" => $error));
            if( $error instanceof Exception )
            {
                $this->logException($error);
                throw new Exception("Error while sending proxy to {$host}");
            }

            // remote server returned error
            JsonRpcException::fromRemote((array)$error->data->error);
        });

        $client->flush();
        return $promise->wait();
    }
    
    public function extractHost($destination) {
        return preg_replace_callback('/^(.*:)\/\/([A-Za-z0-9\-\.]+)(:[0-9]+)?(.*)$/', function($matches) {
            return $matches[2];
        }, $destination);
    }
}

?>
