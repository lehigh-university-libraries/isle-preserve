// Run against a disposable site: opening pages can record usage metrics.
const assert = require('node:assert/strict');
const { chromium } = require('playwright');

(async () => {
  const urls = process.argv.slice(2);
  assert.equal(urls.length, 4, 'Supply browse, collection, compound, and item URLs.');
  const args = JSON.parse(process.env.CHROMIUM_ARGS || '[]');
  const browser = await chromium.launch({ args });
  try {
    const page = await browser.newPage({ ignoreHTTPSErrors: true });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    for (const [index, url] of urls.entries()) {
      await page.setViewportSize({ width: 1440, height: 1000 });
      const response = await page.goto(url, { waitUntil: 'networkidle' });
      assert.equal(response.status(), 200, url);
      assert.equal(await page.locator('#main-content').count(), 1, url);
      // Catch legacy named-grid rules creating implicit columns or extra rows.
      for (const layout of await page.locator('.lehigh-content-layout.with-sidebar').all()) {
        const boxes = await layout.evaluate(element => [...element.children]
          .filter(child => child.getBoundingClientRect().width > 0)
          .map(child => {
            const box = child.getBoundingClientRect();
            return { y: box.y, width: box.width };
          }));
        assert(boxes.every(box => box.width >= 190), `${url}: squeezed column`);
        assert(boxes.every(box => Math.abs(box.y - boxes[0].y) < 2), `${url}: misplaced sidebar`);
      }
      for (const width of [390, 768]) {
        await page.setViewportSize({ width, height: 844 });
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${url}: overflow at ${width}`);
        if (index < 3) {
          const toggle = page.locator('.islandora-mobile-filter-toggle');
          assert.equal(await toggle.count(), 1, `${url}: duplicate or missing filters`);
          assert((await toggle.boundingBox()).height >= 44, `${url}: small filter target`);
          await toggle.click();
          assert.equal(await toggle.getAttribute('aria-expanded'), 'true');
          const sidebar = page.locator('aside[data-islandora-sidebar="primary"]').first();
          await sidebar.locator('input[type="submit"]').first().focus();
          await page.keyboard.press('Escape');
          assert.equal(await toggle.getAttribute('aria-expanded'), 'false');
          assert(await toggle.evaluate(element => element === document.activeElement));
        }
      }
      console.log(`PASS ${url}`);
    }
    assert.deepEqual(errors, []);
  }
  finally {
    await browser.close();
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
