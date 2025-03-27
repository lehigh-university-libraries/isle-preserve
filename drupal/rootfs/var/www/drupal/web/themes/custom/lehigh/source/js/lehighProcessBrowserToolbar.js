/**
 * lehighProcessBrowserToolbar.js
 * see Views Attachment Tabs module – viewsAttachmentTabs.js
 *
 */


(function ($, Drupal) {

  /** This is when the attachment tabs have been processed (see the Views
   *  See the Views Attachment Tabs module assets/viewsAttachmentTabs.js
   *  for the views-attachment-tabs-processed event
   */

  $(document).on('views-attachment-tabs-processed', function(e,selector) {
    const vat = $(selector).find('.views-attachment-tabs');
    const viewsHeader = vat.closest('header');
    const paginatorNav = viewsHeader.siblings('nav');

    viewsHeader.wrapAll('<div class="browser-ui">');

    const browserUI = viewsHeader.closest('.browser-ui');
    paginatorNav.prependTo(browserUI);



    /*
    Left over code from another implementation. Left here in case boilerplate for the Mutator is required.

    // const vatMobile = vat.clone();
    const blockContainer = vat.closest('.block').parent();

    function appendViewsTabs(tabsObj,targetElement) {
      tabsObj.appendTo(targetElement);
      tabsObj.fadeIn(window.heartbeat);
    }

    // Infers the presence of tab objects and moves them to the nearest exposed form.
    // The body class is supplied by Contexts. The .vat-processed class is supplied by the VAT module javascript.
    const exposedFormSection = $('body.object-browser .vat-processed').closest('section');
    const exposedFormContainer = exposedFormSection.find('.views-exposed-form');
    const mobileToolbar = '#mobile-toolbar';

    function watchForVatContainer(tabsObj,containerSectionId, containerElementClass, targetElement) {
      let observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
          if (!mutation.addedNodes) return

          for (let i = 0; i < mutation.addedNodes.length; i++) {
            let node = mutation.addedNodes[i]
            // Substring(1) removes the period from the class
            if (node.nodeType === Node.ELEMENT_NODE && (node.classList.contains(containerElementClass.substring(1)) || node.classList.contains(containerElementClass))) {

              let containerElement = $(node);

              if (containerElement.parents('#' + containerSectionId).length > 0)
              {
                appendViewsTabs(tabsObj, $(node).find(targetElement));
                observer.disconnect()
              }
            }
          }
        })
      })

      vat.hide();

      observer.observe(document.body, {
        childList: true
        , subtree: true
        , attributes: false
        , characterData: false
      });
    }

    // Check to see if the exposed form has been rendered
    if (exposedFormContainer.length > 0) {
      appendViewsTabs(vat,exposedFormContainer.find('form'));
    } else {
      watchForVatContainer(vat,exposedFormSection.attr('id'),'.views-exposed-form','form')
    }

    /*

    // Check to see if the exposed form has been rendered
    if ($(mobileToolbar).length > 0) {
      appendViewsTabs(vatMobile,$(mobileToolbar).find('.tier-container'));
    } else {
      watchForVatContainer(vatMobile,mobileToolbar,'.tier-container');
    }

     */


  })

  Drupal.behaviors.moveAttachmentTabs = {};
  Drupal.behaviors.moveAttachmentTabs.attach = function (context,settings) {
    // Stub function for now

  }
})(jQuery, Drupal);

