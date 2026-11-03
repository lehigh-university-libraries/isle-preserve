# Source browser imports

The archived `gunn-index.xlsx` was downloaded from the stakeholder's temporarily public [three-tab workbook](https://docs.google.com/spreadsheets/d/1yaas4fu53AYM_lNeKuqrhJIuV-4elTM-l-l0q0vIoww/edit) on 2026-10-05. The workbook is retained unchanged. `php prepare.php` regenerates the CSV exports using only standard libraries and normalizes integral spreadsheet numbers. Tabs: `gunn_volumes` (22 rows), `gunn_subjects` (4,664), and `gunn_index` (33,118). All subject/volume references and page ranges validated. The workbook contains “New York picayune.” and “New Orleans picayune.”, but no exact “Picayune Office” subject.

The post-update applies the dates in the three CSV files in this directory (`vfair.csv`, `sat.csv`, and `nyleader.csv`), matching `field_identifier` to `pfaffs_CDM_ID`. It creates revisions for changed dates and logs unmatched identifiers. `legacy-issue-dates.csv` contains the explicit issue mappings in `frontend/pfaffs/index.php`, supplied by the stakeholder as the date document: 157 Saturday Press, 130 Vanity Fair, 609 NY Leader issues. Each row preserves the legacy pointer as `digitalcollections:pfaffs_{pointer}`, with its date normalized to YYYY-MM-DD. Repeated volume/issue numbers are intentional; never use them as unique identifiers. Gunn's 22 volume pointers have no dates in that PHP file.

On the target Drupal site, run database updates and clear caches, which enable **Display Hints → Journal Browser** for the specified Pfaffs collections and create the newspaper collections. For preview, sign in and open `/pfaffs?source=COLLECTION_NODE_ID`. The post-update moves matching newspaper items from node 26 into the new collections while preserving their other memberships. The display hint makes the collection available to the browser without replacing its normal collection page. Existing collection navigation remains in place; the browser is limited to authenticated users. Imports resolve exact existing `field_pid` values within the specified collection. They never fabricate missing nodes. The automatic date update runs during `drush updatedb`; the separate manual imports below default to a dry run; any invalid mapping prevents an applied batch from writing. Inspect the report before adding `--apply`.

```sh
drush source-browser:import-index GUNN_COLLECTION_NODE_ID /path/to/source-browser
drush source-browser:import-dates SATURDAY_PRESS_COLLECTION_NODE_ID /path/to/legacy-issue-dates.csv spress
drush source-browser:import-dates VANITY_FAIR_COLLECTION_NODE_ID /path/to/legacy-issue-dates.csv vfair
drush source-browser:import-dates NY_LEADER_COLLECTION_NODE_ID /path/to/legacy-issue-dates.csv nyleader
drush source-browser:import-transcriptions GUNN_COLLECTION_NODE_ID /path/to/gunn_transcripts
```

Index rows become managed source-local records with subject taxonomy and exact volume, starting-page node, and printed page range. Subjects are also attached to the starting page for native subject browsing/search. Reruns update changed rows and skip unchanged rows. Date conflicts require explicit `--overwrite`; date/part updates create revisions. Transcript files must be UTF-8 `{legacy Gunn page pointer}.txt`; imports create private managed files and document media with transcription use 20. Alternatively, pass `gunn.csv` directly to the same command:

```sh
drush source-browser:import-transcriptions GUNN_COLLECTION_NODE_ID /var/www/drupal/scripts/migration/source-browser/gunn.csv
drush source-browser:import-transcriptions GUNN_COLLECTION_NODE_ID /var/www/drupal/scripts/migration/source-browser/gunn.csv --apply
```

CSV mode uses `node_id` as the target, verifies its `field_pid` and source membership, and skips blank/volume-level URLs. Dry runs validate mappings without downloads or writes. Applying downloads the reviewed HTTPS transcript URLs into `private://source-browser/downloads/gunn/{pointer}.txt`; reruns reuse valid cached text. It creates or updates an importer-owned document with media-use term 20 under `private://source-browser/transcriptions`; existing OCR media are preserved. Unchanged published media are kept. Download failures leave existing media intact and appear in the final report; a rerun retries missing downloads. Remove a cached transcript to fetch a revised remote copy. Files larger than 10 MB, HTML responses, invalid UTF-8, redirects, and mismatched node/PID mappings are rejected.

The real Gunn corpus is absent from the local development database. The full workbook has been downloaded and validated, but has not been applied to staging/production. The three new subcollection names remain unspecified. Legacy redirects are deferred at the user's request.

