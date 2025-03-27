(function ($, Drupal, cookies) {
  Drupal.behaviors.lehighNode = {
    attach: function (context, settings) {
      $(once('add-search', '.browse', context)).first().each(function () {
        var s = Drupal.behaviors.lehighNode.getQueryParam('search_api_fulltext');
        if (s != null && s != "") {
          $('.browse .node--type-islandora-object a').each(function() {
            let h = $(this).attr('href') + '?search_api_fulltext=' + encodeURIComponent(s);
            $(this).attr('href', h)
          })
        }
      });

      $(once('fix-facet-search', '#main-content a[href*="f%5B0"]', context)).each(function () {
        var href = $(this).attr('href').replace(window.location.pathname, "/browse");
        $(this).attr('href', href);
      });

      $(once('skip', '.block-mirador', context)).first().each(function () {
        if (window.innerWidth < 1200) {
          $('.MuiButtonBase-root[title="Collapse image tools"], button[title="Collapse text overlay options"]').each(function(){
            $(this).click();
          });
        }
        // skip to pages if #page/8/mode/1up is in the URL (where 8 is the page number)
        if (!window.location.hash) {
          return;
        }
        const hashParts = window.location.hash.substring(1).split('/');
        const pageIndex = hashParts.indexOf('page');
        if (pageIndex === -1 || pageIndex + 1 > hashParts.length) {
          return;
        }
        var pageValue = parseInt(hashParts[pageIndex + 1], 10);
        if (isNaN(pageValue)) {
          return;
        }

        pageValue -= 1;
        const checkInterval = 100;
        const maxAttempts = 500;
        let attempts = 0;
      
        const intervalId = setInterval(() => {
          const viewerId = '#' + drupalSettings.mirador_view_id
          if (typeof Drupal.IslandoraMirador !== 'undefined' &&
              typeof Drupal.IslandoraMirador.instances !== 'undefined' &&
              typeof Drupal.IslandoraMirador.instances[viewerId] !== 'undefined' ) {
            
            // Variable is populated, clear the interval and proceed
            const instance = Drupal.IslandoraMirador.instances[viewerId];              
            const state = instance.store.getState();
            const windowId = Object.keys(state.windows)[0];
            if (!windowId
              || typeof state.windows[windowId] == 'undefined'
              || typeof state.windows[windowId].manifestId == 'undefined') {
              return;
            }
            const mid = state.windows[windowId].manifestId
            if (typeof state.manifests[mid] == 'undefined'
              || typeof state.manifests[mid].json == 'undefined') {
              return;
            }
            manifest = state.manifests[mid].json;
            if (typeof manifest.sequences  == 'undefined'
              || typeof manifest.sequences[0]  == 'undefined'
              || typeof manifest.sequences[0].canvases  == 'undefined'
              || typeof manifest.sequences[0].canvases[pageValue]  == 'undefined') {
              return;
            }

            clearInterval(intervalId);
            var action = Mirador.actions.setCanvas(windowId, manifest.sequences[0].canvases[pageValue]['@id']);
            instance.store.dispatch(action);
          } else {
            attempts++;
            if (attempts >= maxAttempts) {
              clearInterval(intervalId);
              console.error("Mirador instance was not populated within the expected time.");
            }
          }
        }, checkInterval);
      });
    },
    getQueryParam: function(parameterName) {
      var queryString = window.location.search.substring(1);
      var queryParams = queryString.split('&');
      for (var i = 0; i < queryParams.length; i++) {
          var pair = queryParams[i].split('=');
          if (decodeURIComponent(pair[0]) === parameterName) {
              return decodeURIComponent(pair[1]);
          }
      }
      return null;
    }
  };
})(jQuery, Drupal, window.Cookies);
