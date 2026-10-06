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
  const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1366, height: 1000 } });
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
  const page = await context.newPage();
  const errors = [];
  if (process.env.BROWSER_DEBUG) {
    page.on('console', (message) => { if (message.type() === 'error') console.error(message.text()); });
    page.on('requestfailed', (request) => console.error('Failed:', request.url(), request.failure()));
  }
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto(`${base}/browse-source?source=${fixture.source}`, { waitUntil: 'networkidle' });
  await page.locator('[data-source-browser]').waitFor();
  const initial = await page.evaluate(() => {
    const configs = Object.values(drupalSettings.mirador.viewers);
    return configs.map((config) => config.windows[0]);
  });
  assert.equal(initial[0].thumbnailNavigationPosition, 'far-right');
  assert.deepEqual(initial[0].textOverlay, { enabled: false, selectable: false, visible: false });
  assert.ok(initial[0].canvasId.includes('/canvas/'));
  await page.locator('.source-browser__dates').waitFor();
  assert.equal(await page.getByRole('button', { name: 'Go to page' }).count(), 0);
  assert.equal(await page.locator('.source-browser__tabs').getByText('Articles / entries', { exact: true }).count(), 0);
  await page.screenshot({ path: path.join(output, 'desktop-pages.png'), fullPage: true });
  // A document-level token proves navigation did not reload the page.
  await page.evaluate(() => { window.sourceBrowserTestToken = 'same-document'; });
  await page.route('**/browse-source?**', async (route) => {
    await new Promise((resolve) => setTimeout(resolve, 1000));
    await route.continue();
  });
  await page.locator('[data-source-browser-page]').selectOption(String(fixture.pages[1]));
  await page.locator('[data-source-browser][aria-busy="true"]').waitFor();
  assert.equal(await page.locator('.source-browser__toolbar').evaluate((element) => element.inert), true);
  assert.equal(await page.locator('.source-browser__layout').evaluate((element) => element.inert), true);
  await page.locator('.source-browser__loading').waitFor({ state: 'visible' });
  await page.screenshot({ path: path.join(output, 'loading-progress.png'), fullPage: true });
  await page.waitForURL(`**page=${fixture.pages[1]}**`);
  await page.unroute('**/browse-source?**');
  assert.equal(await page.locator('.source-browser__toolbar').evaluate((element) => element.inert), false);
  assert.equal(await page.evaluate(() => window.sourceBrowserTestToken), 'same-document');
  const second = await page.evaluate(() => Object.values(drupalSettings.mirador.viewers)[0].windows[0]);
  assert.notEqual(initial[0].canvasId, second.canvasId);
  await page.locator('.source-browser__tabs').getByText('Transcription', { exact: true }).click();
  await page.waitForURL('**tab=transcription**');
  await page.getByText('Literal <brackets> are preserved.').waitFor();
  assert.equal(await page.locator('[data-source-browser-page]').inputValue(), String(fixture.pages[1]));
  await page.screenshot({ path: path.join(output, 'desktop-transcription.png'), fullPage: true });
  await page.locator('.source-browser__tabs').getByText('Subject index', { exact: true }).click();
  await page.waitForURL('**tab=index**');
  await page.locator('.source-browser__index-term h4 a').first().click();
  await page.waitForURL('**term=**');
  await page.locator('.source-browser__index-term li a[data-source-browser-link]').first().click();
  await page.waitForURL('**tab=pages**');
  assert.equal(await page.evaluate(() => window.sourceBrowserTestToken), 'same-document');
  await page.locator('[data-source-browser-issue]').selectOption(String(fixture.issues[1]));
  await page.waitForURL(`**issue=${fixture.issues[1]}**`);
  await page.locator('.source-browser__heading').getByText('Gunn diary volume 2', { exact: true }).waitFor();
  assert.equal(await page.locator('.source-browser__downloads').getByText('Download original PDF', { exact: true }).count(), 0);
  await page.goBack();
  await page.locator('.source-browser__heading').getByText('Gunn diary volume 1', { exact: true }).waitFor();
  await page.fill('#source-browser-search', 'Needle');
  await page.locator('.source-browser__toolbar button').click();
  await page.waitForURL('**q=Needle**');
  await page.locator('.source-browser__results .source-browser__row').first().waitFor();
  await page.screenshot({ path: path.join(output, 'desktop-search.png'), fullPage: true });
  await page.locator('.source-browser__results').getByText('Clear search', { exact: true }).click();
  await page.waitForURL(`${base}/browse-source?source=${fixture.source}`);
  await page.locator('.source-browser__downloads summary').click();
  await page.locator('.source-browser__downloads').getByText('Download original PDF', { exact: true }).waitFor();
  await page.locator('.source-browser__downloads').getByText('Download generated page PDF', { exact: true }).waitFor();
  await page.screenshot({ path: path.join(output, 'desktop-downloads.png'), fullPage: true });
  for (const width of [1024, 768, 400]) {
    await page.setViewportSize({ width, height: 900 });
    if (width === 400) {
      await page.locator('.source-browser__navigation > summary').click();
      await page.locator('.source-browser__dates').waitFor();
    }
    const dimensions = await page.locator('[data-source-browser]').evaluate((element) => ({ scroll: element.scrollWidth, client: element.clientWidth }));
    assert.ok(dimensions.scroll <= dimensions.client + 1, `Browser overflow at ${width}px: ${JSON.stringify(dimensions)}`);
    await page.screenshot({ path: path.join(output, `pages-${width}px.png`), fullPage: true });
  }
  // Fetch errors leave a retryable message, intact controls, and unchanged URL.
  const currentUrl = page.url();
  await page.route('**/browse-source?**', (route) => route.abort());
  await page.locator('.source-browser__tabs').getByText('About', { exact: true }).click();
  await page.getByRole('status').filter({ hasText: 'Unable to update the browser' }).waitFor();
  assert.equal(page.url(), currentUrl);
  assert.equal(await page.locator('.source-browser__toolbar').evaluate((element) => element.inert), false);
  assert.equal(await page.locator('[data-source-browser]').getAttribute('aria-busy'), 'false');
  await page.unroute('**/browse-source?**');
  // Plain GET forms and links render the same page without JavaScript.
  const noJs = await browser.newContext({ ignoreHTTPSErrors: true, javaScriptEnabled: false, viewport: { width: 400, height: 900 } });
  const plainPage = await noJs.newPage();
  await plainPage.goto(`${base}/browse-source?source=${fixture.source}&issue=${fixture.issues[0]}&page=${fixture.pages[1]}&tab=transcription`);
  await plainPage.getByText('Literal <brackets> are preserved.').waitFor();
  if (!(await plainPage.locator('.source-browser__navigation').getAttribute('open') !== null)) {
    await plainPage.locator('.source-browser__navigation > summary').click();
  }
  await plainPage.locator('.source-browser__dates').waitFor();
  await plainPage.screenshot({ path: path.join(output, 'mobile-no-javascript.png'), fullPage: true });
  await noJs.close();
  await context.close();
  await browser.close();
  assert.deepEqual(errors, [], 'Browser JavaScript errors');
  console.log('Browser checks passed: AJAX, history, canvases, tabs, search, downloads, responsive layout, errors, and no-JS rendering.');
})().catch((error) => { console.error(error); process.exit(1); });
