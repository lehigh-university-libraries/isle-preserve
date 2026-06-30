const { expect, test } = require('@playwright/test');

function baseUrl() {
  if (process.env.BASE_URL) {
    return process.env.BASE_URL.replace(/\/$/, '');
  }
  if (process.env.DOMAIN) {
    return `https://${process.env.DOMAIN}`;
  }
  return 'https://wight.cc.lehigh.edu';
}

async function miradorCandidateUrls(request) {
  if (process.env.MIRADOR_TEST_URLS) {
    return process.env.MIRADOR_TEST_URLS
      .split(',')
      .map((url) => url.trim())
      .filter(Boolean)
      .map((url) => new URL(url, baseUrl()).toString());
  }

  const response = await request.get(`${baseUrl()}/api/v1/mirador?_format=json`, {
    headers: {
      Accept: 'application/json',
    },
  });

  expect(response.ok(), `GET /api/v1/mirador returned ${response.status()}`).toBe(true);

  const rows = await response.json();
  expect(Array.isArray(rows), '/api/v1/mirador should return a JSON array').toBe(true);

  return rows
    .map((row) => row && row.url)
    .filter(Boolean)
    .map((url) => new URL(url, baseUrl()).toString());
}

async function loadDeployedLehighMiradorAssets(page) {
  await page.goto(`${baseUrl()}/`, { waitUntil: 'domcontentloaded' });
  await page.evaluate(() => {
    const jquery = function () {
      return {
        each: () => {},
        first: function () { return this; },
        on: function () { return this; },
      };
    };
    jquery.each = () => {};

    window.Cookies = {};
    window.Drupal = { behaviors: {} };
    window.drupalSettings = {};
    window.jQuery = jquery;
    window.$ = jquery;
    window.once = () => [];
  });

  await page.addScriptTag({ url: `${baseUrl()}/libraries/mirador/dist/main.js` });
  await page.waitForFunction(() => typeof window.Mirador !== 'undefined', null, {
    timeout: 30000,
  });
  await page.addScriptTag({ url: `${baseUrl()}/themes/custom/lehigh/js/node.js` });
  await page.waitForFunction(() => !!window.Drupal?.behaviors?.lehighNode, null, {
    timeout: 30000,
  });
}

async function miradorState(page) {
  return page.evaluate(() => {
    const instances = window.Drupal?.IslandoraMirador?.instances || {};
    const instance = Object.values(instances)[0];
    if (!instance || !instance.store || typeof instance.store.getState !== 'function') {
      return null;
    }

    const state = instance.store.getState();
    const windowId = Object.keys(state.windows || {})[0];
    const windowState = windowId ? state.windows[windowId] : null;
    const manifestId = windowState?.manifestId;
    const manifest = manifestId ? state.manifests?.[manifestId]?.json : null;
    const canvases = manifest?.items || manifest?.sequences?.[0]?.canvases || [];
    const canvasIds = canvases.map((canvas) => canvas.id || canvas['@id']).filter(Boolean);

    return {
      canvasCount: canvasIds.length,
      canvasIds,
      currentCanvasId: windowState?.canvasId || null,
      hasMiradorGlobal: typeof window.Mirador !== 'undefined',
      manifestShape: Array.isArray(manifest?.items) ? 'presentation-3' : 'presentation-2',
    };
  });
}

