(function (Drupal, once) {
  function findViewerSelector(browser, settings) {
    if (!settings || !settings.mirador || !settings.mirador.viewers) {
      return null;
    }

    return Object.keys(settings.mirador.viewers).find((selector) => {
      const element = document.querySelector(selector);
      return element && browser.contains(element);
    }) || null;
  }

  function cloneViewerConfig(config, manifestUrl) {
    const next = JSON.parse(JSON.stringify(config));
    const windowConfig = Object.assign({}, next.windows && next.windows[0] ? next.windows[0] : {});

    windowConfig.manifestId = manifestUrl;
    next.manifests = {};
    next.manifests[manifestUrl] = {
      provider: 'Islandora',
    };
    next.windows = [windowConfig];
    if (next.selectedTheme === 'system') {
      next.selectedTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    return next;
  }

  function updateViewer(browser, settings, option) {
    const manifestUrl = option.dataset.manifest;
    const viewerSelector = findViewerSelector(browser, settings);
    if (!manifestUrl || !viewerSelector || typeof Mirador === 'undefined') {
      return false;
    }

    const viewerElement = document.querySelector(viewerSelector);
    const currentConfig = settings.mirador.viewers[viewerSelector];
    if (!viewerElement || !currentConfig) {
      return false;
    }

    const nextConfig = cloneViewerConfig(currentConfig, manifestUrl);
    viewerElement.innerHTML = '';
    Drupal.IslandoraMirador = Drupal.IslandoraMirador || {};
    Drupal.IslandoraMirador.instances = Drupal.IslandoraMirador.instances || {};
    delete Drupal.IslandoraMirador.instances[viewerSelector];
    settings.mirador.viewers[viewerSelector] = nextConfig;
    Drupal.IslandoraMirador.instances[viewerSelector] = Mirador.viewer(nextConfig, window.miradorPlugins || {});

    return true;
  }

  function updateHeading(browser, option) {
    const heading = browser.querySelector('[data-source-browser-heading] h1');
    if (heading && option.dataset.heading) {
      heading.textContent = option.dataset.heading;
    }
  }

  function updateLocation(option) {
    if (option.dataset.url && window.history && window.history.replaceState) {
      window.history.replaceState({}, '', option.dataset.url);
    }
  }

  Drupal.behaviors.lehighSourceBrowser = {
    attach(context, settings) {
      once('lehigh-source-browser', '[data-source-browser]', context).forEach((browser) => {
        browser.querySelectorAll('[data-source-browser-source]').forEach((select) => {
          select.addEventListener('change', () => {
            if (select.value) {
              window.location.href = select.value;
            }
          });
        });

        browser.querySelectorAll('[data-source-browser-manifest]').forEach((select) => {
          select.addEventListener('change', () => {
            const option = select.selectedOptions[0];
            if (!option) {
              return;
            }

            if (!updateViewer(browser, settings, option)) {
              if (option.dataset.url) {
                window.location.href = option.dataset.url;
              }
              return;
            }

            updateHeading(browser, option);
            updateLocation(option);
          });
        });
      });
    },
  };
})(Drupal, once);
