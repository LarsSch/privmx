
<?php
/*************************************************************
This file is distributed under the PrivMX Web Freeware License
See LICENSE.TXT in the main folder of the software package
or https://privmx.com/web-freeware-license
Copyright (c) 2017 Simplito Sp. z o.o.
**************************************************************/

  if (empty($_GET["token"])) {
    die("Missing token");
  }
  
  require_once __DIR__ . "/../vendor/autoload.php";
  
  use io\privfs\core\Utils;
  
  $ioc = new io\privfs\data\IOC(false);
  
  $tokenData = $ioc->getSecureForm()->getTokenData($_GET["token"]);
  if (!$tokenData) {
    die("Invalid token");
  }
  $sid = $tokenData["sid"];
  $sinkService = $ioc->getSink();
  $sink = $sinkService->sinkGet($sid);
  $verifyEmail = $sinkService->sinkNeedEmailVerification($sink);
  
  $config = $ioc->getConfig();
  
  $i18n = array(
    "pl" => array(
      "title" => "FormTest",
      "form.legend" => "Formularz kontaktowy",
      "form.fields.firstName.label" => "Imię",
      "form.fields.lastName.label" => "Nazwisko",
      "form.fields.email.label" => "E-mail",
      "form.fields.subject.label" => "Temat wiadomości",
      "form.fields.subject.options.sell" => "Sprzedaż",
      "form.fields.subject.options.buy" => "Kupno",
      "form.fields.subject.options.other" => "Inne",
      "form.fields.senderType.company.label" => "Firma",
      "form.fields.senderType.private.label" => "Osoba prywatna",
      "form.fields.message.label" => "Treść wiadomości",
      "form.button.label" => "Wyślij",
      "loading" => "Wysyłanie...",
      "about.text" => "To jest przykładowy, prosty formularz kontaktowy - sprawdź kod źródłowy tej strony",
      "button.close" => "Zamknij formularz testowy",
      "error" => "Błąd :(",
      "success.standard" => "OK :)",
      "success.verify" => "Wysłaliśmy na podany adres email link weryfikacyjny. Kliknij go, aby zakończyć proces wysyłania wiadomości."
    ),
    "en" => array(
      "title" => "FormTest",
      "form.legend" => "Contact form",
      "form.fields.firstName.label" => "First name",
      "form.fields.lastName.label" => "Last name",
      "form.fields.email.label" => "E-mail",
      "form.fields.subject.label" => "Message subject",
      "form.fields.subject.options.sell" => "Sell",
      "form.fields.subject.options.buy" => "Buy",
      "form.fields.subject.options.other" => "Other",
      "form.fields.senderType.company.label" => "Company",
      "form.fields.senderType.private.label" => "Private person",
      "form.fields.message.label" => "Message body",
      "form.button.label" => "Send",
      "loading" => "Sending...",
      "about.text" => "This is an example, simple contact form - check the source code of this page",
      "button.close" => "Close example form",
      "button.close" => "Zamknij formularz testowy",
      "error" => "ERROR :(",
      "success.standard" => "OK :)",
      "success.verify" => "We have just sent a verification link at given email address. Please click it to finish message sending process."
    )
  );
  
  $languageDetector = new \io\privfs\core\LanguageDetector("en", array("en", "pl"));
  $lang = $languageDetector->detectFromGet();
  
  $t = function ($id) use ($lang, $i18n) {
    return isset($i18n[$lang][$id]) ? $i18n[$lang][$id] : $id;
  };
  
  $clientScriptUrl = Utils::concatUrl($config->getInstanceUrl(), "secure-form/assets.php?f=privmx-client");
  $clientScriptUrl = preg_replace("(^https?://)", "//", $clientScriptUrl);
  $host = $config->getHosts()[0];
?>
<!doctype html>
<html lang="<?= $lang ?>">
<head>
  <meta charset="utf-8">
  <title><?= $t("title") ?></title>
  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }
    body {
      font-size: 14px;
      text-align: center;
      font-family: sans-serif;
      background-color: #eee;
    }
    form {
      width: 400px;
      display: block;
      margin: 20px auto;
      text-align: left;
    }
    label,
    input[type=text],
    textarea,
    select {
      display: block;
      width: 100%;
    }
    form div {
      margin: 0 0 20px 0;
    }
    fieldset {
      background-color: white;
      padding: 10px;
    }
    form button {
      padding: 5px 10px;
      display: block;
      margin: 0 auto;
      width: 100px;
    }
    textarea {
      height: 100px;
    }
    button.close-btn {
      margin: 20px 0;
    }
    #loading {
      display: none;
      position: absolute;
      top: 200px;
      text-align: center;
      width: 500px;
      padding: 50px;
      left: 50%;
      margin-left: -250px;
      background-color: #fff;
      border: 1px solid #ddd;
    }
    #loading.show {
      display: block;
    }
  </style>
</head>
<body>
  <div id="loading">
    <?= $t("loading") ?>
  </div>
  <form name="contact" method="post" action="javascript:;">
    <fieldset>
      
      <legend><?= $t("form.legend") ?></legend>
      
      <div>
        <label><?= $t("form.fields.firstName.label") ?></label>
        <input type="text" name="first-name" />
      </div>
      
      <div>
        <label><?= $t("form.fields.lastName.label") ?></label>
        <input type="text" name="last-name" />
      </div>
      
      <div>
        <label><?= $t("form.fields.email.label") ?></label>
        <input type="text" name="email" />  
      </div>
      
      <div>
        <label><?= $t("form.fields.subject.label") ?></label>
        <select name="subject">
          <option value="sell"><?= $t("form.fields.subject.options.sell") ?></option>
          <option value="buy"><?= $t("form.fields.subject.options.buy") ?></option>
          <option value="other"><?= $t("form.fields.subject.options.other") ?></option>
        </select>  
      </div>
      
      <div>
        <label>
          <input type="radio" name="sender-type" value="company" />
          <?= $t("form.fields.senderType.company.label") ?>
        </label>
        <label>
          <input type="radio" name="sender-type" value="private" />
          <?= $t("form.fields.senderType.private.label") ?>
        </label>
      </div>
      
      <div>
        <label><?= $t("form.fields.message.label") ?></label>
        <textarea name="message"></textarea>
      </div>
      
      <button id="submit-button" type="submit"><?= $t("form.button.label") ?></button>
      
    </fieldset>
  </form>
  
  <p>
    <?= $t("about.text") ?>
  </p>
  <button class="close-btn" onclick="window.close()"><?= $t("button.close") ?></button>
  
  <script src="<?= $clientScriptUrl ?>"></script>
  
  <script>
    var loading = document.getElementById("loading");
    document.forms.contact.addEventListener("submit", function (event) {
      if (!window.privmx) {
        alert("PrivMX not found!");
        return
      }
      loading.classList.add("show");
      var contactForm = event.currentTarget;
      window.privmx.send({
        host: "<?= $host ?>",
        sid: "<?= $sid ?>",
        form: contactForm,
        subject: "Test",
        <?= $verifyEmail ? "extra: JSON.stringify({email: contactForm.email.value}),\n" : "\n" ?>
        onSuccess: function() {
          loading.classList.remove("show");
          alert("<?= $t($verifyEmail ? "success.verify" : "success.standard") ?>");
          contactForm.reset();
        },
        onError: function() {
          loading.classList.remove("show");
          alert("<?= $t("error") ?>");
        }
      });
    });
  </script>

</body>
</html>
