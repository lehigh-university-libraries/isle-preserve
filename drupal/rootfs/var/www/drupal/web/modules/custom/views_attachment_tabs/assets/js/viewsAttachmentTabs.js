/**
 * @file
 * Manages the tab interface for the viewsAttachmentTabs module.
 **/
(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.viewsAttachmentTabs = {};
  Drupal.behaviors.viewsAttachmentTabs.attach = function (context, settings) {
    if (settings && settings.viewsAttachmentTabs && settings.viewsAttachmentTabs.tabGroup) {
      let tabGroup = settings.viewsAttachmentTabs.tabGroup;
      Object.keys(tabGroup || {}).forEach(function (i) {
        Drupal.attachmentTabGroup.instances[i] = new Drupal.viewsAttachmentTabs(tabGroup[i]);
      });
    }
  };
  Drupal.behaviors.viewsAttachmentTabs.detach = function (context, settings, trigger) {
    if (trigger === 'unload') {
      if (settings && settings.viewsAttachmentTabs && settings.viewsAttachmentTabs.tabGroup) {
        let tabGroup = settings.viewsAttachmentTabs.tabGroup;

        Object.keys(tabGroup || {}).forEach(function (i) {
          const selector = ".vat-parent-".concat(tabGroup[i].view_dom_id);
          if ($(selector, context).length) {
            delete Drupal.attachmentTabGroup.instances[i];
            delete settings.viewsAttachmentTabs[i];
          }
        });
      }
    }
  };

  Drupal.attachmentTabGroup = {};
  Drupal.attachmentTabGroup.instances = {}

  Drupal.viewsAttachmentTabs = function (settings) {
    let _this = this;
    const selector = ".vat-parent-".concat(settings.view_dom_id);
    this.$view = $(selector);

    updateAttachmentView = function (view)  {
      var views_parameters = Drupal.Views.parseQueryString(url);
      var views_arguments = Drupal.Views.parseViewArgs(url, "search");
      var views_settings = $.extend(
        {},
        Drupal.views.instances[views_dom_id].settings,
        views_arguments,
        views_parameters
      );
      var views_ajax_settings =
        Drupal.views.instances[views_dom_id].element_settings;
      views_ajax_settings.submit = views_settings;
      views_ajax_settings.url =
        view_path + "?" + $.param(Drupal.Views.parseQueryString(url));
      Drupal.ajax(views_ajax_settings).execute();

    }

    if (!this.$view.hasClass('vat-processed')) {
      const heartbeat = 200;
      let tabui = this.$view.find('.views-attachment-tabs');
      let buttons = tabui.find('a');

      buttons.each(function() {
        let button = $(this);
        if (!button.hasClass('vat-button-processed')) {
          const target = button.attr('data-target');
          button.addClass('vat-button-processed')
          button.unbind('click');
          button.on('click', function () {
            _this.$view.find('.pager').hide();
            _this.$view.find('.view-attachment-tab').each(function() {

              $(this).fadeOut(heartbeat);
              $(this).addClass('view-attachment-tab-hidden');
              buttons.each(function() {
                $(this).removeClass('active');
              });
            });
            const wait = setTimeout(fadeInTarget,heartbeat);
            function fadeInTarget() {
              $(target).removeClass('view-attachment-tab-hidden');
              $(target).addClass('active');

              // Certain libraries, like Masonry, will recalculate positioning on resize, so we trigger it here
              window.dispatchEvent(new Event('resize'));
              $(target).fadeIn(heartbeat,function(){
                _this.$view.find('.pager').show();
                button.addClass('active');
              });
            }
          })
        }
      });
      this.$view.addClass('vat-processed')

      // Provide an event for theme post-processing
      this.$view.trigger('views-attachment-tabs-processed',[selector,this.$view]);
    }
  };
})(jQuery, Drupal, drupalSettings);
