/*!
 *  v
 * 
 * (c) 2024 Theme author *  License
 * https://github.com/stop14/stop14-themesystem-legacy] */

/**
 * lehighProcessBrowserToolbar.js
 * see Views Attachment Tabs module – viewsAttachmentTabs.js
 *
 */


(function ($, Drupal) {

  /** This is when the attachment tabs have been processed (see the Views
   *  See the Views Attachment Tabs module assets/viewsAttachmentTabs.js
   *  for the views-attachment-tabs-processed event
   */

  $(document).on('views-attachment-tabs-processed', function(e,selector) {
    const vat = $(selector).find('.views-attachment-tabs');
    const viewsHeader = vat.closest('header');
    const paginatorNav = viewsHeader.siblings('nav');

    viewsHeader.wrapAll('<div class="browser-ui">');
  })

  Drupal.behaviors.moveAttachmentTabs = {};
  Drupal.behaviors.moveAttachmentTabs.attach = function (context,settings) {
    // Stub function for now

  }
})(jQuery, Drupal);


(function($) {
  $(document).ready(function() {
    
    var mobileNavIcon = $('.menu-icon, .overlay-close');
    var mobileMenu = $('#modal-overlay');
    var searchIcon = $('.mobile-search, .search-close')
    var searchDropdown = $('#search-dropdown');
 
    mobileNavIcon.on('click', function () {
      mobileMenu.slideToggle("fast");
      searchDropdown.hide();
    });

    searchIcon.on('click', function () {
      searchDropdown.slideToggle("fast");
      mobileMenu.hide();
    });




  });    
})(jQuery);

(function($) {
  $(document).ready(function() {
    
    $('.vat-tab.card_view').attr('alt', 'Card View Icon');
    $('.vat-tab.card_view').attr('title', 'Card View');

    $('.vat-tab.list').attr('alt', 'List View Icon');
    $('.vat-tab.list').attr('title', 'List View');

    $('.vat-tab.masonry').attr('alt', 'Masonry View Icon');
    $('.vat-tab.masonry').attr('title', 'Masonry View');
    
  });    
})(jQuery);
