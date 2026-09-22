# Lehigh Analytics

Public usage explorer for The Preserve at `/usage`, with staff review at
`/admin/reports/lehigh-analytics/review` (Reports → The Preserve usage).

The dashboard loads reports independently, with at most two data requests in
flight. `/usage/data?period=calendar` returns the summary; add `report=types`,
`views`, `downloads`, `collections`, or `countries` to request another report.
Each response includes the reporting period and refresh timestamp. Successful
reports remain visible if another fails, with a retry button for the request.
This keeps six cold database queries from sharing one 60-second HTTP timeout.
Deploy the controller, JavaScript, and template together and run `drush cr`.
This change does not require rebuilding the saved usage totals.

The interface follows the visual language of
[phewit01/lehigh-research-explorer](https://github.com/phewit01/lehigh-research-explorer):
a warm neutral palette, masthead, summary cards, metadata filters, clickable
model/collection charts, ranked country list, document rankings, and dark mode.
It uses Drupal, native CSS, and browser APIs without a new frontend dependency.

## Install or upgrade

From the Drupal project root:

```sh
vendor/bin/drush en lehigh_analytics -y
vendor/bin/drush updatedb -y
vendor/bin/drush cr
vendor/bin/drush lehigh-analytics:refresh
```

**Run the last command on staging after deploying.** It backfills derived totals
outside the 60-second web request limit. Interrupted runs resume at the last
committed event ID. Until the initial backfill completes, the public page shows
“Usage data is being prepared”; it never silently scans raw history or presents
partial totals as complete.

Normal Drupal cron processes up to 500 new events per invocation. For a busy
repository, schedule `drush lehigh-analytics:refresh` regularly (for example every
five minutes) to catch up fully. The command processes 5,000 source rows per
transaction under a refresh lock. Public requests and aggregate blocks query
persisted all-time/calendar/fiscal totals instead of raw events, including on
cold caches and with new filters. Report results are also cached for five minutes
and invalidated by refreshes and metadata changes. Update timestamps indicate
when the latest batch was processed, not when a reader opened the page.

Only background refresh reads the source history. Each eligible event contributes
to three indexed buckets: all time, its local calendar year, and its fiscal year.
The event-ID checkpoint and counts commit together, so retries cannot double
usage. Late-arriving events go into their original date bucket. Future-dated
records pause ingestion with an error rather than contaminating current totals.
Deleted/edited source events and changed region assignments need an explicit
rebuild; timezone or fiscal-month changes make reports unavailable until rebuilt:

```sh
vendor/bin/drush lehigh-analytics:refresh --rebuild
```

A rebuild replaces only derived data and leaves source events intact. Reports
show the preparation state until it finishes. Resume an interrupted rebuild with
`lehigh-analytics:refresh` without `--rebuild`. There is no automatic attempt to
repair Entity Metrics geolocation. Country names in existing region records are
resolved when reports run; event-to-region reassignment requires a rebuild.

## Public access and staff review

The explorer, aggregate CSVs at `/usage/export/{report}`, and aggregate blocks
are public. Every report uses **anonymous node grants**, even for a logged-in
administrator, and includes only currently published records. Collection labels
and metadata node suggestions use the same grants. Anonymous users must have
Drupal's `access content` permission. No session IDs, IP addresses, or raw events
are available through these public endpoints.

Grant **Review and export Lehigh usage analysis** (`view lehigh analytics`) to
staff who need the review form and anomaly analysis. Anomaly blocks and exports
retain this permission and remain outside the public explorer. The staff form
runs only the selected report after **Run report**; session-based anomaly
analysis still queries source events and should use a narrow period and scope
on large histories. COUNTER/ACRL exports are explicitly preparation worksheets.

Choose all recorded time, last completed calendar year, last completed fiscal
year, calendar year to date, or current fiscal year to date. Fiscal years begin
July 1, using `America/New_York`. Dates use an inclusive start and exclusive end;
YTD includes the current year through the latest processed event. On September 22, 2026 the completed periods
are January–December 2025 and July 2025–June 2026. See the
[Lehigh fiscal calendar](https://financeadmin.lehigh.edu/content/controllers-office-calendars).

Available reports show separate page-view/download counts by **Islandora Model**,
separate top-100 document rankings for views and downloads, and country counts
ranked by page views, then downloads. Every table has a CSV export that retains
the filters, grouping, reporting period, timezone, and data source. Ties in
document rankings are resolved by node ID. Empty periods produce an empty table
and a CSV with headers, never fabricated usage.

Filter by parent collection, genre, model, or any other taxonomy/node reference
field attached to `islandora_object`. Multiple values in one field are OR;
different fields are AND. Parent collection is **direct membership**, not a
recursive collection tree. Available taxonomy reference fields can also replace
Model as the grouping. Text/date/numeric metadata filters are not included.

## Embeddable blocks

In Drupal Block layout or Layout Builder, add **The Preserve usage report** in
the **Lehigh Analytics** category (`lehigh_analytics_usage`). Add multiple
instances to display different reports. Aggregate blocks are public; anomaly
blocks require the staff review permission. Blocks show a preparation message
until saved usage totals are ready.

Each instance selects a report, period, content-type grouping, and the same
metadata filters as the page. **Collection scope** offers:

* **Use saved metadata filters**: persist any combination of supported fields.
* **Current collection**: use the current published Collection-model node as the
  parent-collection filter. Other saved filters still apply. This replaces any
  saved parent-collection selection. On a non-collection page the block is hidden;
  it never silently expands to repository-wide usage.

Top-document reports can rank the filtered scope as a whole or produce a separate
top 100 **within every content type**. Page views and downloads have separate
rankings. The collection report includes page views, downloads, and distinct works
used among each collection's direct members. A work in several collections counts
in each, so these rows must not be summed for a repository total. Collection
landing-page usage is not added to its own members' usage. Nested descendants
are not recursively rolled up.

## Anomalous usage

The page and blocks offer **Page-view spikes for review**, by individual work or
by collection's direct members. Defaults are 50 views in a complete UTC day and
at least 5 times the daily average of the previous 28 days, including zero-use
days. A zero baseline produces “No prior usage” rather than an infinite ratio.
The baseline window (7–90 days), multiplier, minimum views, and concentration
threshold are configurable using the same controls on the page and blocks.

The baseline can extend before the selected reporting period. The first and last
partial UTC days are excluded, including the current day. Detection requires a
full baseline after the first available recorded view and the object's creation
date. This avoids treating newly observed history as a normal baseline, but it
cannot infer missing tracking days.

Each result includes its daily count, baseline mean, ratio, known session count,
largest session's share of all views, and number of views with no session. A
single session supplying at least 50% of views is marked concentrated by default;
other spikes are marked distributed, or inconclusive when most session IDs are
missing. Session concentration does not prove automation, and multiple sessions
do not prove human readership. No visitor identifiers are exposed, no IP lookups
are performed, and no counts are automatically removed. CSV exports retain the
thresholds and scope for review.

## COUNTER and ACRL download actions

The page and blocks provide two CSV download links:

* `/usage/export/counter`: a **mapping worksheet** with
  candidate Total_Item_Investigations (recorded work visits plus media requests)
  and Total_Item_Requests (media requests). Unique metrics are explicitly marked
  unavailable. The existing events cannot substantiate successful full-content
  delivery, robot exclusion, or double-click processing. This is not a COUNTER
  Item Report, SUSHI response, or claim of compliance. See the official
  [COUNTER processing rules](https://cop5.projectcounter.org/en/5.1/07-processing/02-double-click-filtering.html)
  and [unique-item rules](https://cop5.projectcounter.org/en/5.1/07-processing/03-counting-unique-items.html).
* `/usage/export/acrl`: an **ACRL preparation worksheet**
  mapped to question 51 in the
  [2025 survey instructions](https://acrl.libguides.com/ld.php?content_id=82791234).
  That question prefers repository downloads and asks respondents to identify
  the measurement used. The export identifies recorded media requests as the
  source, includes page views separately as context, and discloses that human-only
  usage is unverified. Select the desired completed fiscal year and confirm the
  applicable survey year, file scope, and coverage before submission.

Both downloads preserve the active filters and period. They are working CSV
crosswalks, not final submission files. Final standards-specific formats and any
additional deduplication/exclusions need confirmed reporting requirements.

## Sources and definitions

* `entity_metrics_data.entity_type = node`: recorded page views.
* `entity_type = media`: recorded file requests, joined to the owning node via
  `media__field_media_of`, using the media's default language. Repeated references
  and translations do not multiply usage.
* Only currently published `islandora_object` nodes are included. Metadata and
  publication status reflect the current default-language record, not a
  historical snapshot. Deleted nodes and unlinked/deleted media cannot be
  attributed. Counts exclude events where `cookie_set != 0`.
* The top-document reports include repository works of all models except
  Collection (`http://purl.org/dc/dcmitype/Collection`) and Page
  (`http://id.loc.gov/ontologies/bibframe/part`). Component-page usage is not
  rolled up to its parent document. Collection and Page usage is still present
  in the content-type and country reports.
* A multivalued grouping field contributes once per distinct term per event.
  Its rows may overlap; the summary's totals are unduplicated. Filters use EXISTS
  so repeating a metadata value cannot multiply events.
* Country codes come from `entity_metrics_regions`, with missing/blank/unmatched
  regions retained as **Unknown**. Country counts use the same filtered works
  and period as the other reports. Country is the recorded request location,
  not an assertion about a reader's nationality.
* “All time” means all available attributable recorded events, not the lifetime
  of the repository. The summary shows first/last matching events; these do not
  prove continuous coverage. Downloads are not necessarily unique, completed,
  or human-initiated. Review signals do not change the counts; no automatic bot
  removal, session deduplication, or COUNTER normalization is applied.

No IP addresses, session IDs, cities, or raw visitor events are exposed or
exported. CSV text is protected against spreadsheet formula injection. Reporting
does not modify source events or invoke external geolocation services.

## Google Analytics country source

The installed Entity Metrics beta3 geolocation cron contains a `return` before
saving regions. Its download hook also assigns the cookie name using a boolean
expression; historical download staff flags may therefore be incomplete. This
module reads the flags and regions that actually exist; it cannot repair that
history. Tracking bugs should be addressed in Entity Metrics separately.

For staff global-reach reporting, the review form links to Google Analytics and gives instructions for
the existing **LU Islandora Digital Collections - GA4** property. In Reports →
User attributes → Demographic details, select Country, the desired dates, and
sort by the metric being reported. See Google's
[Demographic details documentation](https://support.google.com/analytics/answer/12948931).
GA user totals must be described as users, not as page views or downloads.
Use separate `page_view` and `file_download` event reports if comparing events,
and first verify that those events are collected for the desired scope.
Local metadata filters do **not** apply to the external GA report. Do not combine
GA counts with Entity Metrics counts: coverage and collection rules differ.

The configured source link opens the supplied GA property home page (account
`16612925`, property `273599833`). Follow the country-report navigation above.
No API credentials are configured. A saved country-report URL can replace the
home-page link in `lehigh_analytics.settings:google_analytics_url`; only
`https://analytics.google.com/` URLs are displayed. No API credentials or new
dependencies are required. Staff need access to the GA property.

## Checks

From the Drupal project root, with the existing development dependencies:

```sh
SIMPLETEST_DB=sqlite://localhost/:memory: vendor/bin/phpunit \
  -c web/core/phpunit.xml.dist web/modules/custom/lehigh_analytics/tests
vendor/bin/phpcs -n --standard=Drupal,DrupalPractice --extensions=php,yml \
  web/modules/custom/lehigh_analytics
```

Tests exercise the real Entity Metrics table schema and Drupal SQL queries,
media attribution, repeated/translated references, country normalization and
missing geography, staff/draft/orphan exclusions, metadata filtering, period
boundaries (including fiscal rollover and leap years), independent top-100
rankings, per-type limits, collection attribution, contextual block configuration
and access, anomaly baselines and session evidence, mapping exports, CSV
protection, report/export permissions, a landing page without event queries, and
reuse of cached reports. Public checks cover anonymous node grants (including
restricted collection labels), cold requests without raw-event queries, resumable
backfills, idempotent refresh, late events, and reporting-setting changes.

With Playwright and Chromium available, run
`node web/modules/custom/lehigh_analytics/tests/browser/explorer.cjs` from the
project root (`NODE_PATH` may point to an existing Playwright installation).
It uses mocked responses to check partial rendering, a failed country request,
retry, the two-request limit, and cancellation when the reporting period changes.

After installation, compare one known node's raw node events and its related
media events with the reports, verify both sides of a July 1 boundary, inspect
the Unknown country share, and confirm the GA source and historical coverage
before using the numbers in communications.

Recursive collection rollups, Google Sheets, maps, author/department reporting,
citations, reader testimonials, and the broader 2027 impact page remain outside
this September implementation. The public usage explorer is available now.
