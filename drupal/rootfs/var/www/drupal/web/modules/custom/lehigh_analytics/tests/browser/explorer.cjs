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
      .replace(/{% for name, label in fields %}.*?{% endfor %}/s, '<option value="field_model">Model</option><option value="field_genre">Genre</option><option value="field_language">Language</option>')
      .replace(/{{.*?}}/gs, '#');
    let slowViews = gate();
    let staleViews;
    let countryFails = true;
    let active = 0;
    let maximum = 0;
    const seen = [];
    const optionQueries = [];
    let optionsFail = false;
    let staleOptions;
    let noFields = false;
    let fieldsFail = false;
    await page.route('https://usage.test/**', async route => {
      const url = new URL(route.request().url());
      if (url.pathname === '/usage') return route.fulfill({ contentType: 'text/html', body: html });
      if (url.pathname === '/usage/fields') return fieldsFail ? route.fulfill({ status: 500, body: 'Failed' }) : route.fulfill({ json: noFields ? [] : ['field_model', 'field_genre'] });
      if (url.pathname.startsWith('/usage/options')) {
        optionQueries.push(url);
        const term = url.searchParams.get('q');
        if (term === 'slow' && staleOptions) await staleOptions.promise;
        if (optionsFail) return route.fulfill({ status: 500, body: 'Failed' });
        const values = Array.from({ length: 55 }, (_, i) => ({ id: i + 1, label: `Value ${String(i + 1).padStart(2, '0')}` }));
        const matches = values.filter(row => row.label.toLowerCase().includes(term.toLowerCase()));
        const offset = Number(url.searchParams.get('offset'));
        return route.fulfill({ json: { rows: matches.slice(offset, offset + 50), more: matches.length > offset + 50 } });
      }
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
          countries: period === 'calendar' ? [['US', count, 50], ['Unknown', 30, 6], ['PR', 20, 4], ['GU', 10, 2]] : [['Unknown', count, 50]],
        };
        await route.fulfill({ json: { updated: '2026-09-25T00:00:00Z', period, [report]: { rows: rows[report], ids: [3] } } });
      }
      finally { active--; }
    });
    await page.goto('https://usage.test/usage?period=calendar');
    await page.evaluate(() => {
      window.Drupal = { behaviors: {} };
      window.drupalSettings = { lehighAnalytics: {
        dataUrl: '/usage/data', optionsUrl: '/usage/options/field_model', fieldsUrl: '/usage/fields',
        exportUrl: '/usage/export/views', nodeUrl: '/node/NODE',
      } };
      window.once = (id, selector, context) => [...context.querySelectorAll(selector)];
    });
    const core = path.resolve(root, '../../../core');
    for (const file of [
      'assets/vendor/jquery/jquery.min.js', 'assets/vendor/jquery.ui/ui/version-min.js',
      'assets/vendor/jquery.ui/ui/widget-min.js', 'assets/vendor/jquery.ui/ui/keycode-min.js',
      'assets/vendor/jquery.ui/ui/unique-id-min.js', 'misc/position.js',
      'assets/vendor/jquery.ui/ui/widgets/menu-min.js', 'assets/vendor/jquery.ui/ui/widgets/autocomplete-min.js',
    ]) await page.addScriptTag({ path: path.join(core, file) });
    for (const file of ['core', 'autocomplete', 'menu', 'theme']) {
      await page.addStyleTag({ path: path.join(core, `assets/vendor/jquery.ui/themes/base/${file}.css`) });
    }
    await page.addStyleTag({ path: path.join(root, 'css/explorer.css') });
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
    assert.equal(await page.locator('[data-kpi="countries"]').textContent(), '3');
    assert.equal(await page.locator('[data-kpi="countries"]').locator('..').locator('span').textContent(), 'Countries & Territories');
    assert.equal(await page.locator('.lu-countries th').first().textContent(), 'Country / Territory');
    assert.deepEqual(await page.locator('[data-countries] tr td:first-child').allTextContents(), ['United States', 'Puerto Rico', 'Guam']);
    assert.equal(await page.locator('[data-geo-note]').textContent(), 'Ranked by page views. Unknown location: 30 views, 6 downloads.');
    staleViews = gate();
    await page.locator('[name="period"]').selectOption('fiscal');
    await page.waitForFunction(() => document.querySelector('[data-kpi="views"]').textContent === '200');
    await page.waitForFunction(() => document.querySelector('[data-kpi="countries"]').textContent === '0');
    assert.equal(await page.locator('[data-countries] tr').count(), 0);
    assert.match(await page.locator('[data-geo-note]').textContent(), /Unknown location: 200 views, 50 downloads/);
    await page.locator('[name="period"]').selectOption('calendar');
    await page.waitForFunction(() => document.querySelector('[data-status]').textContent.includes('6 of 6'));
    staleViews.release();
    await page.waitForTimeout(100);
    assert.equal(await page.locator('[data-kpi="views"]').textContent(), '100');
    assert.match(await page.locator('[data-works]').textContent(), /calendar document/);
    assert(!new URL(page.url()).searchParams.has('report'), 'Report selector stays out of shared links');
    // Only nonempty fields are offered. Search reaches beyond the first page.
    await page.waitForFunction(() => !document.querySelector('[data-search]').disabled);
    assert.deepEqual(await page.locator('[data-field] option').allTextContents(), ['Model', 'Genre']);
    const search = page.locator('[data-search]');
    await search.fill('Value');
    await page.waitForFunction(() => document.querySelectorAll('.ui-menu-item').length === 50);
    await page.locator('[data-more-options]').click();
    await page.waitForFunction(() => document.querySelectorAll('.ui-menu-item').length === 55);
    assert.equal(optionQueries.at(-1).searchParams.get('offset'), '50');
    await search.fill('55');
    await page.waitForFunction(() => document.querySelectorAll('.ui-menu-item').length === 1);
    await search.press('ArrowDown');
    await search.press('Enter');
    await page.waitForFunction(() => document.querySelectorAll('[data-chips] button').length === 1 && !document.querySelector('[data-search]').disabled);
    assert.deepEqual(new URL(page.url()).searchParams.getAll('filters[field_model][]'), ['55']);
    await search.fill('54');
    await page.waitForFunction(() => document.querySelector('.ui-menu-item')?.textContent === 'Value 54');
    await page.locator('.ui-menu-item').click();
    await page.waitForFunction(() => document.querySelectorAll('[data-chips] button').length === 2 && !document.querySelector('[data-search]').disabled);
    assert.deepEqual(new URL(page.url()).searchParams.getAll('filters[field_model][]'), ['55', '54']);
    await page.locator('[data-field]').selectOption('field_genre');
    await search.fill('53');
    await page.waitForFunction(() => document.querySelector('.ui-menu-item')?.textContent === 'Value 53');
    assert.deepEqual(optionQueries.at(-1).searchParams.getAll('filters[field_model][]'), ['55', '54']);
    assert.equal(optionQueries.at(-1).searchParams.get('period'), 'calendar');
    await page.locator('.ui-menu-item').click();
    await page.waitForFunction(() => document.querySelectorAll('[data-chips] button').length === 3 && !document.querySelector('[data-search]').disabled);
    await page.locator('[data-chips] button').first().click();
    await page.waitForFunction(() => document.querySelectorAll('[data-chips] button').length === 2 && !document.querySelector('[data-search]').disabled);
    assert.deepEqual(new URL(page.url()).searchParams.getAll('filters[field_model][]'), ['54']);
    assert.deepEqual(new URL(await page.locator('[data-export="countries"]').getAttribute('href'), page.url()).searchParams.getAll('filters[field_model][]'), ['54']);
    // A failed or superseded search must not show stale choices.
    optionsFail = true;
    await search.fill('51');
    await page.waitForFunction(() => document.querySelector('[data-options-status]').textContent.includes('Could not load'));
    optionsFail = false;
    staleOptions = gate();
    await search.fill('slow');
    await page.waitForRequest(request => new URL(request.url()).searchParams.get('q') === 'slow');
    await search.fill('52');
    await page.waitForFunction(() => document.querySelector('.ui-menu-item')?.textContent === 'Value 52');
    staleOptions.release();
    await page.waitForTimeout(100);
    assert.equal(await page.locator('.ui-menu-item').textContent(), 'Value 52');
    noFields = true;
    await page.locator('[name="period"]').selectOption('fiscal');
    await page.waitForFunction(() => document.querySelector('[data-options-status]').textContent.includes('No metadata choices'));
    assert.equal(await search.isDisabled(), true);
    assert.equal(await page.locator('[data-field] option').count(), 0);
    assert.equal(await page.locator('[data-chips] button').count(), 2, 'Active filters stay removable even when no values match');
    fieldsFail = true;
    await page.locator('[name="period"]').selectOption('calendar');
    await page.waitForFunction(() => !document.querySelector('[data-retry-filters]').hidden);
    fieldsFail = noFields = false;
    await page.locator('[data-retry-filters]').click();
    await page.waitForFunction(() => !document.querySelector('[data-search]').disabled);
    assert.deepEqual(await page.locator('[data-field] option').allTextContents(), ['Model', 'Genre']);
    assert.deepEqual(scriptErrors, []);
    console.log('PASS: paged autocomplete, multiple filters, empty choices, search failure/cancellation, geographic labels, separate territories, unknown locations, partial rendering, isolated failure, retry, concurrency, and stale-request cancellation');
  }
  finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
