(function (Drupal, once) {
  let pending;
  function setLoading(browser, loading) {
    browser.setAttribute('aria-busy', String(loading));
    [...browser.children].forEach((element) => {
      if (!element.matches('[data-source-browser-status], .source-browser__loading')) element.inert = loading;
    });
  }
  async function navigate(browser, url, push = true) {
    if (pending) pending.abort();
    pending = new AbortController();
    setLoading(browser, true);
    browser.querySelector('[data-source-browser-status]').textContent = Drupal.t('Loading…');
    try {
      const response = await fetch(url, { signal: pending.signal });
      if (!response.ok) throw new Error('Unable to load browser');
      const documentNext = new DOMParser().parseFromString(await response.text(), 'text/html');
      const next = documentNext.querySelector('[data-source-browser]');
      if (!next) throw new Error('Missing browser');
      // Use the complete server-rendered state so metadata and viewer stay aligned.
      const settingsScript = documentNext.querySelector('script[data-drupal-selector="drupal-settings-json"]');
      const settings = settingsScript ? JSON.parse(settingsScript.textContent) : window.drupalSettings;
      if (next.querySelector('[id^="mirador-"]') && typeof Mirador === 'undefined') {
        window.location.assign(url);
        return;
      }
      settings.mirador = settings.mirador || { viewers: {} };
      if (window.drupalSettings.mirador) {
        const instances = Drupal.IslandoraMirador && Drupal.IslandoraMirador.instances;
        Object.entries(instances || {}).forEach(([selector, viewer]) => {
          if (browser.querySelector(selector) && typeof viewer.unmount === 'function') viewer.unmount();
        });
        Drupal.detachBehaviors(browser, window.drupalSettings, 'unload');
      }
      Object.assign(window.drupalSettings, settings);
      next.classList.add('source-browser--updated');
      browser.replaceWith(next);
      Drupal.attachBehaviors(next, settings);
      if (push) window.history.pushState({}, '', url);
      next.querySelector('[data-source-browser-status]').textContent = Drupal.t('Browser updated.');
      const heading = next.querySelector('[data-source-browser-focus]') || next.querySelector('h2');
      if (heading) {
        heading.tabIndex = -1;
        heading.focus();
      }
    } catch (error) {
      if (error.name === 'AbortError') return;
      setLoading(browser, false);
      browser.querySelector('[data-source-browser-status]').textContent = Drupal.t('Unable to update the browser. Please retry or reload the page.');
    }
  }
  Drupal.behaviors.lehighSourceBrowser = {
    attach(context) {
      const browsers = [...context.querySelectorAll('[data-source-browser]')];
      if (context.matches && context.matches('[data-source-browser]')) browsers.unshift(context);
      once('lehigh-source-browser', browsers).forEach((browser) => {
        browser.querySelector('[data-source-browser-navigation]').open = window.matchMedia('(min-width: 768px)').matches;
        const form = browser.querySelector('form');
        form.addEventListener('submit', (event) => {
          event.preventDefault();
          const url = new URL(form.action, window.location.href);
          url.search = new URLSearchParams(new FormData(form)).toString();
          navigate(browser, url.href);
        });
        browser.querySelector('[data-source-browser-source]').addEventListener('change', (event) => {
          const url = new URL(form.action, window.location.href);
          url.search = new URLSearchParams({ source: event.target.value }).toString();
          navigate(browser, url.href);
        });
        browser.querySelector('[data-source-browser-issue]').addEventListener('change', (event) => {
          const option = event.target.selectedOptions[0];
          if (option) navigate(browser, option.dataset.url);
        });
        const pageSelect = browser.querySelector('[data-source-browser-page]');
        if (pageSelect) pageSelect.addEventListener('change', () => navigate(browser, pageSelect.selectedOptions[0].dataset.url));
        const pageForm = browser.querySelector('[data-source-browser-page-form]');
        if (pageForm) pageForm.addEventListener('submit', (event) => {
          event.preventDefault();
          const url = new URL(form.action, window.location.href);
          url.search = new URLSearchParams(new FormData(pageForm)).toString();
          navigate(browser, url.href);
        });
        browser.querySelectorAll('[data-source-browser-link]').forEach((link) => {
          link.addEventListener('click', (event) => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
            event.preventDefault();
            navigate(browser, link.href);
          });
        });
      });
      once('lehigh-source-browser-history', 'html', context).forEach(() => {
        window.matchMedia('(min-width: 768px)').addEventListener('change', (event) => {
          const navigation = document.querySelector('[data-source-browser-navigation]');
          if (navigation) navigation.open = event.matches;
        });
        window.addEventListener('popstate', () => {
          const browser = document.querySelector('[data-source-browser]');
          if (browser) navigate(browser, window.location.href, false);
        });
      });
    },
  };
})(Drupal, once);
