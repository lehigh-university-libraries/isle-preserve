# Lehigh theme

Preserve's branding, navigation, cards, and page regions extend Islandora DXPR.
Drupal core owns responsive Views grids. DXPR owns form markup, pagination,
search controls, and mobile filter behavior. The small Composer patch makes
DXPR's filter selectors work with Lehigh's regions and exposed-form blocks.

The Stop14 JavaScript bundle, mobile toolbar, metadata-hiding script, pager
and form overrides, and custom Views grid plugin are retired. Remaining legacy
CSS and templates still support editorial pages and Lehigh-specific displays.

## Deployment

Use the normal Composer install and configuration import, then rebuild Drupal
caches. Browse, related items, featured collections, subcollections, and top-level
collections now use core's Responsive Grid style.

Search API stores rendered card HTML in Solr. Mark the existing index's content
for reindexing and process it to refresh cards; do not clear the live index just
to change a template. Until reindexing finishes, search results retain old cards.

The custom canonical HTML disk cache under `private://canonical` survives
`drush cr`. Purge that generated cache through the deployment cache workflow,
or regenerate representative URLs with `?cache-warmer=1` while reviewing.

## UI checks

With Playwright and Chromium installed, run the check against a disposable site
with representative browse, collection, compound, and item URLs, in that order:

```sh
node tests/check-layout.cjs \
  'https://islandora.io/browse?cache-warmer=1' \
  'https://islandora.io/browse-items/COLLECTION_ID?cache-warmer=1' \
  'https://islandora.io/browse-items/COMPOUND_ID?cache-warmer=1' \
  'https://islandora.io/node/ITEM_ID?cache-warmer=1'
```

`NODE_PATH` can point to an existing Playwright installation. `CHROMIUM_ARGS`
accepts a JSON array of Chromium flags for a local container environment.
The check covers desktop column alignment, mobile overflow, filter target size,
Escape/focus behavior, and JavaScript errors. Separately verify real search
results, pagination, grid/list switching, and representative media viewers on a
site with working Solr and media storage; layout checks do not establish those.
