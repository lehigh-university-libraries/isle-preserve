(function ($) {
    Drupal.behaviors.entityMetrics = {
      attach: function (context, settings) {
        $(window).once('entity_metrics_record').on('load', function () {
          $.ajax({
            url: '/entity-metrics/visit',
            method: 'POST',
            data: {
              currentPath: drupalSettings.path.currentPath
            }
          });
        });
      }
    };
})(jQuery);
