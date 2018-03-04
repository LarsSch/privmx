<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace privmx\pki\keystore;

class AttachmentsStorage {
    
    public static function addAttachment($container, $fileName, $data) {
        $pointerIndex = -1;
        foreach ($container->attachmentPointerList as $i => $pointer) {
            if ($pointer->fileName == $fileName) {
                $pointerIndex = $i;
                break;
            }
        }
        if ($pointerIndex != -1) {
            $pointer = $container->attachmentPointerList[$pointerIndex];
            array_splice($container->attachmentPointerList, $pointerIndex, 1);
            $dataIndex = -1;
            foreach ($container->attachmentDataList as $i => $data) {
                if ($data->hash == $pointer->hash) {
                    $dataIndex = $i;
                    break;
                }
            }
            if ($dataIndex != -1) {
                array_splice($container->attachmentDataList, $dataIndex, 1);
            }
        }
        $algorithm = Algorithm::SHA256;
        $hash = Utils::hashWithAlgorithm($data, $algorithm);
        $attPointer = AttachmentPointerPacket::create($fileName, $hash, $algorithm);
        $attData = AttachmentDataPacket::create($hash, $data);
        array_push($container->attachmentPointerList, $attPointer);
        array_push($container->attachmentDataList, $attData);
    }
    
    public static function getAttachment($container, $fileName) {
        $pointer = null;
        foreach ($container->attachmentPointerList as $p) {
            if ($p->fileName == $fileName) {
                $pointer = $p;
                break;
            }
        }
        if ($pointer == null) {
            return null;
        }
        $data = null;
        foreach ($container->attachmentDataList as $d) {
            if ($d->hash == $pointer->hash) {
                $data = $d;
                break;
            }
        }
        return $data == null ? null : $data->data;
    }
    
    public static function validate($container) {
        foreach ($container->attachmentPointerList as $pointer) {
            if (!$pointer->validate() || strlen($pointer->hash) != Utils::hashLength($pointer->algorithm)) {
                return false;
            }
        }
        foreach ($container->attachmentDataList as $data) {
            $pointer = null;
            foreach ($container->attachmentPointerList as $p) {
                if ($p->hash == $data->hash) {
                    $pointer = $p;
                    break;
                }
            }
            if (!$data->validate() || $pointer == null || Utils::hashWithAlgorithm($data->data, $pointer->algorithm) != $data->hash) {
                return false;
            }
        }
        return true;
    }
    
    public static function isValidToSave($container) {
        if (!AttachmentsStorage::validate($container)) {
            return false;
        }
        foreach ($container->attachmentPointerList as $pointer) {
            foreach ($container->attachmentDataList as $data) {
                if ($pointer->hash == $data->hash) {
                    continue 2;
                }
            }
            return false;
        }
        return true;
    }
    
    public static function filterData($container, $includeAttachments) {
        if ($includeAttachments === true) {
            return $container->attachmentDataList;
        }
        if ($includeAttachments === false) {
            return array();
        }
        $res = array();
        foreach ($includeAttachments as $fileName) {
            $pointer = null;
            foreach ($container->attachmentPointerList as $p) {
                if ($p->fileName == $fileName) {
                    $pointer = $p;
                    break;
                }
            }
            if ($pointer == null) {
                continue;
            }
            $data = null;
            foreach ($container->attachmentDataList as $d) {
                if ($d->hash == $pointer->hash) {
                    $data = $d;
                    break;
                }
            }
            if ($data != null) {
                array_push($res, $data);
            }
        }
        return $res;
    }
}
