<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\protocol;

abstract class Utils
{

    public static function hexdump($data)
    {
        $result = "";
        $len  = strlen($data);
        $hlen = ($len % 16 == 0) ? $len : $len + 16 - ($len % 16); 
        for($i = 0; $i < $hlen; ++$i) {
            if ($i % 16 == 0)
                $result .= sprintf("%08x  ", $i);
            
            if ($i < $len)
                $result .= sprintf("%02x ", ord($data[$i]));
            else
                $result .= "   ";

            if ($i % 16 == 7) {
                $result .= " ";
            } elseif ($i % 16 == 15) {
                $result .= " |";
                for($j = $i - 15; $j <= $i && $j < $len; ++$j) {
                    if (ctype_graph($data[$j]))
                        $result .= $data[$j];
                    else
                        $result .= '.';
                }
                $result .= "|\n";
            }
        }
        $result .= sprintf("%08x", $len);
        return $result;
    }

    private static $encoders = array();
    public static function get_encoder($dict)
    {
        $key = implode($dict);
        if( !isset(self::$encoders[$key]) )
            self::$encoders[$key] = new \PSON\StaticPair($dict);

        return self::$encoders[$key];
    }

    public static function pson_encode($obj, $dict = array())
    {
        return self::get_encoder($dict)->encode($obj)->toBinary();
    }

    public static function pson_decode($bin, $dict = array())
    {
        return self::get_encoder($dict)->decode($bin);
    }
};

?>
