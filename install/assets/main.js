$(function(){
  
  if (window.CHECK_HTTPS) {
      var url = "https:" + document.location.host + document.location.pathname;
      $.ajax({
        cache: false,
        url: url + "?testHttps",
        dataType: 'jsonp',
        success: function(res) {
          if (res == "OK") {
              document.location = url + document.location.search;
          }
          else {
            init();
          }
        },
        error: function(e) {
          init();
        }
      });
  }
  else {
    init();
  }
  
  function init() {
    
    var AUTO_NEXT = window.AUTO_NEXT;
    var ANIMATION_SPEED = 400;
    
    function post(action) {
      return $.post("", {action: action});
    }
    
    function showTestsSection() {
      var $testsSection = $("#tests-section");
      $testsSection.slideDown(ANIMATION_SPEED);
      $('html, body').animate({
        scrollTop: $(document).height() * 2
      }, ANIMATION_SPEED);
    }
    
    if (AUTO_NEXT) {
      $("#start-section").find(".section-buttons").remove();
    }
    
    $("#logo").addClass("fade-in");
    setTimeout(function(){
      $("#sections").fadeIn(ANIMATION_SPEED);
    }, 500);
    
    $("#http-warning").find("button").on("click", function(){
      $("#http-warning").slideUp(ANIMATION_SPEED, function() {
        $("#start-section").slideDown(ANIMATION_SPEED, function() {
          if (AUTO_NEXT) {
            showTestsSection();
          }
        });
      });
    });
    
    if ($("body").hasClass("proto-https") && AUTO_NEXT) {
      showTestsSection();
    }
    
    $("#next-button").on("click", function(){
      window.location = "?next";
    });
    $("#accept-licence").change(function() {
        $("#install-button").prop("disabled", !this.checked);
    });
    
    var $installButton = $("#install-button");
    $installButton.one("click", function() {
      $installButton.attr("disabled", true);
      $installButton.html("<i class='icon-spinner animate-spin'></i> Installing...");
      var $confirmSection = $("#confirm-section");
      post("install").done(function(result){
        if (result.error) {
          $confirmSection.addClass("is-error");
          $confirmSection.find(".on-error .text").text(result.error);
        } else {
          $confirmSection.addClass("is-success");
          if (result.registrationUrl) {
            $("#register-button").attr("href", result.registrationUrl);
            $("#register-link").text(result.registrationUrl);
          } else {
            $(".register-info").remove();
          }
          if (result.loginUrl) {
            $("#login-button").attr("href", result.loginUrl);
          } else {
            $("#login-button").remove();
          }
        }
      }).fail(function() {
        $confirmSection.addClass("is-error");
        $confirmSection.find(".on-error .text").text("Some error occured. Please check your server logs.");
      }).always(function(){
        $(".hide-after-install").slideUp(ANIMATION_SPEED, function(){
          $confirmSection.fadeIn(ANIMATION_SPEED);
        })
      });
    });
    
  }
});
