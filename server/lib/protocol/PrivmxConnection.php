<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\protocol;

use GuzzleHttp\Psr7\BufferStream;
use privmx\pki\keystore\KeyStore;
use privmx\pki\keystore\KeyPair;
use Psr\Http\Message\StreamInterface;
use io\privfs\core\Crypto;
use PSON\ByteBuffer;
use Monolog\Logger;
use io\privfs\core\PasswordMixer;
use io\privfs\core\SrpLogic;
use io\privfs\data\Srp;
use io\privfs\data\KeyLogin;
use io\privfs\data\Validators;
use io\privfs\config\Config;
use BI\BigInteger;

class PrivmxConnection {
    public static $dict = array(
        "type", "ticket", "tickets", "ticket_id", "ticket_request",
        "ticket_response", "ecdhe", "ecdh", "key", "count", "client_random"
    );

    var $version;

    var $connection_end;
    var $connection_type;

    var $master_secret;
    var $client_random;
    var $server_random;

    var $session_id;
    var $client_agent;
    var $session;

    var $timestamp;

    var $write_state;
    var $read_state;
    var $next_read_state;
    var $next_write_state;

    var $app_frame_handler;

    var $output;

    var $tickets = array();
    private $config = null;

    private $validators;

    private $password_mixer;
    private $srp;
    private $keystore;

    private $keyLogin;
    private $ekey;

    private $logger;

    private static function encodeUint64($value)
    {
        $bb = new ByteBuffer();
        $bb->writeUint64($value);
        $bb->flip();
        return $bb;
    }

    public function __construct(
        Config $config,
        $connection_end = ConnectionEnd::CLIENT,
        $connection_type = ConnectionType::ONE_SHOT,
        Validators $validators = null,
        Srp $srp = null,
        KeyLogin $keyLogin = null
    ) {
        $this->logger = \io\privfs\log\LoggerFactory::get($this);
        $this->config = $config;

        $this->version = 1;
        $this->connection_end  = $connection_end;
        $this->connection_type = $connection_type;
        $this->write_state = new RWState();
        $this->read_state  = new RWState();
        $this->next_read_state  = new RWState();
        $this->next_write_state = new RWState();
        $this->client_random = "";
        $this->server_random = "";
        $this->master_secret = "";
        $this->session_id = NULL;
        $this->client_agent = NULL;
        $this->session = new Session();
        $this->password_mixer = new PasswordMixer();
        $this->validators = $validators;
        $this->srp = $srp;
        $this->keyLogin = $keyLogin;
        $this->keystore = NULL;
    }

    public function setKeyStore(KeyStore $keystore)
    {
        $this->keystore = $keystore;
    }

    /**
     * Sends a single binary data packet of specified content type. 
     */
    public function send($packet, $content_type = ContentType::APPLICATION_DATA, $force_plaintext = false) {
        if (!$force_plaintext && $this->write_state->initialized) {
            $this->logger->debug("send encrypted $content_type");
            $frame_length = strlen($packet);
            if ($frame_length > 0) {
                $frame_length = (($frame_length + 16) >> 4) << 4;
            }

            $frame_header_body = chr($this->version) . chr($content_type) . pack('N', $frame_length). chr(0) . chr(0);

            $seq_bin = self::encodeUint64($this->write_state->sequence_number);
            $this->write_state->sequence_number++;

            $frame_seed = $seq_bin . $frame_header_body;
            $frame_header_tag  = substr( hash_hmac('sha256', $frame_seed, $this->write_state->mac_key, true), 0, 8 );

            $frame_header = $frame_header_body . $frame_header_tag;
            $frame_header = Crypto::aes256EcbEncrypt($frame_header, $this->write_state->key);

            if ($frame_length > 0) {
                $iv = $frame_header;
                $frame_data = Crypto::aes256CbcPkcs7Encrypt($packet, $this->write_state->key, $iv);

                $frame_mac  = substr( hash_hmac('sha256', $frame_seed . $iv . $frame_data, $this->write_state->mac_key, true), 0, 16);
            } else {
                $frame_data = "";
                $frame_mac  = "";
            }
        } else {
            $this->logger->debug("send plaintext $content_type");
            // Encryption context not initialized
            $frame_length = strlen($packet);

            $frame_header = chr($this->version) . chr($content_type) . pack('N', $frame_length) . chr(0) . chr(0);
            $frame_data   = $packet;
            $frame_mac    = '';
        }

        $this->output->write($frame_header);
        $this->output->write($frame_data);
        $this->output->write($frame_mac);
    }

