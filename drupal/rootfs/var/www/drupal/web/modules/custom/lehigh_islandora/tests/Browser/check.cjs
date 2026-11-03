/* Run with PLAYWRIGHT_MODULE pointing to an installed playwright package. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const base = process.env.BROWSER_BASE_URL || 'https://islandora.io';
const output = process.env.BROWSER_OUTPUT_DIR || '/tmp/source-browser-preview';
fs.mkdirSync(output, { recursive: true });
(async () => {
  const browser = await chromium.launch({ args: ['--no-sandbox', ...(process.env.BROWSER_HOST_IP ? [`--host-resolver-rules=MAP islandora.io ${process.env.BROWSER_HOST_IP}`] : [])] });
  const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1366, height: 1000 }, ...(process.env.BROWSER_STORAGE_STATE ? { storageState: process.env.BROWSER_STORAGE_STATE } : {}) });
  // Optional image-service fixture isolates UI checks from local Cantaloupe.
  // Manifest canvas IDs and Drupal routes still come from the real application.
  if (process.env.BROWSER_IMAGE_FIXTURE) {
    await context.route(/\/node\/\d+\/(?:book-)?manifest(?:\?.*)?$/, async (route) => {
      const response = await route.fetch();
      const manifest = await response.json();
      const body = JSON.stringify(manifest).replaceAll('http://islandora.io/iiif/', 'https://islandora.io/iiif/');
      await route.fulfill({ response, body });
    });
    await context.route('**/iiif/3/**', async (route) => {
      const url = route.request().url();
      if (url.endsWith('/info.json')) {
        await route.fulfill({ json: {
          '@context': 'http://iiif.io/api/image/3/context.json',
          id: url.replace('/info.json', ''), type: 'ImageService3',
          protocol: 'http://iiif.io/api/image', profile: 'level2',
          width: 500, height: 700,
        } });
      } else {
        await route.fulfill({ contentType: 'image/png', body: fs.readFileSync(process.env.BROWSER_IMAGE_FIXTURE) });
      }
    });
  }
  // A one-time login URL can be generated on a disposable test site with drush uli.
  const page = await context.newPage();
  if (process.env.BROWSER_LOGIN_URL) await page.goto(process.env.BROWSER_LOGIN_URL, { waitUntil: 'networkidle' });
  const authState = await context.storageState();
  const anonymous = await browser.newContext({ ignoreHTTPSErrors: true });
  const anonymousPage = await anonymous.newPage();
  const gated = await anonymousPage.goto(`${base}/pfaffs?source=${fixture.source}`);
  assert.equal(gated.status(), 403, 'Preview requires an authenticated user');
  const collection = await anonymousPage.goto(`${base}/browse-items/${fixture.source}`);
  assert.equal(collection.status(), 200, 'Public collection stays accessible');
  assert.equal(await anonymousPage.locator('[data-source-browser]').count(), 0);
  await anonymous.close();
  const errors = [];
  if (process.env.BROWSER_DEBUG) {
    page.on('console', (message) => { if (message.type() === 'error') console.error(message.text()); });
    page.on('requestfailed', (request) => console.error('Failed:', request.url(), request.failure()));
  }
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto(`${base}/pfaffs?source=${fixture.source}`, { waitUntil: 'networkidle' });
  await page.locator('[data-source-browser]').waitFor();
  assert.equal(await page.locator('#source-browser-scope option[value="source"]').textContent(), await page.locator('[data-source-browser-source] option:checked').textContent());
  assert.equal(await page.locator('#source-browser-scope option[value="issue"]').textContent(), await page.locator('[data-source-browser-issue] option:checked').textContent());
  const toolbarHeights = await page.locator('.source-browser__toolbar').evaluate((toolbar) =>
    [...toolbar.querySelectorAll('select, input[type="search"], button')].map((control) => control.getBoundingClientRect().height));
  assert.ok(Math.max(...toolbarHeights) - Math.min(...toolbarHeights) <= 1, `Toolbar controls have mismatched heights: ${toolbarHeights}`);
  await page.locator(`[data-page-id="${fixture.pages[0]}"]`).getByText('Download generated page PDF', { exact: true }).waitFor();
  const initial = await page.evaluate(() => {
    const configs = Object.values(drupalSettings.mirador.viewers);
    return configs.map((config) => config.windows[0]);
  });
  assert.equal(initial[0].thumbnailNavigationPosition, 'far-right');
  assert.deepEqual(initial[0].textOverlay, { enabled: false, selectable: false, visible: false });
  assert.ok(initial[0].canvasId.includes('/canvas/'));
  assert.equal(initial[0].allowClose, false);
  const manifest = await page.evaluate(async () => {
    const config = Object.values(drupalSettings.mirador.viewers)[0];
    const response = await fetch(config.windows[0].manifestId);
    if (!response.ok) throw new Error(`Manifest returned HTTP ${response.status}`);
    return response.json();
  });
  const canvases = manifest.sequences?.[0]?.canvases || manifest.items || [];
  assert.ok(canvases.length >= 3, `Expected at least three fixture canvases, got ${canvases.length}`);
  assert.ok(canvases.some((canvas) => (canvas.id || canvas['@id']) === initial[0].canvasId), 'The selected canvas exists in the manifest');
  const viewer = page.locator('.source-browser__viewer');
  await viewer.getByRole('button', { name: 'Next item', exact: true }).click();
  await page.waitForFunction((id) => document.querySelector('[data-source-browser-page]')?.value === String(id), fixture.pages[1]);
  assert.equal(new URL(page.url()).searchParams.get('page'), String(fixture.pages[1]));
  await viewer.getByRole('button', { name: 'Next item', exact: true }).click();
  await page.waitForFunction((id) => document.querySelector('[data-source-browser-page]')?.value === String(id), fixture.pages[2]);
  assert.equal(await page.locator('.source-browser__toolbar input[name="page"]').inputValue(), String(fixture.pages[2]));
  assert.equal(new URL(await page.locator('[data-page-previous]').getAttribute('href'), base).searchParams.get('page'), String(fixture.pages[1]));
  assert.equal(new URL(await page.locator('[data-page-next]').getAttribute('href'), base).searchParams.get('page'), String(fixture.pages[3]));
  assert.equal(await page.locator(`[data-page-id="${fixture.pages[2]}"]`).getAttribute('aria-current'), 'page');
  for (const link of await page.locator('.source-browser__tabs a').all()) {
    assert.equal(new URL(await link.getAttribute('href'), base).searchParams.get('page'), String(fixture.pages[2]));
  }
  await page.locator('[data-page-previous]').click();
  await page.waitForURL(`**page=${fixture.pages[1]}**`);
  await page.locator('.source-browser__tabs').getByText('Transcription', { exact: true }).click();
  await page.getByText('Needle: café diary transcription, volume 1, page 2.', { exact: false }).waitFor();
  assert.equal(await page.locator('.source-browser__viewer [id^="mirador-"]').count(), 1);
  assert.equal(await page.evaluate(() => Object.values(drupalSettings.mirador.viewers)[0].windows[0].thumbnailNavigationPosition), 'far-bottom');
  let transcriptRequests = 0;
  const delayTranscript = async (route) => {
    transcriptRequests += 1;
    await new Promise((resolve) => setTimeout(resolve, 1200));
    await route.continue();
  };
  await page.evaluate(() => { window.transcriptTestViewer = Object.values(Drupal.IslandoraMirador.instances)[0]; });
  await page.route('**/api/v1/source-browser/*/transcription/**', delayTranscript);
  await page.locator('.source-browser__viewer').getByRole('button', { name: 'Next item', exact: true }).click();
  await page.locator('[data-source-browser-transcripts][aria-busy="true"]').waitFor();
  await page.evaluate(() => {
    const viewer = Object.values(Drupal.IslandoraMirador.instances)[0];
    for (let i = 0; i < 5; i += 1) viewer.store.dispatch({ type: 'SOURCE_BROWSER_TEST' });
  });
  await page.waitForURL(`**page=${fixture.pages[2]}**`);
  await page.getByText('Needle: café diary transcription, volume 1, page 3.', { exact: false }).waitFor();
  await page.unroute('**/api/v1/source-browser/*/transcription/**', delayTranscript);
  assert.equal(transcriptRequests, 1, 'Mirador updates cause only one transcription request');
  assert.equal(await page.evaluate(() => window.transcriptTestViewer === Object.values(Drupal.IslandoraMirador.instances)[0]), true, 'Transcription navigation keeps the viewer mounted');
  assert.equal(await page.locator('[data-source-browser-page]').inputValue(), String(fixture.pages[2]));

  await page.locator('.source-browser__tabs').getByText('Pages', { exact: true }).click();
  await page.waitForURL('**tab=pages**');
  await page.locator('.source-browser__viewer').getByRole('button', { name: 'Next item', exact: true }).waitFor();
  await page.locator('[data-source-browser-page]').selectOption(String(fixture.pages[0]));
  await page.waitForURL(`**page=${fixture.pages[0]}**`);
  await page.locator('.source-browser__dates').waitFor();
  assert.equal(await page.getByRole('button', { name: 'Go to page' }).count(), 0);
  assert.equal(await page.locator('.source-browser__tabs').getByText('Articles / entries', { exact: true }).count(), 0);
  await page.screenshot({ path: path.join(output, 'desktop-pages.png'), fullPage: true });
  // Page changes should dispatch to the existing viewer without fetching HTML.
  await page.evaluate(() => {
    window.sourceBrowserTestToken = 'same-document';
    window.sourceBrowserTestViewer = Object.values(Drupal.IslandoraMirador.instances)[0];
  });
  let pageRequests = 0;
  const countPageRequests = async (route) => { pageRequests += 1; await route.continue(); };
  await page.route('**/pfaffs?**', countPageRequests);
  await page.locator('[data-source-browser-page]').selectOption(String(fixture.pages[1]));
  await page.waitForURL(`**page=${fixture.pages[1]}**`);
  assert.equal(await page.evaluate(() => window.sourceBrowserTestViewer === Object.values(Drupal.IslandoraMirador.instances)[0]), true);
  const second = await page.evaluate(() => Object.values(window.sourceBrowserTestViewer.store.getState().windows)[0]);
  assert.notEqual(initial[0].canvasId, second.canvasId);
  await page.locator('[data-page-next]').click();
  await page.waitForURL(`**page=${fixture.pages[2]}**`);
  await page.goBack();
  await page.waitForFunction((id) => document.querySelector('[data-source-browser-page]')?.value === String(id), fixture.pages[1]);
  assert.equal(pageRequests, 0, 'Page controls and browser history do not request browser HTML');
  await page.unroute('**/pfaffs?**', countPageRequests);
  await page.locator('.source-browser__tabs').getByText('Transcription', { exact: true }).click();
  await page.waitForURL('**tab=transcription**');
  await page.getByText('Literal <brackets> are preserved.').waitFor();
  assert.equal(await page.locator('[data-source-browser-page]').inputValue(), String(fixture.pages[1]));
  await page.screenshot({ path: path.join(output, 'desktop-transcription.png'), fullPage: true });
  const layout = await page.locator('.source-browser__reading').evaluate((container) => {
    const viewerBounds = container.querySelector('.source-browser__viewer').getBoundingClientRect();
    const textBounds = container.querySelector('.source-browser__transcription').getBoundingClientRect();
    return { viewerTop: viewerBounds.top, textTop: textBounds.top };
  });
  assert.ok(Math.abs(layout.viewerTop - layout.textTop) < 300, 'Image and transcription start together');
  await page.locator('[data-source-browser-page]').selectOption(String(fixture.pages[3]));
  await page.waitForURL(`**page=${fixture.pages[3]}**`);
  await page.getByText('Needle: café diary transcription, volume 1, page 4.', { exact: false }).waitFor();
  await page.waitForTimeout(1500);
  assert.equal(await page.locator('[data-source-browser-page]').inputValue(), String(fixture.pages[3]), 'A page without an image remains selected');
  assert.equal(await page.locator('.source-browser__viewer [id^="mirador-"]').count(), 0, 'Do not show another page image when this page has none');

  await page.locator('.source-browser__tabs').getByText('Subject index', { exact: true }).click();
  await page.waitForURL('**tab=index**');
  assert.equal(await page.locator('.source-browser__index-term p').count(), 0, 'Index references load only after selecting a subject');
  await page.locator('.source-browser__index-term h4 a').first().click();
  await page.waitForURL('**term=**');
  await page.locator('.source-browser__index-term p a[data-source-browser-link]').first().click();
  await page.waitForURL('**tab=pages**');
  assert.equal(await page.evaluate(() => window.sourceBrowserTestToken), 'same-document');
  await page.locator('[data-source-browser-issue]').selectOption(String(fixture.issues[1]));
  await page.waitForURL(`**issue=${fixture.issues[1]}**`);
  await page.locator('.source-browser__heading').getByText('Gunn diary volume 2', { exact: true }).waitFor();
  assert.equal(await page.locator('.source-browser__downloads').getByText('Download original PDF', { exact: true }).count(), 0);
  await page.goBack();
  await page.locator('.source-browser__heading').getByText('Gunn diary volume 1', { exact: true }).waitFor();
  await page.fill('#source-browser-search', 'Needle');
  await page.locator('#source-browser-search').press('Enter');
  await page.waitForURL('**q=Needle**');
  await page.locator('.source-browser__results .source-browser__row').first().waitFor();
  const resultsHeading = page.locator('.source-browser__results h2');
  await page.waitForFunction(() => document.activeElement === document.querySelector('.source-browser__results h2'));
  const bounds = await resultsHeading.boundingBox();
  assert.ok(bounds.y >= 0 && bounds.y < 1000, 'Search results heading is in view');
  await page.locator('#source-browser-search').hover();
  const searchStyle = await page.locator('#source-browser-search').evaluate((element) => {
    const style = getComputedStyle(element);
    return { color: style.color, background: style.backgroundColor, border: style.borderTopWidth };
  });
  assert.notEqual(searchStyle.color, searchStyle.background, 'Search text remains visible on hover');
  assert.notEqual(searchStyle.border, '0px', 'Search has a visible border');
  const searchButton = page.locator('.source-browser__toolbar button');
  await searchButton.hover();
  const buttonStyle = await searchButton.evaluate((element) => {
    const style = getComputedStyle(element);
    return { color: style.color, background: style.backgroundColor, decoration: style.textDecorationLine };
  });
  assert.notEqual(buttonStyle.color, buttonStyle.background, 'Button text remains visible on hover');
  assert.equal(buttonStyle.decoration, 'none');
  await page.screenshot({ path: path.join(output, 'desktop-search.png'), fullPage: true });
  await page.locator('.source-browser__results').getByText('Clear search', { exact: true }).click();
  await page.waitForURL(`${base}/pfaffs?source=${fixture.source}`);
  await page.locator('.source-browser__downloads summary').click();
  await page.locator('.source-browser__downloads').getByText('Download original PDF', { exact: true }).waitFor();
  await page.locator('.source-browser__downloads').getByText('Download generated page PDF', { exact: true }).waitFor();
  await page.screenshot({ path: path.join(output, 'desktop-downloads.png'), fullPage: true });
  for (const width of [1024, 768, 400]) {
    await page.setViewportSize({ width, height: 900 });
    if (width === 400) {
      await page.locator('.source-browser__navigation > summary').evaluate((element) => element.scrollIntoView({ block: 'center' }));
      await page.locator('.source-browser__navigation > summary').focus();
      await page.keyboard.press('Enter');
      await page.locator('.source-browser__dates').waitFor();
    }
    const dimensions = await page.locator('[data-source-browser]').evaluate((element) => ({ scroll: element.scrollWidth, client: element.clientWidth }));
    assert.ok(dimensions.scroll <= dimensions.client + 1, `Browser overflow at ${width}px: ${JSON.stringify(dimensions)}`);
    await page.screenshot({ path: path.join(output, `pages-${width}px.png`), fullPage: true });
  }
  // Fetch errors leave a retryable message, intact controls, and unchanged URL.
  const currentUrl = page.url();
  await page.route('**/pfaffs?**', (route) => route.abort());
  await page.locator('.source-browser__tabs').getByText('About', { exact: true }).click();
  await page.getByRole('status').filter({ hasText: 'Unable to update the browser' }).waitFor();
  assert.equal(page.url(), currentUrl);
  assert.equal(await page.locator('.source-browser__toolbar').evaluate((element) => element.inert), false);
  assert.equal(await page.locator('[data-source-browser]').getAttribute('aria-busy'), 'false');
  await page.unroute('**/pfaffs?**');
  // Plain GET forms and links render the same page without JavaScript.
  const noJs = await browser.newContext({ ignoreHTTPSErrors: true, javaScriptEnabled: false, storageState: authState, viewport: { width: 400, height: 900 } });
  const plainPage = await noJs.newPage();
  await plainPage.goto(`${base}/pfaffs?source=${fixture.source}&issue=${fixture.issues[0]}&page=${fixture.pages[1]}&tab=transcription`);
  await plainPage.getByText('Literal <brackets> are preserved.').waitFor();
  if (!(await plainPage.locator('.source-browser__navigation').getAttribute('open') !== null)) {
    await plainPage.locator('.source-browser__navigation > summary').evaluate((element) => element.scrollIntoView({ block: 'center' }));
    await plainPage.locator('.source-browser__navigation > summary').focus();
    await plainPage.keyboard.press('Enter');
  }
  await plainPage.locator('.source-browser__dates').waitFor();
  await plainPage.screenshot({ path: path.join(output, 'mobile-no-javascript.png'), fullPage: true });
  await noJs.close();
  await context.close();
  await browser.close();
  assert.deepEqual(errors, [], 'Browser JavaScript errors');
  console.log('Browser checks passed: AJAX, history, canvases, tabs, search, downloads, responsive layout, errors, and no-JS rendering.');
})().catch((error) => { console.error(error); process.exit(1); });