async function loadMiradorPage(page, url) {
  const target = new URL(url);
  target.hash = 'page/2';

  const pageErrors = [];
  page.on('pageerror', (error) => {
    pageErrors.push(error.message);
  });

  await page.goto(target.toString(), { waitUntil: 'domcontentloaded' });

  await expect(page.locator('.block-mirador, article.mirador, [id^="mirador_"]').first()).toBeVisible({
    timeout: 30000,
  });

  await page.waitForFunction(() => typeof window.Mirador !== 'undefined', null, {
    timeout: 60000,
  });

  await page.waitForFunction(() => {
    const instances = window.Drupal?.IslandoraMirador?.instances || {};
    return Object.keys(instances).length > 0;
  }, null, { timeout: 60000 });

  const stateHandle = await page.waitForFunction(() => {
    const instances = window.Drupal?.IslandoraMirador?.instances || {};
    const instance = Object.values(instances)[0];
    if (!instance || !instance.store || typeof instance.store.getState !== 'function') {
      return null;
    }

    const state = instance.store.getState();
    const windowId = Object.keys(state.windows || {})[0];
    const windowState = windowId ? state.windows[windowId] : null;
    const manifestId = windowState?.manifestId;
    const manifest = manifestId ? state.manifests?.[manifestId]?.json : null;
    const canvases = manifest?.items || manifest?.sequences?.[0]?.canvases || [];
    const canvasIds = canvases.map((canvas) => canvas.id || canvas['@id']).filter(Boolean);

    if (canvasIds.length === 0) {
      return null;
    }
    if (canvasIds.length > 1 && windowState?.canvasId !== canvasIds[1]) {
      return null;
    }

    return true;
  }, null, { timeout: 90000 });

  expect(await stateHandle.jsonValue()).toBe(true);

  const state = await miradorState(page);
  expect(state.hasMiradorGlobal).toBe(true);
  expect(state.canvasCount).toBeGreaterThan(0);
  if (state.canvasCount > 1) {
    expect(state.currentCanvasId).toBe(state.canvasIds[1]);
  }
  expect(pageErrors).toEqual([]);

  return state;
}

test('Mirador 4 loads on a deployed repository item and honors page hash navigation', async ({ page, request }) => {
  const candidates = await miradorCandidateUrls(request);
  test.skip(candidates.length === 0, 'No Mirador-capable nodes were returned by this environment.');

  const failures = [];
  for (const url of candidates.slice(0, 5)) {
    try {
      await loadMiradorPage(page, url);
      return;
    }
    catch (error) {
      failures.push(`${url}: ${error.message}`);
    }
  }

  throw new Error(`No Mirador candidate rendered successfully.\n${failures.join('\n\n')}`);
});

test('deployed Mirador and Lehigh node assets support IIIF Presentation 3 canvas customizations', async ({ page }) => {
  await loadDeployedLehighMiradorAssets(page);

  const result = await page.evaluate(() => {
    const behavior = window.Drupal.behaviors.lehighNode;
    const manifest = {
      id: 'https://example.test/iiif/manifest',
      type: 'Manifest',
      items: [
        { id: 'https://example.test/iiif/canvas/1', type: 'Canvas', width: 1000, height: 1500 },
        { id: 'https://example.test/iiif/canvas/2', type: 'Canvas', width: 1200, height: 1600 },
      ],
    };

    let dispatchedAction = null;
    const instance = {
      store: {
        getState: () => ({
          windows: {
            windowA: {
              manifestId: 'manifestA',
              canvasId: 'https://example.test/iiif/canvas/2',
              viewportPosition: {},
            },
          },
          manifests: {
            manifestA: { json: manifest },
          },
        }),
        dispatch: (action) => {
          dispatchedAction = action;
        },
      },
    };

    behavior.applyCdmzoom(instance, 'windowA', 'cdmzoom:50/10/20');

    return {
      hasMirador: typeof window.Mirador !== 'undefined',
      hasUpdateViewportAction: typeof window.Mirador.actions.updateViewport === 'function',
      canvasCount: behavior.getManifestCanvases(manifest).length,
      canvasId: behavior.getCanvasId(manifest.items[1]),
      foundCanvasId: behavior.getCanvasId(behavior.getCanvasById(manifest, manifest.items[1].id)),
      dispatchedType: dispatchedAction?.type || null,
    };
  });

  expect(result.hasMirador).toBe(true);
  expect(result.hasUpdateViewportAction).toBe(true);
  expect(result.canvasCount).toBe(2);
  expect(result.canvasId).toBe('https://example.test/iiif/canvas/2');
  expect(result.foundCanvasId).toBe('https://example.test/iiif/canvas/2');
  expect(result.dispatchedType).toBeTruthy();
});