    private function readFromStream(StreamInterface $source, $number) {
        $result = $source->read($number);
        $count = strlen($result);
        while($count != $number) {
            $data = $source->read($number - $count);
            if( $data )
                $result .= $data;
            if( $source->eof() )
                break;
            $count = strlen($result);
        }

        return $result;
    }

    /**
     *
     */
    public function process(StreamInterface $input) {
        $response_processing = 
             ($this->connection_end  == ConnectionEnd::CLIENT) 
          && ($this->connection_type == ConnectionType::ONE_SHOT);
        
        if ($this->logger->isHandling(Logger::DEBUG)) {
            $content = $input->getContents();
            $input = new BufferStream();
            $input->write($content);
            $this->logger->debug("received\n" . Utils::hexdump($content));
        }
        $iv = "";
        $frame_seed = "";
        while( ! $input->eof() ) {
            if ($this->read_state->initialized) {
                $frame_header = $this->readFromStream($input, 16);
                if (strlen($frame_header) == 0) {
                    if ( ! $input->eof() )
                        throw new \Exception("read error (read 0 bytes)");
                    return;
                }

                // Reuse encrypted frame_header as IV for frame data
                $iv = $frame_header;

                $frame_header = Crypto::aes256EcbDecrypt($frame_header, $this->read_state->key);

                $frame_header_tag  = substr($frame_header, 8);
                $frame_header      = substr($frame_header, 0, 8);

                $seq_bin = self::encodeUint64($this->read_state->sequence_number);
                $this->read_state->sequence_number++;

                $frame_seed = $seq_bin . $frame_header;
                $expected_tag = substr( hash_hmac("sha256", $frame_seed, $this->read_state->mac_key, true), 0, 8 );

                if ($frame_header_tag != $expected_tag) {
                    throw new \Exception("Invalid frame TAG");
                }
            } else {
                $frame_header = $this->readFromStream($input, 8);
                if (strlen($frame_header) == 0) {
                    if ( ! $input->eof() )
                        throw new \Exception("read error (read 0 bytes)");
                    return;
                }
            }

            $frame_version = ord($frame_header[0]);
            if ($frame_version != $this->version) {
                throw new \Exception("Unsupported version");
            }

            $frame_content_type = ord($frame_header[1]);
            $frame_length = unpack('N', substr($frame_header, 2, 4))[1];

            $this->logger->debug("process " . ($this->read_state->initialized ? "encrypted" : "plaintext") . " frame {$frame_content_type}");

            if ($frame_length > 0) {
                $ciphertext = $this->readFromStream($input, $frame_length);

                if ($this->read_state->initialized) {
                    $frame_mac  = $this->readFromStream($input, 16);

                    $mac_data = $frame_seed . $iv . $ciphertext;
                    $expected_mac = substr( hash_hmac("sha256", $mac_data, $this->read_state->mac_key, true), 0, 16 );
                    if ($frame_mac != $expected_mac) {
                        throw new \Exception("Invalid frame MAC");
                    }
                    $frame_data = Crypto::aes256CbcPkcs7Decrypt($ciphertext, $this->read_state->key, $iv);
                } else {
                    $frame_data = $ciphertext;
                }
            } else {
                $frame_data = "";
            }


            switch($frame_content_type) {
            case ContentType::APPLICATION_DATA:
                $this->logger->debug("application", array("data" => $frame_data));
                if ($this->app_frame_handler) {
                    call_user_func($this->app_frame_handler, $this, $frame_data);
                }
                break;

            case ContentType::HANDSHAKE:
                $packet = Utils::pson_decode($frame_data, self::$dict);
                if ($packet->type == "ecdh") {
                
                    if (!$this->session->contains("our_ec_key"))
                        throw new \Exception("Invalid state");

                    $ec   = new \Elliptic\EC("secp256k1");
                    
                    $ekey = $ec->keyPair($this->session->get("our_ec_key"));

                    $cekey = $ec->keyFromPublic($packet->key->toBinary);
                    $der = hex2bin($ekey->derive($cekey->getPublic())->toString("hex", 2));
                    $z = \io\privfs\core\Utils::fillTo32($der);
                    $this->setPreMasterSecret($z);
                    if (!$response_processing)
                        $this->changeCipherSpec();

                } elseif ($packet->type == "ecdhe") {
                    $ec   = new \Elliptic\EC("secp256k1");

                    if ($this->session->contains("ecdhe_key")) {
                    // Jeżeli mamy klucz w sesji to znaczy, że to odpowiedź na zainicjowany przez nas handshake
                        // pobieramy i usuwamy klucz z sesji bo handshake właśnie się kończy
                        $ekey = $ec->keyPair($this->session->get("ecdhe_key"));
                        $this->session->delete("ecdhe_key");
                        $this->session->save("server_agent", isset($packet->agent) ? $packet->agent : null);
                    } else {
                        // Skoro nie mamy klucza w sesji to znaczy, że to my musimy odesłać odpowiedź,
                        // a że parę kluczy już mamy to handshake i w tym przypadku się kończy
                        $ekey = $ec->genKeyPair();
                        $pson = Utils::pson_encode(
                            array(
                                "type" => "ecdhe",
                                "key" => ByteBuffer::wrap($ekey->getPublic(true, "binary")),
                                "agent" => $this->getServerAgent()
                            ),
                            self::$dict
                        );
                        $this->send($pson, ContentType::HANDSHAKE);
                        if (isset($packet->agent)) {
                            $this->client_agent = $packet->agent;
                        }
                    }

                    $cekey= $ec->keyFromPublic($packet->key->toBinary());
                    $der  = hex2bin($ekey->derive($cekey->getPublic())->toString("hex", 2));
                    $z = \io\privfs\core\Utils::fillTo32($der);
                    $this->setPreMasterSecret($z);
                    if (!$response_processing)
                        $this->changeCipherSpec();

                } elseif ($packet->type == "ticket_response") {
                    $this->logger->debug("ticket_response");
                    $this->saveTickets($packet->tickets);
                } elseif ($packet->type == "ticket_request") {
                    $this->logger->debug("ticket_request");
                    $tickets = $this->generateTickets($packet->count);
                    $ttl = $this->config->getTicketsTTL();
                    $pson = Utils::pson_encode(
                        array("type" => "ticket_response", "tickets" => $tickets, "ttl" => $ttl),
                        self::$dict
                    );
                    $this->send($pson, ContentType::HANDSHAKE);
                } elseif ($packet->type == "ticket") {
                    $ticket_id = $packet->ticket_id->toBinary();
                    $client_random = $packet->client_random->toBinary();
                    $this->logger->debug("ticket " . bin2hex($ticket_id));
                    $ticket = $this->useTicket($ticket_id);
                    if (!$ticket )
                        throw new \Exception("Invalid ticket");
                    $this->restore($ticket, $client_random);
                } elseif ($packet->type == "srp_init" && $this->is_server_connection()) {
                    // srp init client request
                    if( $this->srp === null )
                        throw new \Exception("SRP handshake uavailable");
                    $this->logger->debug("srp init request", array("packet" => $packet));
                    $properties = isset($packet->properties) ? (array)$packet->properties : array();
                    if (isset($packet->agent)) {
                        $this->client_agent = $packet->agent;
                    }
                    $response = $this->srp->init($packet->I, $packet->host, $properties);
                    $response["type"] = "srp_init";
                    $response["agent"] = $this->getServerAgent();
                    $this->logger->debug("send srp init response", array("response" => $response));
                    $this->send(
                        Utils::pson_encode($response, self::$dict),
                        ContentType::HANDSHAKE
                    );
                } elseif ($packet->type == "srp_init" && $this->is_client_connection()) {
                    // srp init response from server
                    $this->logger->debug("srp init response", array("packet" => $packet));
                    $this->session->save("server_agent", isset($packet->agent) ? $packet->agent : null);
                    $exchange = $this->validate_srp((array)$packet);
                    $this->reset(true);
                    $this->ticketHandshake();
                    $this->logger->debug("srp exchange request", array("request" => $exchange));
                    $this->send(
                        Utils::pson_encode($exchange, self::$dict),
                        ContentType::HANDSHAKE
                    );
                } elseif ($packet->type == "srp_exchange" && $this->is_server_connection()) {
                    // srp exchange client request ( handshake finnished - server side )
                    $ssid = $packet->sessionId;
                    $this->logger->debug("session_id {$ssid}", array("packet" => $packet));
                    $A = array("hex" => $packet->A, "bi" => new BigInteger($packet->A, 16));
                    $M1 = array("hex" => $packet->M1, "bi" => new BigInteger($packet->M1, 16));
                    $response = $this->srp->exchange($ssid, $A, $M1, true);
                    $response["type"] = "srp_exchange";
                    $K = \io\privfs\core\Utils::fillTo32($response["K"]);
                    unset($response["K"]);
                    $this->session_id = $ssid;
                    $this->setPreMasterSecret($K);
                    $this->logger->debug("generate tickets after srp {$packet->tickets}");
                    $response["tickets"] = $this->generateTickets($packet->tickets);
                    $response["ttl"] = $this->config->getTicketsTTL();
                    $this->logger->debug("srp exchange done on server", array("response" => $response));
                    $this->send(
                        Utils::pson_encode($response, self::$dict),
                        ContentType::HANDSHAKE
                    );
                    $this->changeCipherSpec();
                } elseif ($packet->type == "srp_exchange" && $this->is_client_connection()) {
                    // srp exchange response from server ( handshake finnished )
                    $this->validate_srp((array)$packet);
                    $this->logger->debug("srp handshake successful");
                } elseif ($packet->type == "ecdhef" && $this->is_server_connection() ) {
                    $this->logger->debug("ecdhef request", array("packet" => $packet));
                    $key = $this->keystore !== null ? $this->keystore->getKeyById($packet->keyid) : null;
                    if( $key === null )
                        throw new \Exception("Cannot find key with id " . $packet->keyid);
                    $ec = new \Elliptic\EC("secp256k1");
                    $cekey = $ec->keyFromPublic($packet->key->toBinary());
                    $this->logger->debug("derive");
                    $der = hex2bin($key->keyPair->derive($cekey->getPublic())->toString("hex", 2));
                    $z = \io\privfs\core\Utils::fillTo32($der);
                    $this->setPreMasterSecret($z);
                    $this->changeCipherSpec();
                } elseif( $packet->type === "key_init" && $this->is_server_connection() ) {
                    // key init client request
                    if( $this->keyLogin === null )
                        throw new \Exception("Key Login handshake uavailable");
                    $this->logger->debug("key login init request", array("packet" => $packet));
                    $params = array("pub" => $packet->pub, "properties" => (array)$packet->properties);
                    if (isset($packet->agent)) {
                        $this->client_agent = $packet->agent;
                    }
                    $params = $this->validators->get("keyInit")->validate($params);
                    $response = $this->keyLogin->init($params["pub"], $params["properties"]);
                    $response["type"] = "key_init";
                    $response["agent"] = $this->getServerAgent();
                    $this->logger->debug("send srp init response", array("response" => $response));
                    $this->send(
                        Utils::pson_encode($response, self::$dict),
                        ContentType::HANDSHAKE
                    );
                } elseif( $packet->type === "key_exchange" && $this->is_server_connection() ) {
                    // key exchange client request
                    $params = array(
                        "sessionId" => $packet->sessionId,
                        "nonce" => $packet->nonce,
                        "timestamp" => $packet->timestamp,
                        "signature" => $packet->signature,
                        "K" => $packet->K
                    );
                    $params = $this->validators->get("keyExchange")->validate($params);
                    $K = $this->keyLogin->exchange(
                        $params["sessionId"], $params["nonce"], $params["timestamp"],
                        $params["signature"], $params["K"], true
                    );
                    $K = \io\privfs\core\Utils::fillTo32($K);
                    
                    $this->session_id = $packet->sessionId;
                    $this->setPreMasterSecret($K);

                    $this->logger->debug("generate tickets after key login {$packet->tickets}");
                    $response = array(
                        "type" => "key_exchange",
                        "tickets" => $this->generateTickets($packet->tickets),
                        "ttl" => $this->config->getTicketsTTL()
                    );
                    $this->logger->debug("send key exchange response", array("response" => $response));
                    $this->send(
                        Utils::pson_encode($response, self::$dict),
                        ContentType::HANDSHAKE
                    );
                }
                break;

            case ContentType::CHANGE_CIPHER_SPEC:
                $this->logger->debug("change cipher spec");
                if (!$this->next_read_state->initialized)
                    throw new \Exception("Invalid next read state");
                $this->read_state = $this->next_read_state;
                $this->next_read_state = new RWState();
                break;

            case ContentType::ALERT:
                $this->logger->error("Got ALERT");
                break;
            }
        }
    }

