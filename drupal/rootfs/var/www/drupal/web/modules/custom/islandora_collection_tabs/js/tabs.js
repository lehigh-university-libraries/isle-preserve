(function ($, Drupal) {
  Drupal.behaviors.islandoraCollectionTabs = {
    attach: function (context, settings) {
      $(once('control-view', '#block-lehigh-views-block-collection-tabs-block-1', context)).each(function () {
        const block = $(this);
        const navTabs = block.find('.nav-tabs a');
        const collectionElements = $('#primary-content, #block-lehigh-browseitemssummary');
        const collectionTabId = 'tab-view-collection-tab';

        function getActiveTab() {
          return navTabs.filter('.active').first();
        }

        function getActiveHash() {
          const activeTab = getActiveTab();
          const target = activeTab.attr('data-bs-target');
          return target && target.charAt(0) === '#' ? target : '#tab-view-collection';
        }

        function syncCollectionView() {
          const activeTab = getActiveTab();
          if (activeTab.attr('id') === collectionTabId) {
            collectionElements.removeClass('d-none');
          }
          else {
            collectionElements.addClass('d-none');
          }

          $('.messages.messages--error').prependTo('.tab-pane.active');
        }

        function appendHash(url, hash) {
          return url.replace(/#.*$/, '') + hash;
        }

        function syncPagerLinks() {
          const hash = getActiveHash();
          $('#primary-content .pager a, #block-lehigh-browseitemssummary .pager a').each(function () {
            if (this.href) {
              this.href = appendHash(this.href, hash);
            }
          });
        }

        navTabs.on('shown.bs.tab', function () {
          const target = $(this).attr('data-bs-target');
          if (target && window.history && window.history.replaceState) {
            window.history.replaceState(null, '', target);
          }
          syncCollectionView();
          syncPagerLinks();
        });

        function activateTabWhenReady() {
          const activeSelector = window.location.hash || getActiveHash();
          const targetTab = block.find('a[data-bs-target="' + activeSelector + '"]').first();

          if (targetTab.length && window.bootstrap && window.bootstrap.Tab) {
            window.bootstrap.Tab.getOrCreateInstance(targetTab[0]).show();
          } else if (targetTab.length && typeof targetTab.tab === 'function') {
            targetTab.tab('show');
          } else if (targetTab.length) {
            setTimeout(activateTabWhenReady, 50);
          }
        }

        activateTabWhenReady();
        syncCollectionView();
        syncPagerLinks();
      });
    }
  };
})(jQuery, Drupal);
