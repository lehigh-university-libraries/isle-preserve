# Source browser imports

The archived `gunn-index.xlsx` was downloaded from the stakeholder's temporarily public [three-tab workbook](https://docs.google.com/spreadsheets/d/1yaas4fu53AYM_lNeKuqrhJIuV-4elTM-l-l0q0vIoww/edit) on 2026-10-05. The workbook is retained unchanged. `python3 prepare.py` regenerates the CSV exports using only standard libraries and normalizes integral spreadsheet numbers. Tabs: `gunn_volumes` (22 rows), `gunn_subjects` (4,664), and `gunn_index` (33,118). All subject/volume references and page ranges validated. The workbook contains “New York picayune.” and “New Orleans picayune.”, but no exact “Picayune Office” subject.

`legacy-issue-dates.csv` contains the explicit issue mappings in `frontend/pfaffs/index.php`, supplied by the stakeholder as the date document: 157 Saturday Press, 130 Vanity Fair, 609 NY Leader issues. Each row preserves the legacy pointer as `digitalcollections:pfaffs_{pointer}`, with its date normalized to YYYY-MM-DD. Repeated volume/issue numbers are intentional; never use them as unique identifiers. Gunn's 22 volume pointers have no dates in that PHP file.

On the target Drupal site, run database updates and clear caches, then enable **Display Hints → Journal Browser** on each collection. Imports resolve exact existing `field_pid` values within the specified collection. They never fabricate missing nodes. All commands default to a dry run; any invalid mapping prevents an applied batch from writing. Inspect the report before adding `--apply`.

```sh
drush source-browser:import-index GUNN_COLLECTION_NODE_ID /path/to/source-browser
drush source-browser:import-dates SATURDAY_PRESS_COLLECTION_NODE_ID /path/to/legacy-issue-dates.csv spress
drush source-browser:import-dates VANITY_FAIR_COLLECTION_NODE_ID /path/to/legacy-issue-dates.csv vfair
drush source-browser:import-dates NY_LEADER_COLLECTION_NODE_ID /path/to/legacy-issue-dates.csv nyleader
drush source-browser:import-transcriptions GUNN_COLLECTION_NODE_ID /path/to/gunn_transcripts
```

Index rows become managed source-local records with subject taxonomy and exact volume, starting-page node, and printed page range. Subjects are also attached to the starting page for native subject browsing/search. Reruns update changed rows and skip unchanged rows. Date conflicts require explicit `--overwrite`; date/part updates create revisions. Transcript files must be UTF-8 `{legacy Gunn page pointer}.txt`; imports create private managed files and extracted-text media. Alternatively, pass `gunn.csv` directly to the same command:

```sh
drush source-browser:import-transcriptions GUNN_COLLECTION_NODE_ID /var/www/drupal/scripts/migration/source-browser/gunn.csv
drush source-browser:import-transcriptions GUNN_COLLECTION_NODE_ID /var/www/drupal/scripts/migration/source-browser/gunn.csv --apply
```

CSV mode uses `node_id` as the target, verifies its `field_pid` and source membership, and skips blank/volume-level URLs. Dry runs validate mappings without downloads or writes. Applying downloads the reviewed HTTPS transcript URLs into `private://source-browser/downloads/gunn/{pointer}.txt`; reruns reuse valid cached text. It replaces the node's extracted-text media with managed media at the active `get_ocr_from_image` action's private destination (normally `private://derivatives/ocr/{node_id}.txt`). Unchanged published media are kept. Download failures leave existing media intact and appear in the final report; a rerun retries missing downloads. Remove a cached transcript to fetch a revised remote copy. Files larger than 10 MB, HTML responses, invalid UTF-8, redirects, and mismatched node/PID mappings are rejected.

The real Gunn corpus is absent from the local development database. The full workbook has been downloaded and validated, but has not been applied to staging/production. The three new subcollection names remain unspecified. Legacy redirects are deferred at the user's request.