    public function setOutputStream(StreamInterface $output) {
        $this->output = $output;
    }
    
    public function getClientAgent() {
        return "webmail-server-php;1.0.0";
    }
    
    public function getServerAgent() {
        return "webmail-server-php;1.0.0";
    }

    public function ecdheHandshake() {
        if ($this->session->contains("ecdhe_key"))
            throw new \Exception("Invalid handshake state");
        $ec = new \Elliptic\EC("secp256k1");
        $ekey = $ec->genKeyPair();
        $this->ekey = $ekey;

        $pson = Utils::pson_encode(
            array("type" => "ecdhe", "key" => ByteBuffer::wrap($ekey->getPublic(true, "binary")), "agent" => $this->getClientAgent()),
            self::$dict
        );
        $this->send($pson, ContentType::HANDSHAKE);

        $this->session->save("ecdhe_key", array("pub" => $ekey->getPublic("hex"), "pubEnc" => "hex", "priv" => $ekey->getPrivate("hex"), "privEnc" => "hex"));
    }

    public function ecdhefHandshake(KeyPair $key)
    {
        $ec = new \Elliptic\EC("secp256k1");
        $ekey = $ec->genKeyPair();
        $this->ekey = $ekey;

        $pson = Utils::pson_encode(
            array("type" => "ecdhef", "keyid" => $key->getKeyId(), "key" => ByteBuffer::wrap($ekey->getPublic(true, "binary")), "agent" => $this->getClientAgent()),
            self::$dict
        );

        $this->send($pson, ContentType::HANDSHAKE);
        $der = hex2bin($ekey->derive($key->getPublic(false, false))->toString("hex", 2));
        $z = \io\privfs\core\Utils::fillTo32($der);
        $this->setPreMasterSecret($z);
        $this->changeCipherSpec();
    }

