(function ($, Drupal) {

  'use strict';

  Drupal.zaboutme = Drupal.zaboutme || {};

  Drupal.behaviors.zaboutmeLoad = {
    attach: function (context) {
      var $windowWidth = document.documentElement.clientWidth || document.body.clientWidth;
      var $context = $(context);
      var $container = $context.find('body');
      
      $container.once('body').each(function () {
        /**
         * Main menu hover effect
         */
        $(".nav li.expanded").hover(
          function(){
            $(this).addClass("open");
          },function(){
            $(this).removeClass("open");
          }
        );
        $(".nav li.expanded, .nav li.expanded a").unbind("click");

        /**
         * Window resize and load actions
         */
        $(window).on('resize load',function() {
          $windowWidth = document.documentElement.clientWidth || document.body.clientWidth;
          Drupal.zaboutme.setupEqualHeights($windowWidth);
        });
        
        $('.comment-form textarea').autoGrow();
      });
    }
  };
  
  /**
   * Make items for selector equal heights
   *
   */
  Drupal.zaboutme.setupEqualHeights = function(windowWidth) {
    //listing blocks with images that need to be vertically aligned
    $('.listing.equal-img').each(function() {
      var $items = $(this).find(".img img");
      var $applyTo = $(this).find(".img");
      Drupal.zaboutme.equalHeights($items, windowWidth, 600, null, true, $applyTo);
    });
    
    $('.view.equal-height').each(function() {
      var $items = $(this).find(".item");
      Drupal.zaboutme.equalHeights($items, windowWidth, 480);
    });
  }
  
  /**
   * Make items equal heights
   *
   */
  Drupal.zaboutme.equalHeights = function(items, windowWidth, minWindow, maxWindow, valign, applyTo) {
    var height = 0;
    items.css('height', 'auto');  //always reset the height
    
    if (!maxWindow) maxWindow = 0;

    if (windowWidth >= minWindow && (maxWindow == 0 || windowWidth < maxWindow))
    {
      items.each(function() {
        if($(this).innerHeight() > height) {
          height = $(this).innerHeight();
        }
      });
      if (applyTo)
      {
        applyTo.css('height', height);
        if (valign) applyTo.css('line-height', height + 'px');
      }
      else {
        items.css('height', height);
        if (valign) items.css('line-height', height + 'px');
      }
    }

  }

})(jQuery, Drupal);