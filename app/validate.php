<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/
$i18nData = array(
    "pl" => array(
      "title" => "PrivMX WebMail",
      "subtitle" => "Szyfrowanie end-to-end",
      "subtitle.short" => "End-to-end",
      "success.header" => "Dziękuję",
      "success.info" => "Wiadomość została doręczona do",
      "error.header" => "Błąd",
      "invalid" => "Niepoprawny token"
    ),
    "en" => array(
      "title" => "PrivMX WebMail",
      "subtitle" => "End-to-end encryption",
      "subtitle.short" => "End-to-end",
      "success.header" => "Thank you",
      "success.info" => "Your message has been delivered to",
      "error.header" => "Error",
      "invalid" => "Invalid token"
    )
);
?><!DOCTYPE html>
<html lang="<?= $lang ?>">
  <head>
    <meta charset="utf-8">
    <meta http-equiv="cache-control" content="public, no-cache" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link href="../app/20180228133745-1.0/pages-assets/pure-release-1.0.0/pure-min.css" media="all" rel="stylesheet" />
    <link href="../app/20180228133745-1.0/pages-assets/pure-release-1.0.0/grids-responsive-min.css" media="all" rel="stylesheet" />
    <link href="../app/20180228133745-1.0/pages-assets/pmxtalk.css" media="all" rel="stylesheet" />
    <link href="../app/20180228133745-1.0/themes/default/css/fonts.css" media="all" rel="stylesheet" />
    <link href="../app/20180228133745-1.0/themes/default/css/font-awesome.min.css" media="all" rel="stylesheet" />
    <link rel="shortcut icon" href="favicon.ico" />
    <title><?= i18n("title") ?></title>
    <style type="text/css">
      #layout {
        max-width: 600px;
      }
      .new-msg-info {
        margin-bottom: 1.2em;
        color: #444;
        margin-top: 0.2em;
      }
      .new-msg-info-avatar {
        margin-left: 0;
      }
      .finish-icon {
        margin: 20px 0 10px 0;
        text-align: center;
        font-size: 40px;
        opacity: 0.3;
      }
      .lg-finish-verify .finish-icon {
        color: #999;
      }
      .lg-finish-success .finish-icon {
        color: #60ae1c;
      }
      .lg-finish-error .finish-icon {
        color: #d56401;
      }
      .info-ex {
        font-size: 16px;
        text-align: center;
        padding: 0 20px;
      }
      .finish-header {
        font-size: 16px;
        font-weight: bold;
        margin-bottom: 5px;
      }
    </style>
  </head>
  <body>
    <div id="layout" class="pure-g pmxlayout">
      <div class="header pure-u-1">
        <div class="pure-g">
          <div class="pure-u-14-24 todown">
            <div class="inner">
              <i class="fa fa-lock" aria-hidden="true"></i>
              <span class="full-header"><?= i18n("subtitle") ?></span>
              <span class="short-header"><?= i18n("subtitle.short") ?></span>
            </div>
          </div>
          <div class="pure-u-10-24 logo">
            <a href="https://privmx.com" target="_blank">
              <img src="../app/20180228133745-1.0/themes/default/images/logo-bl.png" />
            </a>
          </div>
        </div>
      </div>
      <?php if ($res === false) { ?>
      <div class="pure-u-1 lg-finish-msg lg-finish-error">
        <div class="finish-icon">
          <i class="fa fa-times-circle"></i>
        </div>
        <div class="info-ex">
          <div class="finish-header"><?= i18n("error.header") ?></div>
          <div><?= i18n("invalid") ?></div>
        </div>
      </div>
      <?php } else { ?>
      <div class="pure-u-1 lg-finish-msg lg-finish-success">
        <div class="finish-icon">
          <i class="fa fa-check-circle"></i>
        </div>
        <div class="info-ex">
          <div class="finish-header"><?= i18n("success.header") ?></div>
          <div><?= i18n("success.info") ?></div>
          <div>
            <span class="new-msg-info-avatar">
              <img class="lg-receiver-avatar" src="<?= $userInfo["imgUrl"] ? $userInfo["imgUrl"] : "../app/20180228133745-1.0/icons/user-default.png" ?>" />
            </span>
            <span class="lg-receiver-name">
              <?= $userInfo["displayName"] ? "<b>" . htmlspecialchars($userInfo["displayName"]) . "</b> <span class='hashmail'>&lt;" . $userInfo["hashmail"] . "&gt;</span>" : $userInfo["hashmail"] ?>
            </span>
          </div>
        </div>
      </div>
      <?php } ?>
    </div>
  </body>
</html>