    public function srpHandshake($hashmail, $password, $tickets = 1)
    {
        $split = explode("#", $hashmail);
        if( count($split) !== 2 )
            throw new \Exception("Incorrect hashmail {$hashmail}");

        $username = $split[0];
        $host = $split[1];
        if( $this->session->contains("srp_data") )
            throw new \Exception("Invalid handshake state");

        $pson = Utils::pson_encode(
            array("type" => "srp_init", "I" => $username, "host" => $host, "agent" => $this->getClientAgent()),
            self::$dict
        );
        $this->send($pson, ContentType::HANDSHAKE);
        $this->session->save("srp_data", array("I" => $username, "password" => $password, "tickets" => $tickets));
    }

    public function reset($keepSession = false) {
        $this->logger->debug("reset");
        $this->write_state = new RWState();
        $this->read_state  = new RWState();
        $this->next_read_state  = new RWState();
        $this->next_write_state = new RWState();
        $this->client_random = "";
        $this->server_random = "";
        $this->master_secret = "";
        if( $keepSession === true )
            return;
        $this->session = new Session();
    }

    public function ticketHandshake() {
        if (count($this->tickets) == 0)
            throw new \Exception("No tickets");

        $ticket = array_shift($this->tickets);
        $client_random = $this->generateTicketId();

        $pson = Utils::pson_encode(
            array(
                "type"          => "ticket", 
                "ticket_id"     => ByteBuffer::wrap($ticket["id"]),
                "client_random" => $client_random
            ),
            self::$dict
        );
        $this->logger->debug("send ticket handshake");
        
        $this->send($pson, ContentType::HANDSHAKE);
        $this->restore($ticket, $client_random);
    }

