(function (Drupal, drupalSettings) {
  function betaSettings() {
    return drupalSettings.lehighBetaSearch || {};
  }

  function resultTarget(context) {
    return (
      context.querySelector('[data-beta-search-results]') ||
      document.querySelector('[data-beta-search-results]')
    );
  }

  Drupal.behaviors.lehighBetaSearch = {
    attach(context) {
      const settings = betaSettings();
      if (!settings.enabled || context !== document) {
        return;
      }
      const target = resultTarget(document);
      if (!target || target.dataset.goSearchBetaLoaded === '1') {
        return;
      }

      target.dataset.goSearchBetaLoaded = '1';
      target.setAttribute('aria-busy', 'true');

      const params = new URLSearchParams(window.location.search);
      const url = new URL(settings.endpoint || '/_go-search/browse', window.location.origin);
      params.forEach((value, key) => {
        url.searchParams.append(key, value);
      });

      const nodeId = settings.nodeId || target.dataset.betaSearchNodeId;
      if (nodeId) {
        url.searchParams.set('node_id', nodeId);
      }

      fetch(url.toString(), {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'fetch' },
      })
        .then((response) => {
          if (!response.ok) {
            throw new Error(`Search fragment request failed: ${response.status}`);
          }
          return response.text();
        })
        .then((html) => {
          target.innerHTML = html;
        })
        .catch((error) => {
          console.error(error);
          target.dataset.goSearchBetaLoaded = '0';
        })
        .finally(() => {
          target.removeAttribute('aria-busy');
        });
    },
  };
})(Drupal, drupalSettings);
