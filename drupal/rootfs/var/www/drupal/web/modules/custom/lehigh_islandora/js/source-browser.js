(function (Drupal, once) {
  let pending;
  const pageNavigation = new WeakMap();
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
      if (push) window.history.pushState({}, '', url);
      Drupal.attachBehaviors(next, settings);
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
        let currentUrl = new URL(window.location.href);
        let changingCanvas = false;
        let transcriptRequest;
        const initialPage = pageSelect && pageSelect.value;
        const syncPage = (option, historyMode) => {
          pageSelect.value = option.value;
          const url = new URL(window.location.href);
          url.searchParams.set('page', option.value);
          if (historyMode === 'push' && url.href !== window.location.href) window.history.pushState({}, '', url);
          if (historyMode === 'replace') window.history.replaceState({}, '', url);
          currentUrl = new URL(window.location.href);
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
            if (link) {
              link.hidden = !neighbor;
              link.href = neighbor ? neighbor.dataset.url : '#';
            }
          });
          browser.querySelectorAll('[data-page-id]').forEach((row) => {
            if (row.dataset.pageId === option.value) row.setAttribute('aria-current', 'page');
            else row.removeAttribute('aria-current');
          });
        };
        const updateTranscription = async (option) => {
          const panel = browser.querySelector('[data-source-browser-transcripts]');
          if (!panel) return;
          if (transcriptRequest) transcriptRequest.abort();
          const controller = new AbortController();
          transcriptRequest = controller;
          panel.setAttribute('aria-busy', 'true');
          panel.textContent = Drupal.t('Loading transcription…');
          const issue = browser.querySelector('[data-source-browser-page-form] input[name="issue"]').value;
          try {
            const response = await fetch(Drupal.url(`api/v1/source-browser/${browser.dataset.sourceId}/transcription/${issue}/${option.value}`), { signal: controller.signal });
            if (!response.ok) throw new Error('Unable to load transcription');
            const data = await response.json();
            if (controller.signal.aborted || !browser.isConnected) return;
            panel.replaceChildren();
            const addText = (parent, tag, text) => {
              const element = document.createElement(tag);
              element.textContent = text;
              parent.append(element);
              return element;
            };
            const addLink = (parent, text, url) => {
              const link = addText(parent, 'a', text);
              link.href = url;
            };
            if (!data.transcriptions.length) addText(panel, 'p', Drupal.t('No transcription is available for this page or document.'));
            data.transcriptions.forEach((transcript) => {
              const article = document.createElement('article');
              panel.append(article);
              if (transcript.is_pdf && transcript.file_url) {
                const frame = document.createElement('iframe');
                frame.className = 'source-browser__transcription-pdf';
                frame.src = `${transcript.file_url}#view=FitH`;
                frame.title = Drupal.t('PDF transcription: @title', { '@title': transcript.node_title });
                article.append(frame);
                addLink(addText(article, 'p', ''), Drupal.t('Open or download the PDF transcription'), transcript.file_url);
              } else {
                const text = addText(article, 'div', transcript.text);
                text.className = 'source-browser__transcription';
              }
              if (transcript.truncated) {
                const notice = addText(article, 'p', Drupal.t('This preview is shortened. '));
                addLink(notice, Drupal.t('Download the full transcription'), transcript.file_url);
              }
            });
          } catch (error) {
            if (error.name !== 'AbortError') {
              panel.textContent = Drupal.t('Unable to load the transcription.');
              const link = document.createElement('a');
              link.href = option.dataset.url;
              link.textContent = Drupal.t('Reload this page');
              panel.append(' ', link);
            }
          } finally {
            if (transcriptRequest === controller) {
              panel.removeAttribute('aria-busy');
              transcriptRequest = null;
            }
          }
        };
        const selectCanvas = (option, historyMode = 'push') => {
          const tab = browser.querySelector('[data-source-browser-page-form] input[name="tab"]');
          const instances = Drupal.IslandoraMirador && Drupal.IslandoraMirador.instances;
          const entry = Object.entries(instances || {}).find(([selector]) => browser.querySelector(selector));
          if (!option || !option.dataset.canvas || !tab || !['pages', 'transcription'].includes(tab.value) || !entry || typeof Mirador === 'undefined') return false;
          const store = entry[1].store;
          const windowId = Object.keys(store.getState().windows || {})[0];
          if (!windowId) return false;
          changingCanvas = true;
          try {
            store.dispatch(Mirador.actions.setCanvas(windowId, option.dataset.canvas));
          } finally {
            changingCanvas = false;
          }
          syncPage(option, historyMode);
          if (tab.value === 'transcription') updateTranscription(option);
          return true;
        };
        if (pageSelect) {
          pageNavigation.set(browser, (target) => {
            const previous = new URL(currentUrl);
            const next = new URL(target);
            previous.searchParams.delete('page');
            next.searchParams.delete('page');
            if (previous.href !== next.href) return false;
            const id = new URL(target).searchParams.get('page') || initialPage;
            const option = [...pageSelect.options].find((item) => item.value === id);
            return selectCanvas(option, 'none');
          });
          pageSelect.addEventListener('change', () => {
            const option = pageSelect.selectedOptions[0];
            if (!selectCanvas(option)) navigate(browser, option.dataset.url);
          });
        }
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
              if (initialCanvas || pending || changingCanvas) return;
              const option = [...pageSelect.options].find((item) => item.dataset.canvas && item.dataset.canvas === canvas);
              if (!option || option.value === pageSelect.value) return;
              syncPage(option, 'replace');
              if (browser.querySelector('[data-source-browser-transcripts]')) updateTranscription(option);
            });
          }, 50);
        }
        const pageForm = browser.querySelector('[data-source-browser-page-form]');
        if (pageForm) pageForm.addEventListener('submit', (event) => {
          event.preventDefault();
          const url = new URL(form.action, window.location.href);
          url.search = new URLSearchParams(new FormData(pageForm)).toString();
          if (!selectCanvas(pageSelect && pageSelect.selectedOptions[0])) navigate(browser, url.href);
        });
        browser.querySelectorAll('[data-source-browser-link]').forEach((link) => {
          link.addEventListener('click', (event) => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
            event.preventDefault();
            const target = new URL(link.href, window.location.href);
            const isPageLink = link.matches('[data-page-previous], [data-page-next]') || link.closest('[data-page-id]');
            const option = pageSelect && [...pageSelect.options].find((item) => item.value === target.searchParams.get('page'));
            if (!(isPageLink && ['pages', 'transcription'].includes(target.searchParams.get('tab')) && selectCanvas(option))) navigate(browser, link.href);
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
          if (browser && !(pageNavigation.get(browser) && pageNavigation.get(browser)(window.location.href))) navigate(browser, window.location.href, false);
        });
      });
    },
  };
})(Drupal, once);