    public function ticketRequest($n = 1) {
        $this->send(
            Utils::pson_encode(array("type" => "ticket_request", "count" => $n), self::$dict),
            ContentType::HANDSHAKE
        );
    }
    
    public function ticketPrefixToBi($prefix) {
        $bi = new BigInteger($prefix, 256);
        return $bi->div(60 * 1000);
    }
    
    public function clearTicketsDb() {
        $curr = $this->ticketPrefixToBi($this->generateTicketPrefix());
        $past = $curr->sub(($this->config->getTicketsTTL() / 60) + 1);
        $dir = $this->config->getTicketsDirectory();
        $files = scandir($dir);
        foreach ($files as $file) {
            if (\io\privfs\core\Utils::endsWith($file, ".db")) {
                $fbi = new BigInteger(substr($file, 0, strlen($file) - 3));
                if ($past->cmp($fbi) > 0) {
                    $path = \io\privfs\core\Utils::joinPaths($dir, $file);
                    $this->logger->info("Remove old ticket db " . $path);
                    unlink($path);
                }
            }
        }
    }
    
    public function openTicketDatabase($prefix, $mode) {
        $dataDir = $this->config->getTicketsDirectory();
        if (!file_exists($dataDir)) {
            if (!mkdir($dataDir)) {
                $this->logger->debug("Cannot create tickets directory under " . $dataDir);
                throw new \Exception("Cannot create tickets directory");
            }
        }
        $this->clearTicketsDb();
        $div = $this->ticketPrefixToBi($prefix);
        $dbFile = $div->toDec() . ".db";
        $dbPath = \io\privfs\core\Utils::joinPaths($dataDir, $dbFile);
        return new \io\privfs\core\JsonDatabase($dbPath, $mode, hex2bin($this->config->getSymmetric()));
    }
    
