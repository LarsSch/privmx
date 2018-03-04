<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\log;

// init config
global $_PRIVMX_GLOBALS;
$_PRIVMX_GLOBALS["logs"] = array(
    "default" => "WARNING"
);

// Singleton factory object for monolog loggers
class LoggerFactory
{
    private static $instance = null;

    private $formatter;
    private $processor;
    private $loggers;

    private function __construct()
    {
        $requestId = substr(uniqid('', true), -8);
        $this->processor = function($record) use ($requestId) {
            $record["extra"]["requestId"] = $requestId;
            if (isset($record["context"]) && is_array($record["context"]) && count($record["context"]) == 1 && isset($record["context"]["_"]) && is_string($record["context"]["_"])) {
                $record["extra"]["thecontext"] = $record["context"]["_"];
                $record["context"] = array();
            }
            else if (!isset($record["extra"]["thecontext"])) {
                $record["extra"]["thecontext"] = "";
            }
            return $record;
        };
        $this->formatter = new \Monolog\Formatter\LineFormatter(
            // line format
            "[%datetime%] [%extra.requestId%] %level_name% - %channel% - %message% %context%%extra.thecontext%\n",
            // date format
            "Y-m-d H:i:s.u",
            // allow inline line breaks
            true,
            // ignore empty context and extra
            true
        );
        $this->loggers = array();
    }

    private function createLogger($name)
    {
        global $_PRIVMX_GLOBALS;
        if( isset($_PRIVMX_GLOBALS["logs"][$name]) )
            $level = $_PRIVMX_GLOBALS["logs"][$name];
        else
            $level = $_PRIVMX_GLOBALS["logs"]["default"];

        $level = \Monolog\Logger::toMonologLevel($level);
        $logger = new \Monolog\Logger($name);

        $fire_php_handler = new \Monolog\Handler\FirePHPHandler($level);
        $fire_php_handler->setFormatter($this->formatter);
        $logger->pushProcessor($this->processor);
        $logger->pushHandler($fire_php_handler);

        if( !empty($_PRIVMX_GLOBALS["logs"]["path"]) )
        {
            $stream_handler = new \Monolog\Handler\StreamHandler(
                $_PRIVMX_GLOBALS["logs"]["path"], $level
            );
            $stream_handler->setFormatter($this->formatter);
            $logger->pushProcessor($this->processor);
            $logger->pushHandler($stream_handler);
        }
        if( !empty($_PRIVMX_GLOBALS["logs"]["error_log"]) )
        {
            $error_log_handler = new \Monolog\Handler\ErrorLogHandler(0, $level);
            $error_log_handler->setFormatter($this->formatter);
            $logger->pushProcessor($this->processor);
            $logger->pushHandler($error_log_handler);
        }

        return $logger;
    }

    public function getLogger($for)
    {
        if( !is_string($for) )
            $for = get_class($for);

        if( !isset($this->loggers[$for]) )
            $this->loggers[$for] = $this->createLogger($for);

        return $this->loggers[$for];
    }

    public static function getInstance()
    {
        if( self::$instance === null )
            self::$instance = new LoggerFactory();
        return self::$instance;
    }

    public static function get($for)
    {
        return self::getInstance()->getLogger($for);
    }
}
