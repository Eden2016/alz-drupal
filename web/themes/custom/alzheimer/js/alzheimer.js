(function ($, Drupal) {

  'use strict';

  Drupal.alzheimer = Drupal.alzheimer || {};
  var NUMBER_OF_MENU_ITEMS = 7;
  var bodyFontSize = 100;

  Drupal.behaviors.alzheimerLoad = {
    attach: function (context) {
      var $context = $(context);
      
      $context.find('body').once('body').each(function () {
        $('#slider').flexslider({
            directionNav: false,
        });

        bodyFontSize = Drupal.alzheimer.getCookie("fontsize");
        if (bodyFontSize == 100 || bodyFontSize == 129 || bodyFontSize == 149) {
            jQuery('body').css('font-size', bodyFontSize + '%');
        }

        $('#small, #medium, #large').click(function (e) {
            e.preventDefault();
            bodyFontSize = $(this).data('size');

            $('body').css('font-size', bodyFontSize + '%');
            Drupal.alzheimer.Set_Cookie('fontsize', bodyFontSize, 30, '/', '', '');
        });
        
        Drupal.alzheimer.autoPadNav();
        
        $('#tabs').tabs();
        
      });
      
    }
  };
  
  Drupal.behaviors.entityBrowser = {
    attach: function (context) {
      var $context = $(context);
      
      $context.find('.view-entity-browser').once('eb').each(function () {
        $('.view-entity-browser .views-col').on('click', function (e) {
          $(this).find('input').prop('checked', true);
          $(this).parents('form').children('.form-actions').children('button').trigger('click');
        });
      });
    }
  };
  
  /*
  Drupal.behaviors.termTreeSettings = {
    attach: function (context) {
      var $context = $(context);
      
      $context.find('.term-reference-tree').once('trt').each(function () {
        $('.term-reference-tree .radio input[type="radio"]').on('click', function (e) {
          $(this).parents('.term-reference-tree').find('.selected').removeClass('selected');
          $(this).parent().addClass('selected');
        });
        //set first checked
        $('.term-reference-tree .radio input[type="radio"]:checked').parent().addClass('selected');
      });
      
    }
  };
  */
  
  Drupal.behaviors.customCKEditorConfig = {
    attach: function (context, settings) {
      if (typeof CKEDITOR !== "undefined") {
        CKEDITOR.config.autoParagraph = false;
        CKEDITOR.dtd.$removeEmpty['i'] = false;
        CKEDITOR.dtd.$removeEmpty['span'] = false;
        CKEDITOR.dtd.$removeEmpty['div'] = false;
        CKEDITOR.dtd.$removeEmpty['a'] = false;
        CKEDITOR.dtd.$removeEmpty['p'] = false;
      }
    }
  }
  
  Drupal.alzheimer.autoPadNav = function () {
    var w = $('ul.navbar-nav').parent().width();
    var cw = 0;

    $('ul.navbar-nav li').each(function () {
        cw += $(this).width();
    });

    var delta = w - cw;
    delta = delta / NUMBER_OF_MENU_ITEMS;
    delta = delta / 2;

    $('ul.navbar-nav li a').css('padding-left', delta + 'px').css('padding-right', delta + 'px');
  }

  //TODO check for usage, remove if not required
  Drupal.alzheimer.setCookie = function (c_name, value, exdays) {
    var exdate = new Date();
    exdate.setDate(exdate.getDate() + exdays);
    var c_value = escape(value) + ((exdays == null) ? "" : ";expires=" + exdate.toUTCString());
    document.cookie = c_name + "=" + c_value;
  }

  Drupal.alzheimer.getCookie = function (c_name) {
    var i, x, y, ARRcookies = document.cookie.split(";");
    for (i = 0; i < ARRcookies.length; i++) {
        x = ARRcookies[i].substr(0, ARRcookies[i].indexOf("="));
        y = ARRcookies[i].substr(ARRcookies[i].indexOf("=") + 1);
        x = x.replace(/^\s+|\s+$/g, "");
        if (x == c_name) {
            return unescape(y);
        }
    }
  }

  Drupal.alzheimer.Set_Cookie =  function (name, value, expires, path, domain, secure) {
    // set time, it's in milliseconds
    var today = new Date();
    today.setTime(today.getTime());

    /*
    if the expires variable is set, make the correct
    expires time, the current script below will set
    it for x number of days, to make it for hours,
    delete * 24, for minutes, delete * 60 * 24
    */
    if (expires) {
        expires = expires * 1000 * 60 * 60 * 24;
    }
    var expires_date = new Date(today.getTime() + (expires));

    document.cookie = name + "=" + escape(value) +
    ((expires) ? ";expires=" + expires_date.toGMTString() : "") +
    ((path) ? ";path=" + path : "") +
    ((domain) ? ";domain=" + domain : "") +
    ((secure) ? ";secure" : "");
  }
  
  // Skip to Section Begins 
  Drupal.alzheimer.appearFromLeft = function (id, cssPosition, functionToCall) {
    if (cssPosition == null)
        cssPosition = "absolute";

    id = "#" + id;

    $(id).css({ position: cssPosition, left: "-101%" }).removeClass("offscreen").removeClass("mobileOffscreen");

    $(id).animate({ left: '0px', position: cssPosition }, "100", function () {
        $(id).removeAttr("style");
        $(id).css("float", "left").css("position", "absolute");

        if (functionToCall != null)
            functionToCall();
    });
  }

  Drupal.alzheimer.goAwayToLeft = function (id, classToAdd, functionToCall) {
    $.when($("#" + id).animate({ left: '-100%' })).done(function () {

        if (classToAdd != null)
            $("#" + id).removeAttr("style").addClass(classToAdd);
        else
            $("#" + id).removeAttr("style").addClass("offscreen");

        if (functionToCall != null)
            functionToCall();
    });
  }

	Drupal.behaviors.searchBehavior = {
	  attach: function (context, settings) {
  		$('input#searchButton', context).once('searchingNow').each(function(){
  			$(this).click(function(){
  				$(this).parent().submit();
  			});
  		});
    }
	}

	Drupal.behaviors.searchBySocietyNameBehavior = {
    attach: function (context, settings) {
      $('#twocolumn_0_leftcolumn_1_cboLocation', context).once('searchBySocietyName').each(function(){
    		$("button.pull-right").click(function(){
          window.location.href=$('#twocolumn_0_leftcolumn_1_cboLocation').val();
  			});
  		});
    }
  }

  Drupal.behaviors.postalSearch = {
    attach: function (context, settings) {
      $('#twocolumn_0_leftcolumn_1_txtPostalCode', context).once('postalSearch').each(function(){
        $("#twocolumn_0_leftcolumn_1_btnPostalCodeGo").click(function(){
          $("#twocolumn_0_leftcolumn_1_txtPostalCode").css("background-color","rgb(255, 255, 255)");
          $("#search_results_wrapper").remove();
          var postalString = $("#twocolumn_0_leftcolumn_1_txtPostalCode").val();

          if(Drupal.alzheimer.postalValidate(postalString)) {
            $.get("/postal-search/search/"+postalString,function(data){
              var link = "<a href='"+data.link+"'>"+data.title+"</a>";
              $("#twocolumn_0_leftcolumn_1_updatePnl")
                .append("<div id='search_results_wrapper'><h4>Search Results</h4><div id='search_results'>"+link+"<br><br></div></div>");
            });
          } else {
            $("#twocolumn_0_leftcolumn_1_txtPostalCode").css("background-color","rgb(255, 99, 71)");
          }
        });
        $("#twocolumn_0_leftcolumn_1_txtPostalCode").on("keypress", function(e) {
          if (e.which == 13) {
            $("#twocolumn_0_leftcolumn_1_btnPostalCodeGo").click();
          }
        });
      });
    }
  }

  Drupal.behaviors.mobileHack = {
    attach: function (context, settings) {
      $('.mobileNav > ul', context).once('mobileHack').each(function(){
        $(".mobileNav > ul > li:nth-child(4)").click(function(){
          $('#navContainer').not(this).toggleClass('mobileOffscreen');
        });
        $(".mobileNav > ul > li:nth-child(2)").click(function(){
            $('#settings').not(this).toggleClass('mobileOffscreen');
        });
        $(".mobileNav > ul > li:nth-child(3)").click(function(){
            $('#searchLink').not(this).toggleClass('mobileOffscreen');
        });
      });
    }
  }

  Drupal.behaviors.quickLinks = {
    attach: function (context, settings) {
      $(".quick-links", context).once('quickLinks').each(function() {
        $(".quick-links").click(function () {
          if ($(".footer-nav").hasClass("mobileOffscreen"))
            $(".footer-nav").removeClass("mobileOffscreen").css("display", "none");

          // If the footer links is not visible, then make it visible
          if ($(".footer-nav").css("display") == "none")
          {
            // Remove the style added to the footer navigation once the animation is complete.
            $(".footer-nav").slideDown(function () { $(".footer-nav").removeAttr("style"); $(".footer-nav li:first-child").attr("style", "display: block !important;") });
            // Make the plus a minus
            $("#viewQuickLinks").removeClass("fa-plus").addClass("fa-minus");
          }
          else
          {
            // Hide the footer links and once the animation is complete, remove the style that was added to it and make it offscreen
            $(".footer-nav").slideUp(function (){
              $(".footer-nav").addClass("mobileOffscreen").removeAttr("style");
            });
            // Make the minus a plus
            $("#viewQuickLinks").addClass("fa-plus").removeClass("fa-minus");
          }
        });
      });
    }
  }

  Drupal.behaviors.newsFeatures = {
    attach: function (context, settings) {
      $("#newsFeatures", context).once('newsFeatures').each(function () {
        $("#newsTitle").click(function () {
            toggleNewsFeatures(true);
        });
        $("#featuresTitle").click(function () {
          toggleNewsFeatures(false);
        });
        $(".features").focus(function () {
          //the newsfeatures section exists and the news is showing, show the features
          if (document.getElementById("newsFeatures") != null && !$("#newsTitle").hasClass("notFocusedNews-Features"))
          {
            toggleNewsFeatures(false);
          }
        });
        $("#unfocusOnNewsFeatures").focus(function () {
          //the newsfeatures section exists and the news is hidden, show the news
          if (document.getElementById("newsFeatures") != null && $("#newsTitle").hasClass("notFocusedNews-Features"))
          {
            toggleNewsFeatures(true);
          }
        });
        $(".news").focus(function () {
          //the newsfeatures section exists and the news is hidden, show the news
          if (document.getElementById("newsFeatures") != null && $("#newsTitle").hasClass("notFocusedNews-Features"))
          {
            toggleNewsFeatures(true);
          }
        });
        function toggleNewsFeatures(newsOrFeaturesClicked)
        {
          // If Features was clicked and wasn't initially focused, focus on it
          if ($("#featuresTitle").hasClass("notFocusedNews-Features") && !newsOrFeaturesClicked)
          {
            // Add the "unfocused" look to the news tab
            $("#newsTitle").addClass("notFocusedNews-Features");
            // Hide the news content
            $("#newsContent").addClass("mobileOffscreen");

            // Remove the "unfocused" look from the features tab
            $("#featuresTitle").removeClass("notFocusedNews-Features");
            // Show the features content
            $("#featuresContent").removeClass("mobileOffscreen");
          }
          else
          {
            // If In the News is not focused and it was clicked, switch to it
            if ($("#newsTitle").hasClass("notFocusedNews-Features") && newsOrFeaturesClicked) {
              // Add the "unfocused" look to the features tab
              $("#featuresTitle").addClass("notFocusedNews-Features");
              // Hide the features content
              $("#featuresContent").addClass("mobileOffscreen");

              // Remove the "unfocused" look to the news tab
              $("#newsTitle").removeClass("notFocusedNews-Features");
              // Show the news content
              $("#newsContent").removeClass("mobileOffscreen");
            }
          }
        }
      });
    }
  }

  Drupal.alzheimer.postalValidate = function(postalString) {
    var regex = new RegExp(/^[ABCEGHJKLMNPRSTVXY]\d[ABCEGHJKLMNPRSTVWXYZ]( )?\d[ABCEGHJKLMNPRSTVWXYZ]\d$/i);
    if (regex.test(postalString)) {
      return true;
    } else {
      return false;
    }
  }

  /**
   * Ugly fix for bad bootstrap french links 
   */
  Drupal.behaviors.fixBootstrapLinks = {
    attach: function (context, settings) {
      $('div.table-responsive div.dropdown ul.dropdown-menu', context).once('fixBootstrapLinks').each(function () {
        $('div.table-responsive div.dropdown ul.dropdown-menu').each(function(){
          
          var editLinkLang = $(this).find("li a.hidden").prop("hreflang");
          var viewLinkLang = $(this).parents('tr').first().find('a').prop('hreflang');

          if(editLinkLang != viewLinkLang)
          {
            $(this).find("li a").each(function() {
              var editLinkURL = $(this).prop("href");
              editLinkURL = editLinkURL.replace("/"+editLinkLang+"/", "/"+viewLinkLang+"/");
              $(this).prop("href", editLinkURL);
              $(this).prop("hreflang", viewLinkLang);
            });
          }
        });
      });
    }
  };

  /**
   * fix for member list desination parameters not working
   */
  Drupal.behaviors.fixDestinationLinks = {
    attach: function (context, settings) {
      $('div.view-group-members.view-id-group_members', context).once('fixDestinationLinks').each(function () {
        //alert('hree');
        $('td[headers="view-dropbutton-table-column"] li a').each(function(){
          let url = $(this).attr("href");
          let new_url = url.slice(0,url.indexOf("?destination"));
          $(this).attr("href", new_url);
        });
      });
    }
  };

})(jQuery, Drupal);

