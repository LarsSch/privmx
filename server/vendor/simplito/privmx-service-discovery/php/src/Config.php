<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace simplito;


class Config {  
  static function camelize($input, $separator = '_') {
    return lcfirst(str_replace(' ', '', ucwords(str_replace($separator, ' ', $input))));
  }

  static function fromArray($data) {
    $config = array('ttl' => 600, 'defaultEndpoint' => null, 'version' => null);
    foreach($data as $key => $value) {
      $config[ Config::camelize($key) ] = $value;
    }
    return (object)$config;
  }

  static function fromJSON($data) {
    $parsed = json_decode($data, true);
    if (! $parsed) {
      throw new \Exception("No data");
    }
    return self::fromArray($parsed);
  }
}
