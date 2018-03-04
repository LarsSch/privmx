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
      "alert.ok" => "OK",
      "sending" => "Wysyłanie...",
      "header" => "Nowa wiadomość do",
      "form.subject" => "Tytuł wiadomości",
      "form.body" => "Twoja wiadomość...",
      "form.attachments" => "Załączniki",
      "form.attachments.add" => "Dodaj załącznik",
      "form.attachments.delete" => "Usuń",
      "form.email" => "Twój adres email",
      "form.email.info1" => "Otrzymasz email z linkiem weryfikacyjnym do kliknięcia.",
      "form.email.info2" => "Na podany adres będą wysyłane powiadomienia o odpowiedzi.",
      "form.password" => "Ustal hasło dostępu",
      "form.password.info" => "Hasło jest wymagane, aby zabezpieczyć komunikację end-to-end. Zapamiętaj je.",
      "form.password.show" => "Pokaż hasło",
      "form.password.hide" => "Ukryj hasło",
      "form.send" => "Wyślij",
      "error" => "Błąd",
      "error.missingPrivmx" => "Nie znaleziono PrivMX!",
      "success.header" => "Dziękuję",
      "success.info" => "Wiadomość została doręczona do",
      "success.verify" => "Sprawdź skrzynkę {0} i kliknij link potwierdzający, aby dostarczyć wiadomość."
    ),
    "en" => array(
      "title" => "PrivMX WebMail",
      "subtitle" => "End-to-end encryption",
      "subtitle.short" => "End-to-end",
      "alert.ok" => "OK",
      "sending" => "Sending...",
      "header" => "New message to ",
      "form.subject" => "Message subject",
      "form.body" => "Your message...",
      "form.attachments" => "Attachments",
      "form.attachments.add" => "Add attachment",
      "form.attachments.delete" => "Delete",
      "form.email" => "Your email address",
      "form.email.info1" => "You will receive an email with a verification link to click.",
      "form.email.info2" => "Notifications about replay will be send at given email address.",
      "form.password" => "Set a password",
      "form.password.info" => "Password is required to end-to-end secure the communication. Remember it.",
      "form.password.show" => "Show password",
      "form.password.hide" => "Hide password",
      "form.send" => "Send",
      "error" => "Error",
      "error.missingPrivmx" => "PrivMX not found!",
      "success.header" => "Thank you",
      "success.info" => "Your message has been delivered to",
      "success.verify" => "Please check the {0} mailbox and click confirmation link to deliver the message."
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
      h2 {
        margin: 0;
      }
      label {
        font-weight: bold;
      }
      input, textarea {
        width: 100%;
      }
      textarea {
        height: 6em;
      }
      #loading {
        text-align: center;
        font-size: 16px;
      }
      #loading .alert-modal-content {
        padding-bottom: 30px;
      }
      .form-group {
        margin-bottom: 10px;
      }
      .main-container {
        padding-bottom: 50px;
      }
      .info {
        font-size: 14px;
        color: #999;
      }
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
      .form-sub-section {
        margin-top: 20px;
      }
      .pure-form .main-btn-conatiner button[type=submit] {
        margin-top: 20px;
      }
      @media (min-width: 380px) {
        .aligned-form {
          margin-top: 20px;
          padding-top: 20px;
          border-top: 1px solid #ddd;
        }
        .aligned-form .aligned-label {
          float: left;
          width: 150px;
          display: table;
          text-align: right;
        }
        .aligned-form .aligned-label label {
          vertical-align: middle;
          height: 36px;
          display: table-cell;
        }
        .aligned-form .aligned-value {
          margin-left: 160px;
          display: block;
        }
        .main-btn-conatiner {
          margin-top: 20px;
          padding-top: 20px;
          border-top: 1px solid #ddd;
        }
        .pure-form .main-btn-conatiner button[type=submit] {
          margin-top: 0;
        }
        .pure-control-group {
          margin-bottom: 10px;
        }
      }
      input[name=email] {
        width: 200px;
      }
      .pure-form .password-input-line input {
        width: 200px;
        display: inline-block;
        margin-right: 5px;
      }
      .password-input-line .show-hide-password {
        font-size: 14px;
        white-space: nowrap;
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
    <div class="alert-modal hide" id="alert">
      <div class="alert-modal-content">
        <div class="msg" id="alert-message">
        </div>
        <div class="buttons-container">
          <button class="pure-button pure-button-primary lg-close-alert" id="alert-close-btn">
            <?= i18n("alert.ok") ?>
          </button>
        </div>
      </div>
    </div>
    <div class="alert-modal hide" id="loading">
      <div class="alert-modal-content">
        <div class="msg">
          <?= i18n("sending") ?>
        </div>
      </div>
    </div>
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
      <?php if ($emailVerification) { ?>
        <div class="pure-u-1 lg-finish-msg lg-finish-verify hide">
          <div class="finish-icon">
            <i class="fa fa-clock-o"></i>
          </div>
          <div class="info-ex">
          </div>
        </div>
      <?php } else { ?>
        <div class="pure-u-1 lg-finish-msg lg-finish-success hide">
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
      <div class="pure-u-1 lg-form">
        <div class="container main-container">
          <h2><?= i18n("header") ?></h2>
          <div class="new-msg-info">
            <span class="new-msg-info-avatar">
              <img class="lg-receiver-avatar" src="<?= $userInfo["imgUrl"] ? $userInfo["imgUrl"] : "../app/20180228133745-1.0/icons/user-default.png" ?>" />
            </span>
            <span class="lg-receiver-name">
              <?= $userInfo["displayName"] ? "<b>" . htmlspecialchars($userInfo["displayName"]) . "</b> <span class='hashmail'>&lt;" . $userInfo["hashmail"] . "&gt;</span>" : $userInfo["hashmail"] ?>
            </span>
          </div>
          <form name="contact" class="pure-form" autocomplete="off">
            <div class="form-group">
              <input type="text" name="subject" required="required" placeholder="<?= i18n("form.subject") ?>" />
            </div>
            <div class="form-group">
              <textarea name="text" required="required" placeholder="<?= i18n("form.body") ?>" /></textarea>
            </div>
            <div class="form-group">
              <div class="lg-attachments">
              </div>
              <div class="add-attachments-container">
                <button type="button" class="pure-button pure-button-small2 lg-add-attachments-btn">
                  <i class="fa fa-paperclip"></i>
                  <?= i18n("form.attachments.add") ?>
                </button>
              </div>
            </div>
            <div class="aligned-form form-sub-section">
              <div class="pure-control-group">
                <div class="aligned-label">
                  <label><?= i18n("form.email") ?></label>
                </div>
                <div class="aligned-value">
                  <input type="email" name="email" required="required" autocomplete="off" />
                  <div class="info" id="email-info">
                    <?= i18n($emailVerification ? "form.email.info1" : "form.email.info2") ?>
                  </div>
                </div>
              </div>
              <div class="pure-control-group">
                <div class="aligned-label">
                  <label><?= i18n("form.password") ?></label>
                </div>
                <div class="aligned-value">
                  <div class="password-input-line">
                    <input type="password" name="password" required="required" autocomplete="off" />
                    <a href="javascript:void(0)" class="show-hide-password"><?= i18n("form.password.show") ?></a>
                  </div>
                  <div class="info">
                    <?= i18n("form.password.info") ?>
                  </div>
                </div>
              </div>
            </div>
            <div class="main-btn-conatiner">
              <button type="submit" class="pure-button pure-button-primary">
                <i class="fa fa-paper-plane-o"></i>
                <?= i18n("form.send") ?>
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <script type="view-template" id="attachment-template">
      <div class="lg-attachment" data-id="{{@model.id}}">
        <button class="pure-button pure-button-xsmall pure-button-error lg-attachment-delete" title="<?= i18n("form.attachments.delete") ?>">
          <i class="fa fa-times text-danger"></i>
        </button>
        <div class="att-name">
          <i class="fa {{@model.icon}}"></i>
          {{@model.name}}
        </div>
      </div>
    </script>
    <script type="text/javascript" src="../server/secure-form/assets.php?f=privmx-client"></script>
    <script type="text/javascript" src="../app/20180228133745-1.0/build/web-lite.js"></script>
    <script type="text/javascript">
      var LANG = "<?= $lang ?>";
      var I18N_DATA = {
<?php
foreach ($i18nData[$lang] as $key => $value) {
echo("        \"$key\": \"$value\",\n");
}
?>
      };
      var WebLite = webLiteRequire("web-lite");
      var $ = WebLite.$;
      $(document).ready(function() {
        function i18n(id) {
            return id in I18N_DATA ? I18N_DATA[id] : id;
        }
        var manager = new WebLite.Manager();
        manager.helper = new WebLite.ViewHelper(this.manager);
        manager.helper.i18n = i18n;
        var attachmentTemplate = manager.createTemplateFromHtmlElement($("#attachment-template"));
        var attachments = {};
        var attachmentId = 0;
        function openFiles(cb) {
          var $input = $('<input type="file" multiple="multiple" style="height:0;display:block;margin:0;"/>');
          $input.on("change", function(event) {
            let files = event.target.files;
            if (files.length) {
              cb(files);
            }
            $input.remove();
          });
          $("body").append($input);
          $input.trigger("click");
        }
        $(".lg-add-attachments-btn").click(function() {
          openFiles(function(files) {
            var $container = $(".lg-attachments");
            for (var i = 0; i < files.length; i++) {
              var x = files[i];
              var id = attachmentId++;
              attachments[id] = x;
              $container.append(attachmentTemplate.renderToJQ({
                id: id,
                name: x.name,
                icon: x.type.indexOf("image/") == 0 ? "fa-file-image-o" : "fa-file-o"
              }));
            }
          });
        });
        $("body").on("click", ".lg-attachment-delete", function(e) {
          var $attachment = $(e.target).closest(".lg-attachment");
          var id = $attachment.data("id");
          delete attachments[id];
          $attachment.remove();
        });
        $(".show-hide-password").click(function() {
          var $trigger = $(".show-hide-password");
          var $input = $("form input[name=password]");
          var hidden = $input.attr("type") == "password";
          $trigger.html(i18n(hidden ? "form.password.hide" : "form.password.show"));
          $input.attr("type", hidden ? "text" : "password");
        });
        
        var loading = document.getElementById("loading");
        function showAlert(msg) {
          document.getElementById("alert").classList.remove("hide");
          document.getElementById("alert-message").innerHTML = msg;
        }
        document.getElementById("alert-close-btn").addEventListener("click", function() {
          document.getElementById("alert").classList.add("hide");
        });
        document.forms.contact.addEventListener("submit", function (event) {
          event.preventDefault();
          if (!window.privmx) {
            alert("<?= i18n("error.missingPrivmx") ?>");
            return false;
          }
          var $inputs = $("form").find("button, input, textarea");
          var $submit = $("form button[type=submit]");
          $submit.find("i").attr("class", "fa fa-spin fa-circle-o-notch");
          $inputs.prop("disabled", true);
          var contactForm = event.currentTarget;
          var formData = window.privmx.collectFormData(contactForm);
          var files = Object.keys(attachments).map(function(key) { return attachments[key]; });
          window.privmx.send({
            host: location.hostname,
            sid: "<?= $sid ?>",
            data: formData.fields,
            files: files,
            subject: contactForm.subject.value,
            extra: JSON.stringify({email: contactForm.email.value, lang: "<?= $lang ?>"}),
            onSuccess: function() {
              $submit.find("i").attr("class", "fa fa-paper-plane-o");
              $inputs.prop("disabled", false);
              $(".lg-finish-msg.lg-finish-verify .info-ex").html("<?= i18n("success.verify") ?>".replace("{0}", "<b>" + manager.helper.escapeHtml(contactForm.email.value) + "</b>"));
              $(".lg-finish-msg").removeClass("hide");
              $(".lg-form").addClass("hide");
              contactForm.reset();
              attachments = {};
              $(".lg-attachments").html("");
            },
            onError: function(e) {
              console.log("Error", e);
              $submit.find("i").attr("class", "fa fa-paper-plane-o");
              $inputs.prop("disabled", false);
              showAlert("<?= i18n("error") ?>");
            }
          });
          return false;
        });
      });
    </script>
  </body>
</html>