    public function getCurrentTicketData() {
        return array(
            "session_id" => $this->session_id,
            "agent" => $this->client_agent,
            "master_secret" => ByteBuffer::wrap($this->master_secret),
        );
    }
    
    public function serializeTicketData($data) {
        return array(
            "session_id" => bin2hex($data["session_id"]),
            "agent" => $data["agent"],
            "master_secret" => bin2hex($data["master_secret"]->toBinary())
        );
    }
    
    public function deserializeTicketData($data) {
        return array(
            "session_id" => hex2bin($data["session_id"]),
            "agent" => $data["agent"],
            "master_secret" => ByteBuffer::wrap(hex2bin($data["master_secret"])),
        );
    }
    
    public function generateTicketPrefix() {
        $bi = \io\privfs\core\Utils::timeMili();
        $timestamp = \io\privfs\core\Utils::biTo64bit($bi);
        return substr($timestamp, 2);
    }
    
    public function generateTicketId($prefix = null) {
        return (is_null($prefix) ? $this->generateTicketPrefix() : $prefix) . openssl_random_pseudo_bytes(10);
    }
    
    public function generateTickets($count) {
        $ticketsIds = array();
        $ticketsDbIds = array();
        $prefix = $this->generateTicketPrefix();
        $ticketData = $this->getCurrentTicketData();
        for ($i = 0; $i < $count; ++$i) {
            $ticket_id = $this->generateTicketId($prefix);
            $this->tickets[$ticket_id] = array(
                "id" => $ticket_id,
                "data" => $ticketData
            );
            array_push($ticketsIds, ByteBuffer::wrap($ticket_id));
            array_push($ticketsDbIds, bin2hex($ticket_id));
        }
        $db = $this->openTicketDatabase($prefix, "w");
        array_push($db->data, array(
            "data" => $this->serializeTicketData($ticketData),
            "tickets" => $ticketsDbIds
        ));
        $db->close();
        return $ticketsIds;
    }
    
