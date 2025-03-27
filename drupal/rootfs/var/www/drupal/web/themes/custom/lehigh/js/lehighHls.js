(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.lehighHls = {
    attach: function (context, settings) {
      if (typeof drupalSettings.videoUrl !== 'undefined') {
        var video = document.getElementById('video');
        if (Hls.isSupported()) {
          var hls = new Hls();
          hls.loadSource(drupalSettings.videoUrl);
          hls.attachMedia(video);
        }
        // HLS.js is not supported on platforms like iOS, where native HLS is supported
        else if (video.canPlayType('application/vnd.apple.mpegurl')) {
          video.src = drupalSettings.videoUrl;
        }
      } else if (typeof drupalSettings.audioUrl !== 'undefined') {
        var audio = document.getElementById('audio');
        if (Hls.isSupported()) {
          var hls = new Hls();
          hls.loadSource(drupalSettings.audioUrl);
          hls.attachMedia(audio);
        }
        // HLS.js is not supported on platforms like iOS, where native HLS is supported
        else if (audio.canPlayType('application/vnd.apple.mpegurl')) {
          audio.src = drupalSettings.audioUrl;
        }
      }

    }
  };
})(jQuery, Drupal, drupalSettings)
