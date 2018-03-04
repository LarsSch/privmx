<?php
namespace GuzzleHttp;

class PromiseStep implements Iterator {
    
    private $isArray;
    private $iterable;
    private $client;
    private $opts;
    
    public function __construct($iterable, $client, $opts) {
        $this->iterable = $iterable;
        $this->isArray = is_array($this->iterable);
        $this->client = $client;
        $this->opts = $opts;
    }
    
    public static function create($iterable, $client, $opts) {
        if (\GuzzleHttp\Info::$IS_PHP_5_4) {
            return new PromiseStep($iterable, $client, $opts);
        }
        else {
            $requests = function () use ($iterable, $client, $opts) {
                foreach ($iterable as $key => $rfn) {
                    yield $key => PromiseStep::process($rfn, $client, $opts);
                }
            };
            return $requests();
        }
    }
    
    public function rewind() {
        if ($this->isArray) {
            reset($this->iterable);
        }
        else {
            $this->iterable->rewind();
        }
    }
    
    public function current() {
        $rfn = $this->isArray ? current($this->iterable) : $this->iterable->current();
        return $this->process($rfn, $this->client, $this->opts);
    }
    
    public function key()  {
        return $this->isArray ? key($this->iterable) : $this->iterable->key();
    }
    
    public function next()  {
        $rfn = $this->isArray ? next($this->iterable) : $this->iterable->next();
        return $this->process($rfn, $this->client, $this->opts);
    }
    
    public function valid() {
        if ($this->isArray) {
            $key = key($this->var);
            return $key !== NULL && $key !== FALSE;
        }
        return $this->iterable->valid();
    }
    
    public static function process($rfn, $client, $opts) {
        if ($rfn instanceof RequestInterface) {
            return $client->sendAsync($rfn, $opts);
        }
        else if (is_callable($rfn)) {
            return $rfn($opts);
        }
        else {
            throw new \InvalidArgumentException('Each value yielded by '
                . 'the iterator must be a Psr7\Http\Message\RequestInterface '
                . 'or a callable that returns a promise that fulfills '
                . 'with a Psr7\Message\Http\ResponseInterface object.');
        }
    }
}