    public function saveTickets($tickets) {
        foreach ($tickets as $ticket_id) {
            $ticket_id = $ticket_id->toBinary();
            $this->tickets[$ticket_id] = array(
                "id" => $ticket_id,
                "data" => $this->getCurrentTicketData()
            );
        }
    }
    
    public function useTicket($ticket_id) {
        $this->logger->debug("using ticket", array("ticket_id" => bin2hex($ticket_id)));
        if (isset($this->tickets[$ticket_id])) {
            $ticket = $this->tickets[$ticket_id];
            unset($this->tickets[$ticket_id]);
            return $ticket;
        }
        if ($this->connection_end == ConnectionEnd::CLIENT) {
            return false;
        }
        $prefix = substr($ticket_id, 0, 6);
        $db = $this->openTicketDatabase($prefix, "w");
        $dbId = bin2hex($ticket_id);
        $j = false;
        $i = 0;
        foreach ($db->data as $_i => $entry) {
            $j = array_search($dbId, $entry["tickets"], true);
            if ($j !== false) {
                $i = $_i;
                break;
            }
        }
        $ticketData = null;
        if ($j !== false) {
            $ticketData = $db->data[$i]["data"];
            if (count($db->data[$i]["tickets"]) == 1) {
                array_splice($db->data, $i, 1);
            }
            else {
                array_splice($db->data[$i]["tickets"], $j, 1);
            }
        }
        $db->close();
        return is_null($ticketData) ? null : array(
            "id" => $ticket_id,
            "data" => $this->deserializeTicketData($ticketData)
        );
    }

    public function restore($ticket, $client_random) {
        $this->logger->debug("restore " . bin2hex($ticket["id"]) . " " . bin2hex($client_random));
        $data = (array)$ticket["data"];
        $this->client_random = $client_random;
        $this->server_random = $ticket["id"];
        $this->session_id = $data["session_id"];
        $this->client_agent = $data["agent"];
        $this->master_secret = $data["master_secret"]->toBinary();

        $rwstates = $this->getFreshRWStates($this->master_secret, $this->client_random, $this->server_random);
        $this->next_read_state  = $rwstates->read_state;
        $this->next_write_state = $rwstates->write_state;

        $this->changeCipherSpec();
    }

    public function changeCipherSpec() {
        if (!$this->next_write_state->initialized)
            throw new \Exception("Invalid next write state");
        $this->send("", ContentType::CHANGE_CIPHER_SPEC);
        $this->write_state = $this->next_write_state;
        $this->next_write_state = new RWState();
    }

    private function getFreshRWStates($master_secret, $client_random, $server_random) {
        $key_block = Crypto::prf_tls12($master_secret, "key expansion" . $server_random . $client_random, 4*32);
        
        $this->logger->debug("new key material", array("key_block" => bin2hex(substr($key_block, 0, 16)) . "..."));
        
        $client_mac_key = substr($key_block, 0,  32);
        $server_mac_key = substr($key_block, 32, 32);
        $client_key = substr($key_block, 64, 32);
        $server_key = substr($key_block, 96, 32);

        if ($this->connection_end == ConnectionEnd::SERVER) {
            $read_state  = new RWState($client_key, $client_mac_key);
            $write_state = new RWState($server_key, $server_mac_key);
        } else {
            $read_state = new RWState($server_key, $server_mac_key);
            $write_state = new RWState($client_key, $client_mac_key);
        }
        return (object)array("read_state" => $read_state, "write_state" => $write_state);
    }

