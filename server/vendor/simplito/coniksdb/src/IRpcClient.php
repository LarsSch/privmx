<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
namespace privmx\pki;

interface IRpcClient
{
    /**
     * resolves with rpc result
     * rejects with rpc error or when connection error occurs
     * @return Promise
     */
    public function call($method, $params);
};

?>
