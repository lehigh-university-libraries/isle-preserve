(function ($, Drupal) {
  Drupal.behaviors.islandoraCollectionTabs = {
    attach: function (context, settings) {
      $(once('control-view', '#block-lehigh-views-block-collection-tabs-block-1', context)).each(function () {
        if (!$('#tab-view-collection-tab').hasClass('active')) {
          $('#primary-content, #block-lehigh-browseitemssummary').each(function () {
            $(this).addClass('d-none');
          });
          $('.messages.messages--error').prependTo('.tab-pane.active');

        }

        $('#block-lehigh-views-block-collection-tabs-block-1 .nav-tabs a').on('click', function() {
          const elements = $('#primary-content, #block-lehigh-browseitemssummary')
          if ($(this).attr('id') == 'tab-view-collection-tab') {
            elements.removeClass('d-none');
          }
          else {
            elements.each(function () {
              if (!$(this).hasClass('d-none')) {
                $(this).addClass('d-none');
              }
            });
          }
        });
        function activateTabWhenReady() {
          const targetTab = $('a[data-bs-target="' + window.location.hash + '"]');

          if (targetTab.length && typeof targetTab.tab === 'function') {
            targetTab.tab('show');
          } else if (targetTab.length) {
            setTimeout(activateTabWhenReady, 50);
          }
        }

        activateTabWhenReady();

      });
    }
  };
})(jQuery, Drupal);