    public function setPreMasterSecret($pre_master_secret) {
        $client_random = $this->client_random;
        $server_random = $this->server_random;

        $master_secret = Crypto::prf_tls12($pre_master_secret, "master secret" . $client_random . $server_random, 48);
        $this->master_secret = $master_secret;

        $this->logger->debug("new master secret", array("master_secret" => bin2hex(substr($master_secret,0,16)) . "..."));

        $rwstates = $this->getFreshRWStates($master_secret, $client_random, $server_random);
        $this->next_read_state  = $rwstates->read_state;
        $this->next_write_state = $rwstates->write_state;
    }

    public function is_client_connection() {
        return $this->connection_end === ConnectionEnd::CLIENT;
    }

    public function is_server_connection() {
        return $this->connection_end === ConnectionEnd::SERVER;
    }

    private function validate_srp(array $frame)
    {
        $data = $this->session->get("srp_data", null);
        if( $frame["type"] === "srp_exchange" )
        {
            if( !isset($data["M2"]) || !isset($data["K"]) )
                throw new \Exception("Invalid handshake state");

            if( $data["M2"]->cmp(new BigInteger($frame["M2"], 16)) )
            {
                $M2 = $frame["M2"];
                $expected = $data["M2"]->toHex();
                throw new \Exception("Invalid M2 - {$M2}, expected {$expected}");
            }

            $K = \io\privfs\core\Utils::fillTo32($data["K"]);
            $this->setPreMasterSecret($K);

            // flush old tickets
            $this->tickets = array();
            $this->saveTickets($frame["tickets"]);
            return null;
        }

        // srp init - server response
        if( !isset($data["I"]) || !isset($data["password"]) )
            throw new \Exception("Invalid handshake state");

        $tickets = isset($data["tickets"]) ? $data["tickets"] : 1;
        $s = new BigInteger($frame["s"], 16);
        $B = new BigInteger($frame["B"], 16);
        $N = new BigInteger($frame["N"], 16);
        $g = new BigInteger($frame["g"], 16);
        $k = new BigInteger($frame["k"], 16);
        $loginData = (array)json_decode($frame["loginData"]);

        if( !SrpLogic::valid_B($B, $N) )
        {
            $B = $frame["B"];
            $N = $frame["N"];
            throw new \Exception("Invalid B - {$B}, N: {$N}");
        }

        $a = SrpLogic::get_small_a();
        $A = SrpLogic::get_A($g, $N, $a);
        $P = $this->password_mixer->mix($data["password"], $loginData);
        $x = SrpLogic::get_x($s, $data["I"], base64_encode($P));
        $v = SrpLogic::get_v($g, $N, $x);
        $u = SrpLogic::get_u($A, $B, $N);
        $S = SrpLogic::getClient_S($B, $k, $v, $a, $u, $x, $N);
        $M1 = SrpLogic::get_M1($A, $B, $S, $N);
        $this->logger->debug("Client M1", array(
            "A" => $A->toHex(),
            "B" => $B->toHex(),
            "S" => $S->toHex(),
            "N" => $N->toHex()
        ));

        $M2 = SrpLogic::get_M2($A, $M1, $S, $N);
        $K = SrpLogic::get_big_K($S, $N, true);
        $this->session->save("srp_data", array("M2" => $M2, "K" => $K));

        return array(
            "type" => "srp_exchange",
            "A" => $A->toHex(),
            "M1" => $M1->toHex(),
            "sessionId" => $frame["sessionId"],
            "tickets" => $tickets
        );
    }

    public function getSessionId() {
        return $this->session_id;
    }
}

?>
