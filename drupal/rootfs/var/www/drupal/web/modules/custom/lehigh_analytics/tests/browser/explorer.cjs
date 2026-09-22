// Run with Playwright/Chromium available; all HTTP responses are local fixtures.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '../..');
const gate = () => {
  let release;
  const promise = new Promise(resolve => { release = resolve; });
  return { promise, release };
};

(async () => {
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  try {
    const page = await browser.newPage();
    const scriptErrors = [];
    page.on('pageerror', error => scriptErrors.push(error.message));
    let html = fs.readFileSync(path.join(root, 'templates/lehigh-usage-explorer.html.twig'), 'utf8')
      .replace(/{% for key, period in periods %}.*?{% endfor %}/s, '<option value="calendar">Calendar</option><option value="fiscal">Fiscal</option>')
      .replace(/{% for name, label in fields %}.*?{% endfor %}/s, '<option value="field_model">Model</option>')
      .replace(/{{.*?}}/gs, '#');
    let slowViews = gate();
    let staleViews;
    let countryFails = true;
    let active = 0;
    let maximum = 0;
    const seen = [];
    await page.route('https://usage.test/**', async route => {
      const url = new URL(route.request().url());
      if (url.pathname === '/usage') return route.fulfill({ contentType: 'text/html', body: html });
      if (url.pathname.startsWith('/usage/options')) return route.fulfill({ json: [] });
      const report = url.searchParams.get('report');
      const period = url.searchParams.get('period');
      assert(['summary', 'types', 'views', 'downloads', 'collections', 'countries'].includes(report));
      active++;
      maximum = Math.max(maximum, active);
      seen.push([report, period]);
      try {
        if (report === 'views' && slowViews) await slowViews.promise;
        if (report === 'views' && period === 'fiscal' && staleViews) await staleViews.promise;
        if (report === 'countries' && countryFails) return await route.fulfill({ status: 504, body: 'Timeout' });
        const count = period === 'calendar' ? 100 : 200;
        const rows = {
          summary: [[count, 50, '2025-01-01', '2025-12-31']],
          views: [[1, `${period} document`, count, 50]],
          downloads: [[1, `${period} download`, count, 50]],
          types: [['Document', count, 50]],
          collections: [[2, 'Collection', 1, count, 50]],
          countries: [['US', count, 50]],
        };
        await route.fulfill({ json: { updated: '2026-09-25T00:00:00Z', period, [report]: { rows: rows[report], ids: [3] } } });
      }
      finally { active--; }
    });
    await page.goto('https://usage.test/usage?period=calendar');
    await page.evaluate(() => {
      window.Drupal = { behaviors: {} };
      window.drupalSettings = { lehighAnalytics: {
        dataUrl: '/usage/data', optionsUrl: '/usage/options/field_model',
        exportUrl: '/usage/export/views', nodeUrl: '/node/NODE',
      } };
      window.once = (id, selector, context) => [...context.querySelectorAll(selector)];
    });
    await page.addScriptTag({ path: path.join(root, 'js/explorer.js') });
    await page.evaluate(() => Drupal.behaviors.lehighUsageExplorer.attach(document));
    await page.waitForFunction(() => !document.querySelector('[data-retry]').hidden);
    assert.equal(await page.locator('[data-results]').isVisible(), true);
    assert.equal(await page.locator('[data-kpi="views"]').textContent(), '100');
    assert.match(await page.locator('[data-countries]').textContent(), /Unable to load/);
    assert.match(await page.locator('[data-works]').textContent(), /Loading document/);
    slowViews.release();
    slowViews = null;
    await page.waitForFunction(() => document.querySelector('[data-status]').textContent.includes('5 of 6'));
    assert.equal(maximum, 2, 'At most two concurrent report requests');
    assert.equal(new Set(seen.map(([report]) => report)).size, 6);
    countryFails = false;
    await page.locator('[data-retry]').click();
    await page.waitForFunction(() => document.querySelector('[data-status]').textContent.includes('6 of 6'));
    assert.equal(await page.locator('[data-retry]').isVisible(), false);
    assert.equal(await page.locator('[data-kpi="countries"]').textContent(), '1');
    staleViews = gate();
    await page.locator('[name="period"]').selectOption('fiscal');
    await page.waitForFunction(() => document.querySelector('[data-kpi="views"]').textContent === '200');
    await page.locator('[name="period"]').selectOption('calendar');
    await page.waitForFunction(() => document.querySelector('[data-status]').textContent.includes('6 of 6'));
    staleViews.release();
    await page.waitForTimeout(100);
    assert.equal(await page.locator('[data-kpi="views"]').textContent(), '100');
    assert.match(await page.locator('[data-works]').textContent(), /calendar document/);
    assert(!new URL(page.url()).searchParams.has('report'), 'Report selector stays out of shared links');
    assert.deepEqual(scriptErrors, []);
    console.log('PASS: partial rendering, isolated failure, retry, concurrency, and stale-request cancellation');
  }
  finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
