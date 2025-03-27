(function ($) {
    Drupal.behaviors.entityMetricsView = {
      attach: function (context, settings) {
        $(window).once('entity_metrics_view').on('load', function () {
            var components = drupalSettings.path.currentPath.split('/');
            var nodeId = components.pop()
            var entityType = components.pop()
            console.log('/entity-metrics/' + entityType + '/' + nodeId)
            $.ajax({
                url: '/entity-metrics/' + entityType + '/' + nodeId,
                success: function (response) {
                  if (response.total == 0) {
                    $('.block-entity-metrics').hide()
                  }
                  else {
                    $('#entity-metrics-monthly .value').text(response.monthly);
                    $('#entity-metrics-total .value').text(response.total);
                  }
                }
            });
        });
      }
    };
})(jQuery);
