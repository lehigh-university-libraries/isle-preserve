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
    const controller = new AbortController();
    pending = controller;
    setLoading(browser, true);
    browser.querySelector('[data-source-browser-status]').textContent = Drupal.t('Loading…');
    try {
      const response = await fetch(url, { signal: controller.signal });
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
      // Mirador can autofocus after mounting. Protect the restored heading until
      // the reader interacts, then allow normal focus behavior immediately.
      const heading = next.querySelector('[data-source-browser-focus]') || next.querySelector('h2');
      if (heading) heading.tabIndex = -1;
      let restoreFocus = Boolean(heading);
      const stopRestoring = () => { restoreFocus = false; };
      next.addEventListener('pointerdown', stopRestoring, { once: true });
      next.addEventListener('keydown', stopRestoring, { once: true });
      next.addEventListener('focusin', (event) => {
        if (restoreFocus && event.target.closest('.source-browser__viewer')) {
          heading.focus({ preventScroll: true });
          heading.scrollIntoView({ block: 'start' });
        }
      });
      setTimeout(stopRestoring, 2000);
      Drupal.attachBehaviors(next, settings);
      if (push) window.history.pushState({}, '', url);
      next.querySelector('[data-source-browser-status]').textContent = Drupal.t('Browser updated.');
      if (heading) {
        heading.tabIndex = -1;
        requestAnimationFrame(() => {
          heading.focus({ preventScroll: true });
          heading.scrollIntoView({ block: 'start' });
        });
      }
    } catch (error) {
      if (error.name === 'AbortError') return;
      setLoading(browser, false);
      browser.querySelector('[data-source-browser-status]').textContent = Drupal.t('Unable to update the browser. Please retry or reload the page.');
    } finally {
      if (pending === controller) pending = null;
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
        // Keep browser links and URL aligned with Mirador's current canvas.
        if (pageSelect) {
          let attempts = 0;
          const timer = setInterval(() => {
            if (!browser.isConnected || ++attempts > 100) { clearInterval(timer); return; }
            const instances = Drupal.IslandoraMirador && Drupal.IslandoraMirador.instances;
            const entry = Object.entries(instances || {}).find(([selector]) => browser.querySelector(selector));
            if (!entry) return;
            clearInterval(timer);
            const store = entry[1].store;
            const initialWindows = Object.values(store.getState().windows || {});
            let lastCanvas = initialWindows[0] && initialWindows[0].canvasId;
            const unsubscribe = store.subscribe(() => {
              if (!browser.isConnected) { unsubscribe(); return; }
              const windows = Object.values(store.getState().windows || {});
              const canvas = windows[0] && windows[0].canvasId;
              if (!canvas || canvas === lastCanvas) return;
              // The first canvas is initialization, not a reader navigation.
              const initialCanvas = !lastCanvas;
              lastCanvas = canvas;
              if (initialCanvas || pending) return;
              const option = [...pageSelect.options].find((item) => item.dataset.canvas && item.dataset.canvas === canvas);
              if (!option || option.value === pageSelect.value) return;
              if (new URL(window.location.href).searchParams.get('tab') === 'transcription') {
                navigate(browser, option.dataset.url);
                return;
              }
              pageSelect.value = option.value;
              const url = new URL(window.location.href);
              url.searchParams.set('page', option.value);
              window.history.replaceState({}, '', url);
              const toolbarPage = browser.querySelector('.source-browser__toolbar input[name="page"]');
              if (toolbarPage) toolbarPage.value = option.value;
              browser.querySelectorAll('.source-browser__tabs [data-source-browser-link]').forEach((link) => {
                const target = new URL(link.href, window.location.href);
                target.searchParams.set('page', option.value);
                link.href = target.href;
              });
              [['[data-page-previous]', -1], ['[data-page-next]', 1]].forEach(([selector, direction]) => {
                const link = browser.querySelector(selector);
                const neighbor = pageSelect.options[pageSelect.selectedIndex + direction];
                link.hidden = !neighbor;
                link.href = neighbor ? neighbor.dataset.url : '#';
              });
              browser.querySelectorAll('[data-page-id]').forEach((row) => {
                if (row.dataset.pageId === option.value) row.setAttribute('aria-current', 'page');
                else row.removeAttribute('aria-current');
              });
            });
          }, 50);
        }
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
