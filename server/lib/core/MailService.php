<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

namespace io\privfs\core;

use PHPMailer;

class MailService {
    
    private $settings;
    
    public function __construct(Settings $settings) {
        $this->settings = $settings;
    }
    
    public function send($from, $to, $subject, $message, $isHtml) {
        $mail = new PHPMailer();
        $mail->isMail();
        $mail->CharSet = "UTF-8";
        if (is_string($from)) {
            $mail->setFrom($from);
        }
        else {
            $mail->setFrom($from["email"], $from["name"]);
        }
        if (is_string($to)) {
            $mail->addAddress($to);
        }
        else {
            $mail->addAddress($to["email"], $to["name"]);
        }
        $mail->isHTML($isHtml);
        $mail->Subject = $subject;
        $mail->Body = $message;
        
        return $mail->send();
    }
}