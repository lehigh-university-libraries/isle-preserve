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