## Read-only checks and UX review

Run `php scripts/migration/source-browser/check-data.php` from the repository root, or pass `--directory /path/to/source-browser` and `--subject 'name'`. Redirect output to a file to review possible duplicate subject labels, semicolon labels, and all workbook references for the selected subject. Exit status 1 indicates CSV validation errors; 2 indicates an unreadable or malformed input. Candidate duplicates and semicolons are review prompts, not errors or instructions to merge/split. This script does not connect to Drupal, check URLs, verify image/transcript contents, or establish live hierarchy and permissions. Use the import commands' dry runs for live mappings.


To inventory live collection terms, including unpublished descendants and subcollections, run as an administrator with Drush:

```sh
drush php:script /path/to/source-browser/report-terms.php -- COLLECTION_NODE_ID > terms.csv
```

The CSV has one row per node/field/term reference, the original and HTML-decoded labels, proposed semicolon split parts, review flags, and term IDs with the same normalized label in the same vocabulary. HTML character references are decoded without treating their terminating semicolons as separators; replacement characters (�) are flagged for review because their original text cannot be recovered automatically. Run `php scripts/migration/source-browser/test-term-normalize.php` to check the splitting logic. These are candidates for staff review, not automatic corrections. It includes the root and guards against cycles and multiple parents. Keep the report appropriately protected: it includes unpublished titles. The report does not modify terms and does not include standalone curated index records that have no page subject reference.

To convert a newspaper CSV with headers `Vol/Iss,Date,CDM ID` to the existing importer format:

```sh
php scripts/migration/source-browser/convert-dates.php newspaper.csv spress > newspaper-mapped.csv
drush source-browser:import-dates COLLECTION_NODE_ID /absolute/path/newspaper-mapped.csv spress
```

Use the same collection key in both commands. The converter assumes CDM IDs correspond to `digitalcollections:pfaffs_ID`, preserves issue-number leading zeroes, and rejects malformed dates and duplicate IDs. Review the generated PID mappings before import. The second command is a dry run; after resolving reported errors, repeat with `--apply`. Avoid redirecting the output over an existing valuable file: the shell opens it before validation. The existing `legacy-issue-dates.csv` already includes the example Saturday Press and Vanity Fair rows.

## Transcript search

The Search API OCR processor prefers accessible media with transcription use 20,
extracting text from PDF transcripts with `pdftotext` or reading plain-text
transcripts. If none yields text, it falls back to accessible extracted OCR.
PDF extraction requires the Poppler utility installed in the Drupal image.
After adding or changing transcript media, reindex the affected nodes in Search
API so their new text becomes searchable. Scanned PDFs without a text layer
need OCR before their contents can be searched.

## Headless Chromium checks

The existing `web/modules/custom/lehigh_islandora/tests/Browser/check.cjs` exercises the preview, Mirador page changes, transcription, keyboard navigation, search focus, index expansion, responsive layout and the anonymous gate. It requires Playwright with Chromium, a running **disposable** Drupal site, and fixture entities. Do not create the fixtures on production. The CI entry point `scripts/ci/source-browser.sh` creates and cleans its fixture automatically on the disposable CI site. For manual runs, pass a unique `/tmp/source-browser-fixture-*.json` state path to `fixture.php` after `--`, save its JSON output, and run `cleanup.php` with the same state path afterward. Both fixture scripts restrict operation to the configured disposable test sites.

The browser routes require an authenticated user. Generate a one-time login URL for a disposable test account on that site and pass it without printing or storing it in shared logs:

```sh
BROWSER_BASE_URL=https://your-test-site \
BROWSER_LOGIN_URL='one-time-login-url' \
PLAYWRIGHT_MODULE=/path/to/playwright \
node web/modules/custom/lehigh_islandora/tests/Browser/check.cjs /path/to/fixture.json
```

The login URL must use the same host as `BROWSER_BASE_URL`. Alternatively, set `BROWSER_STORAGE_STATE` to a Playwright storage-state JSON file from an authenticated test session and omit `BROWSER_LOGIN_URL`. Protect that file as a credential. For a local site whose image service is unavailable, `BROWSER_IMAGE_FIXTURE` can point to a local PNG; this mocks IIIF image responses, so repeat the viewer check against a real image service. Screenshots go to `BROWSER_OUTPUT_DIR` (default `/tmp/source-browser-preview`). This automated suite does not replace a screen reader, touch-device, Firefox or Safari review.
