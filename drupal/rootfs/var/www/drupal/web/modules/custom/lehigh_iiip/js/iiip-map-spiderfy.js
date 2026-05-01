(function ($, Drupal) {
  "use strict";

  Drupal.behaviors.lehighIiipMapSpiderfy = {
    attach: function (context) {
      if (!Drupal.geolocation || !Drupal.geolocation.maps) {
        return;
      }

      $.each(Drupal.geolocation.maps, function (_index, map) {
        if (!map || map.type !== "google_maps" || map.__iiipSpiderfyBound) {
          return;
        }

        map.__iiipSpiderfyBound = true;

        var bindMarker = function (marker) {
          if (!marker || marker.__iiipSpiderfyBound) {
            return;
          }

          marker.__iiipSpiderfyBound = true;

          marker.addListener("click", function () {
            var icon = marker.getIcon();
            var iconUrl =
              typeof icon === "string"
                ? icon
                : icon && typeof icon.url === "string"
                  ? icon.url
                  : "";

            if (iconUrl.indexOf("marker-plus.svg") === -1) {
              return;
            }

            if (marker.__iiipSpiderfyZooming) {
              marker.__iiipSpiderfyZooming = false;
              return;
            }

            var currentZoom = map.googleMap.getZoom() || 0;
            var maxZoom =
              (map.settings &&
                map.settings.google_map_settings &&
                map.settings.google_map_settings.maxZoom) ||
              20;
            var targetZoom = Math.min(maxZoom, Math.max(currentZoom + 2, 5));

            if (targetZoom <= currentZoom) {
              return;
            }

            marker.__iiipSpiderfyZooming = true;
            google.maps.event.addListenerOnce(map.googleMap, "idle", function () {
              google.maps.event.trigger(marker, "click");
            });
            map.googleMap.panTo(marker.getPosition());
            map.googleMap.setZoom(targetZoom);
          });
        };

        $.each(map.mapMarkers || [], function (_markerIndex, marker) {
          bindMarker(marker);
        });

        map.addMarkerAddedCallback(function (marker) {
          bindMarker(marker);
        }, true);
      });
    }
  };
})(jQuery, Drupal);
