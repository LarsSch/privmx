<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace simplito;

class FetchResult {
  
  private $location;
  private $config;
  
  public function __construct($location, $config)
  {
    $this->location = $location;
    $this->config = $config;
  }
  
  public function getLocation() {
    return $this->location;
  }
  
  public function getConfig() {
    return $this->config;
  }
  
}
