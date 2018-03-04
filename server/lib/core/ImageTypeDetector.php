<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

class ImageTypeDetector {
    
    static public function check($header, $data) {
        for ($i = 0; $i < count($header); $i++) {
            if ($header[$i] !== ord($data[$i])) {
                return false;
            }
        }
        return true;
    }
    
    static public function detect($data) {
        if (ImageTypeDetector::check(array(0xFF, 0xD8, 0xFF), $data)) {
            return array(
                "ext" => "jpg",
                "mime" => "image/jpeg"
            );
        }
        if (ImageTypeDetector::check(array(0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A), $data)) {
            return array(
                "ext" => "png",
                "mime" => "image/png"
            );
        }
        if (ImageTypeDetector::check(array(0x47, 0x49, 0x46), $data)) {
            return array(
                "ext" => "gif",
                "mime" => "image/gif"
            );
        }
        if (ImageTypeDetector::check(array(0x49, 0x49, 0x2A, 0x0), $data) || ImageTypeDetector::check(array(0x4D, 0x4D, 0x0, 0x2A), $data)) {
            return array(
                "ext" => "tif",
                "mime" => "image/tiff"
            );
        }
        if (ImageTypeDetector::check(array(0x42, 0x4D), $data)) {
            return array(
                "ext" => "bmp",
                "mime" => "image/bmp"
            );
        }
        return null;
    }
    
    static public function isValid($data) {
        return ImageTypeDetector::detect($data) !== null;
    }
}
