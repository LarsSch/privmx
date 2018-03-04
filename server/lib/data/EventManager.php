<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\data;

use io\privfs\config\Config;

class EventManager {
    
    private $config;
    private $plugins;
    private $list;
    private $logger;
    
    public function __construct(Config $config, $plugins) {
        $this->config = $config;
        $this->plugins = $plugins;
        $this->list = array("tasks" => array());
        $this->logger = \io\privfs\log\LoggerFactory::get($this);
    }
    
    public function run() {
        if (!$this->config->hasEventHandler() || count($this->list["tasks"]) == 0) {
            return;
        }
        $descriptorSpec = array(
            0 => array("pipe", "r"),
            1 => array("pipe", "w")
        );
        $process = proc_open($this->config->getEventHandlerProcessPath(), $descriptorSpec, $pipes);
        if ($process === false) {
            $this->logger->error("Cannot run process");
            return;
        }
        fwrite($pipes[0], json_encode($this->list));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $exitCode = proc_close($process);
        if ($exitCode != 0) {
            $this->logger->error("Process ends with error: " . $exitCode . ", output: " . $output);
        }
    }
    
    public function newUserWithSink($hashmail, $lang) {
        $event = array(
            "type" => "new-user-with-public-sink",
            "hashmail" => $hashmail,
            "lang" => $lang
        );
        $this->publishEventToPlugins($event);
        array_push($this->list["tasks"], $event);
    }
    
    public function newUser($usersCount, $username, $host, $language, $isAdmin) {
        $event = array(
            "type" => "new-user",
            "usersCount" => $usersCount,
            "username" => $username,
            "host" => $host,
            "language" => $language,
            "isAdmin" => $isAdmin
        );
        $this->publishEventToPlugins($event);
    }
    
    public function publishEventToPlugins($event) {
        foreach ($this->plugins as $plugin) {
            $plugin->processEvent($event);
        }
    }
}