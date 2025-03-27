

(function ($, Drupal, once) {
  Drupal.behaviors.selectElementProcessing = {
    attach: function (context, settings) {
      once('select-processed', 'select', context).forEach(function (element) {
        $(element).select2({
          width: 'element',
          minimumResultsForSearch: 40
        });
      });
    }
  };
})(jQuery, Drupal, once);
