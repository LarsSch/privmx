<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\protocol;

use PSON\ByteBuffer;
use GuzzleHttp\Promise;
use GuzzleHttp\Psr7\BufferStream;
use Psr\Http\Message\StreamInterface;
use io\privfs\config\Config;
use Monolog\Logger;

class PrivmxClientEndpoint
{
    private $id = 0;
    private $promises = array();
    private $connection = null;
    private $ticketHandshake = false;
    private $logger = null;

    public function __construct(Config $config, StreamInterface $stream = null)
    {
        if( $stream === null )
            $stream = new BufferStream();
        $this->logger = \io\privfs\log\LoggerFactory::get($this);
        $this->connection = new PrivmxConnection($config);
        $this->connection->setOutputStream($stream);
        $this->connection->app_frame_handler = $this;
    }

    public function __invoke(PrivmxConnection $conn, $data)
    {
        if ($this->logger->isHandling(Logger::DEBUG)) {
            $this->logger->debug("RPC data:\n". Utils::hexdump($data));
        }
        $rpc = (array)Utils::pson_decode($data, PrivmxConnection::$dict);
        $this->logger->debug("Received:\n", $rpc);
        if( !isset($rpc["id"]) )
        {
            $this->logger->warning("Missing id in response, ignoring");
            return;
        }

        $id = $rpc["id"];
        if( !isset($this->promises[$id]) )
        {
            $this->logger->warning("Missing promise for id {$id}, ignoring");
            return;
        }

        $this->logger->debug("Resolve promise for id {$id}");
        $promise = $this->promises[$id];
        unset($this->promises[$id]);

        if( isset($rpc["error"]) && $rpc["error"] !== null )
        {
            $promise->reject($rpc["error"]);
            return;
        }

        if( !isset($rpc["result"]) )
        {
            $promise->reject("Missing result");
            return;
        }

        $promise->resolve($rpc["result"]);
    }

    /**
     * @param ByteBuffer|string|StreamInterface data
     */
    public function process($data)
    {
        if( $data instanceof ByteBuffer )
            $data = $data->toBinary();

        if( is_string($data) )
        {
            $buffer = new BufferStream();
            $buffer->write($data);
            $data = $buffer;
        }

        $this->connection->process($data);
    }

    public function call($method, $params = null)
    {
        if( !$this->ticketHandshake )
        {
            $this->connection->reset();
            $this->connection->ticketHandshake();
            $this->ticketHandshake = true;
        }

        $id = ++$this->id;
        $data = Utils::pson_encode(array(
            "id" => $id,
            "method" => $method,
            "params" => $params === null ? array() : $params
        ), PrivmxConnection::$dict);
        $this->connection->send($data);
        $this->promises[$id] = new Promise\Promise();
        return $this->promises[$id];
    }

    public function flush()
    {
        $this->ticketHandshake = false;
        return $this->connection->output->getContents();
    }

    public function getConnection()
    {
        return $this->connection;
    }
};

?>
