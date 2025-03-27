/**
 *  @file identifyExposedFacetsSidebar.js
 *  @description Adds a class to an aside if the sidebar contains an exposed
 *  facet block. This inference will help the mobile UI identify a filters
 *  sidebar.
 */

const sidebarDefaultState = 'expanded';

(function ($, Drupal, once) {
  Drupal.behaviors.processExposedFacetsSidebar = {
    attach: function (context, settings) {
      once('facet-block-sidebar', 'aside .block-facets', context).forEach(function (element) {
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
         aside.prepend("<h2 class='alt'>Filter&nbsp;by</h2>");

         const uiControlLabels = {
           expanded: 'Hide filters',
           collaspsed: 'Show filters'
         };

         // Width of sidebar is determined by a root variable, tying to the stylesheet and
         // the general config file (rather than using a calculated width)

         const sidebarWidth = getComputedStyle(document.documentElement).getPropertyValue('--exposed-facet-width');

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

         let uiControls = $("<div class='sidebar-controls " + sidebarDefaultState + "'><button></button></div>");
          uiControls.find('button')
           .text(uiControlLabels[sidebarDefaultState])
           .on('click',function(){
             if(uiControls.hasClass('expanded')) {

               // collapse sidebar
               aside.children().fadeOut(window.heartbeat * 0.5); // hide facets as part of animation
               aside.css('width',0) // transition managed by css
               uiControls.removeClass('expanded');
               $(this).text(uiControlLabels.collaspsed);

               const browserSection = aside.closest('section');

               if (browserSection.length > 0) {
                 browserSection.removeClass('browser-expanded');
                 browserSection.addClass('browser-collapsed');
               }

               resizeMasonry();

             } else {
               // expand sidebar
               aside.css('width',sidebarWidth);
               aside.children().fadeIn(window.heartbeat * 0.5); // reveal facets as part of animation
               uiControls.addClass('expanded');
               $(this).text(uiControlLabels.expanded);

               const browserSection = aside.closest('section');

               if (browserSection.length > 0) {
                 browserSection.removeClass('browser-collapsed');
                 browserSection.addClass('browser-expanded');
               }

               resizeMasonry();
             }
           });

          // Hide filters not available in this build
         aside.before(uiControls);

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
