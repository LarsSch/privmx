<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace Elliptic\Curve;

class PresetCurve
{
    public $curve;
    public $g;
    public $n;
    public $hash;

    function __construct($options)
    {
        if ( $options["type"] === "short" )
            $this->curve = new ShortCurve($options);
        elseif ( $options["type"] === "edwards" )
            $this->curve = new EdwardsCurve($options);
        else
            $this->curve = new MontCurve($options);

        $this->g = $this->curve->g;
        $this->n = $this->curve->n;
        $this->hash = isset($options["hash"]) ? $options["hash"] : null;

        //assert('$this->g->validate()'); //, "Invalid curve");
        //assert('$this->g->mul($this->n)->isInfinity()'); //, "Invalid curve, G*N != O");
    }
}

?>
