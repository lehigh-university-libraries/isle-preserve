const sidebarDefaultState = 'expanded';

(function ($, Drupal, once) {
  function addBackToTopButton() {
    $(once('browse-back-to-top', 'body')).each(function () {
      const $button = $('<button>', {
        'aria-label': Drupal.t('Back to top'),
        'class': 'btn btn-floating btn-lg p-2',
        'id': 'btn-back-to-top',
        'type': 'button',
      }).append(
        $('<span>', {
          'class': 'material-symbols-outlined',
          'text': 'keyboard_double_arrow_up',
        }),
      );

      $(this).append($button);

      const toggleButton = function () {
        $button.toggle(
          document.body.scrollTop > 100 ||
          document.documentElement.scrollTop > 100,
        );
      };

      window.addEventListener('scroll', toggleButton, { passive: true });
      $button.on('click', function () {
        document.body.scrollTop = 0;
        document.documentElement.scrollTop = 0;
      });
      toggleButton();
    });
  }

  Drupal.behaviors.lehighGlobal = {
    attach: function (context, settings) {
      $(once('browse-back-to-top', '.advanced-search-view', context)).each(function () {
        addBackToTopButton();
      });
      $(once('focus', '#toggle-main-nav-search')).on('click', function() {
        document.getElementById('main-nav-search-text').focus();
      });

      $(once('addClass', '.facets-widget-searchbox', context)).each(function () {
        $(this).addClass('form-control');
      });
      $(once('top-right-search', '#main-nav #views-exposed-form-browse-main', context)).first().each(function () {
        $(this).attr('action', '/browse');
      });

      $(once('style-email', '.contact-form #edit-mail', context)).first().each(function () {
        $('#edit-mail, #edit-message-0-value').addClass('form-control form-control-lg');
        var s = $('#edit-submit');
        s.addClass('btn btn-primary mt-3 w-25');
        s.removeClass('button button--primary');
        s.css('background-color', '#0d6efd');
      });

      once('browse-filter-sidebar', '#block-lehigh-exposedformbrowsemain', context).forEach(function (element) {
        const aside = $(element).closest('aside');
        const browserSection = aside.closest('section');

        if (aside.length > 0 && browserSection.length > 0) {
          browserSection.addClass('browser-' + sidebarDefaultState);
        }
        if (aside.length > 0 && !aside.hasClass('browser-sidebar')) {
          aside.addClass('browser-sidebar');
          browserSection.addClass('browser-section');
        }
      });

    }
  };

  Drupal.behaviors.processExposedFacetsFilterButton = {
    attach: function (context, settings) {
      once('processed', '.filter.control-icon', context).forEach(function (element) {

        $(element).on('click',function(){
          const browserSidebar = $('body').find('.browser-sidebar');
          browserSidebar.toggleClass('active');
        });
      })
    }
  };
})(jQuery, Drupal, once);
