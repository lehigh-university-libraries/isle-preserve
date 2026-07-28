const sidebarDefaultState = 'expanded';

(function ($, Drupal, once) {
  function setBrowseDisplay($view, display) {
    const activeDisplay = display === 'list' ? 'list' : 'card';

    $view.attr('data-browse-view', activeDisplay);
    $view.find('.browse-view-toggle__button').each(function () {
      const isActive = this.dataset.browseView === activeDisplay;
      $(this)
        .toggleClass('is-active', isActive)
        .attr('aria-pressed', isActive ? 'true' : 'false');
    });
  }

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
      $(once('browse-view-toggle', '.browse-results', context)).each(function () {
        const $view = $(this);
        const $header = $view.children('.view-header').first();

        if (!$header.length) {
          return;
        }

        const $toggle = $('<div>', {
          'aria-label': Drupal.t('Display options'),
          'class': 'browse-view-toggle',
          'role': 'group',
        });

        ['card', 'list'].forEach(function (display) {
          const label = display === 'card' ? Drupal.t('Card view') : Drupal.t('List view');
          $toggle.append(
            $('<button>', {
              'aria-label': label,
              'aria-pressed': 'false',
              'class': 'browse-view-toggle__button browse-view-toggle__button--' + display,
              'data-browse-view': display,
              'title': label,
              'type': 'button',
            }).append($('<span>', { 'class': 'visually-hidden', 'text': label })),
          );
        });

        $header.prepend($toggle);
        if (!$header.parent().hasClass('browser-ui')) {
          $header.wrap('<div class="browser-ui"></div>');
        }

        $toggle.on('click', '.browse-view-toggle__button', function () {
          setBrowseDisplay($view, this.dataset.browseView);
        });

        const defaultDisplay = $view.attr('data-default-view');
        setBrowseDisplay($view, defaultDisplay);
        addBackToTopButton();
      });
      $(once('focus', '#toggle-main-nav-search')).on('click', function() {
        document.getElementById('main-nav-search-text').focus();
      });

      $(once('addClass', '.facets-widget-searchbox', context)).each(function () {
        $(this).addClass('form-control');
      });
      $('option[value="search_api_relevance_DESC"]').text("Sort by Relevance");
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

      $(once('facet-click', '.facet-item')).on('click', function() {
        const $els = $(once('facet-clicked', 'body'));
        if (!$els.length) {
          return
        }
        var c = $(this).find('input[type="checkbox"]')
        if (c.attr('checked') != undefined) {
          c.removeAttr('checked')
        }
        else {
          c.attr('checked', 'checked')
          c.removeAttr('disabled')
        }
      })
      once('facet-block-sidebar', 'aside .block-facets', context).forEach(function (element) {
        if ($(window).width() > 1199) {
          setTimeout(function() {
            var delta = 70;
            if ($('#block-lehigh-collectiontasksblock').length) {
              delta = 105;
            }

            $('.browser-sidebar').css('top', '-' + ($('.block-facets-summary').height() - delta) + 'px')
          }, 1000)
        }
        const aside = $(element).closest('aside');
        const browserSection = aside.closest('section');
        const masonryObj = browserSection.find('.masonry > .container');

        // Add a class to the nearest section to indicate the sidebar state
        // in case required by other browser c  omponents.

        if (aside.length > 0 &  browserSection.length > 0) {
          browserSection.addClass('browser-' + sidebarDefaultState);
        }
        if (aside.length > 0 && !aside.hasClass('browser-sidebar')) {
         aside.addClass('browser-sidebar');
         aside.closest('section').addClass('browser-section');
         const uiControlLabels = {
           expanded: 'Hide filters',
           collaspsed: 'Show filters'
         };
         $('.block-facets-summary').first().prepend('<span class="alt">Filter by <a class="filter-toggle expanded">Hide filters</a></span>')

         // Masonry needs to be recalculated when sidebar state is changed.
         function resizeMasonry() {
           if (masonryObj.length > 0) {
             masonryObj.each(function () {
               if (typeof $(this).masonry === 'function') {
                 function resetMasonry() {
                   // Masonry recalculates on resize event
                   window.dispatchEvent(new Event('resize'));
                 }

                 // Wait for CSS animations to complete. This must be longer than the values set in the stylesheet.
                 // @todo: consider using a configuration variable here too for more precision.

                 window.setTimeout(resetMasonry, window.heartbeat * 2);
               }
             });

           }
         }

         // Add expand and collapse controls to the stylesheet.

         let uiControls = $("a.filter-toggle");
         uiControls.text(uiControlLabels[sidebarDefaultState])
           .on('click',function(){
             if(uiControls.hasClass('expanded')) {
               // collapse sidebar
               aside.fadeOut(window.heartbeat * 0.5);
               aside.css('width', '0')
               uiControls.removeClass('expanded');
               $(this).text(uiControlLabels.collaspsed);

               const browserSection = aside.closest('section');

               if (browserSection.length > 0) {
                 browserSection.removeClass('browser-expanded');
                 $('body').addClass('browser-collapsed');
                 browserSection.addClass('browser-collapsed');
               }

               resizeMasonry();

             } else {
               // expand sidebar
               aside.fadeIn(window.heartbeat * 0.5);
               aside.css('width', 'var(--exposed-facet-width)');
               uiControls.addClass('expanded');
               $(this).text(uiControlLabels.expanded);

               const browserSection = aside.closest('section');

               if (browserSection.length > 0) {
                 browserSection.removeClass('browser-collapsed');
                 $('body').removeClass('browser-collapsed');
                 browserSection.addClass('browser-expanded');
               }

               resizeMasonry();
             }

             return false;
           });
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
