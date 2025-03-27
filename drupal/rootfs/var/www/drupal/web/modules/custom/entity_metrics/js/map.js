(function ($) {
    Drupal.behaviors.entityMetricsMap = {
      attach: function (context, settings) {
        $(window).once('entity_metrics_map').on('load', function () {
            zoomLevel = 1
            var map = L.map('map').setView([0, 0], zoomLevel);

            // Add a tile layer to the map
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: 'Map data &copy; <a href="https://www.openstreetmap.org/">OpenStreetMap</a> contributors',
                maxZoom: 18,
            }).addTo(map);
            var marker = NULL;
            setInterval(function () {
                if (drupalSettings.entityMetrics == 0) {
                  return;
                }
                if (marker != NULL) {
                  map.removeLayer(marker)
                }
                var v = drupalSettings.entityMetrics.pop()
                var pinLocation = [v.latitude, v.longitude];
                var pinMetadata = v.label;

                $('#map-header').html('<a href="' + v.link + '">' + v.label + '</a> from ' + v.city + ', ' + v.region + ' ' + v.country)

                // Create a marker with the pin location and bind the metadata
                marker = L.marker(pinLocation).addTo(map).bindPopup(pinMetadata);
            }, 5000);
        });
      }
    };
})(jQuery);
