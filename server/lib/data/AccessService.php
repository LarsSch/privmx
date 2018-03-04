<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\data;

class AccessService {
    
    private $user;
    
    public function __construct(User $user) {
        $this->user = $user;
    }
    
    private function validUser($username) {
        $user = $this->user->getUser2($username, null, false);
        return is_null($user) ? false : isset($user["activated"]) && $user["activated"];
    }
    
    public function canCreateSink($username) {
        return $this->validUser($username);
    }
    
    public function canModifyMessage($username) {
        return $this->validUser($username);
    }
    
    public function canCreateDescriptor($username) {
        return $this->validUser($username);
    }
}
