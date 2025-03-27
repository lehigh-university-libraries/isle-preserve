const sidebarDefaultState = 'expanded';

(function ($, Drupal, once) {
  Drupal.behaviors.lehighGlobal = {
    attach: function (context, settings) {
      $(once('addOptions', '.view-attachment-tab-parent .view-header .views-attachment-tabs ul', context)).each(function () {
        $(this).html('<li><a class="vat-tab card_view vat-button-processed active" alt="Card View Icon" title="Card View"><span>Card View</span></a></li>');
        $(this).append('<li><a class="vat-tab list vat-button-processed" alt="List View Icon" title="List View"><span>List View</span></a></li>');
        //$(this).append('<li><a class="vat-tab masonry vat-button-processed" alt="Masonry View Icon" title="Masonry View"><span>Masonry View</span></a></li>');

        $('body').append('<a class="btn btn-floating btn-lg p-2" id="btn-back-to-top"><span class="material-symbols-outlined">keyboard_double_arrow_up</span></a>');
        let mybutton = document.getElementById("btn-back-to-top");

        // When the user scrolls down 20px from the top of the document, show the button
        window.onscroll = function () {
          scrollFunction();
        };

        function scrollFunction() {
          if (
            document.body.scrollTop > 100 ||
            document.documentElement.scrollTop > 100
          ) {
            mybutton.style.display = "block";
          } else {
            mybutton.style.display = "none";
          }
        }
        mybutton.addEventListener("click", backToTop);
        function backToTop() {
          document.body.scrollTop = 0;
          document.documentElement.scrollTop = 0;
        }

      $('.view-attachment-tab-parent .view-header .views-attachment-tabs ul li a').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $('.view-attachment-tab-parent .view-header .views-attachment-tabs ul li a').removeClass('active');
        $(this).addClass('active');

        let nodes = $('article.node--type-islandora-object');
        let vc = $('.view-attachment-tab');
        let r = $('.view-attachment-tab .rows');
        let rows = $('.view-attachment-tab .rows .views-row');
        if ($(this).hasClass('masonry')) {
          console.log('m')
        } else if ($(this).hasClass('list')) {
          nodes.removeClass('agileCard');
          nodes.removeClass('node--view-mode-card')
          vc.removeClass('view-attachment-tab-card-view card-view')
          r.addClass('list-group');
          r.removeClass('themed-grid');
          rows.addClass('list-group-item');
          nodes.addClass('node--view-mode-list card m-3');
          vc.addClass('view-attachment-tab-list-view list-view');
          $('article.node--type-islandora-object header').addClass('w-25 float-start');
          $('article.node--type-islandora-object .node--content').addClass('w-70 float-start ms-3');
          $('article.node--type-islandora-object footer').addClass('w-70 ml-25 position-absolute')
          $('article.node--type-islandora-object footer .reference-count').addClass('d-none');
          $('article.node--type-islandora-object footer .row.g3').addClass('ms-3');
        } else {
          nodes.addClass('node--view-mode-card agileCard');
          vc.addClass('view-attachment-tab-card-view card-view');
          r.removeClass('list-group');
          r.addClass('themed-grid');
          rows.removeClass('list-group-item');
          nodes.removeClass('node--view-mode-list card m-3');
          vc.removeClass('view-attachment-tab-list-view list-view');
          $('article.node--type-islandora-object header').removeClass('w-25 float-start');
          $('article.node--type-islandora-object .node--content').removeClass('w-70 float-start ms-3');
          $('article.node--type-islandora-object footer').removeClass('w-70 ml-25 position-absolute')
          $('article.node--type-islandora-object footer .reference-count').removeClass('d-none');
          $('article.node--type-islandora-object footer .row.g3').removeClass('ms-3');
        }
        return false;
      });
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
