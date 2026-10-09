# Islandora Scribe

A Drupal 10.3/11 module connecting hOCR derivatives to Scribe's full-page
editor and signed publication events. Enable `islandora_scribe` alongside
Islandora and `islandora_hocr`. The module installs the
`generate_hocr_from_an_image_scribe` action; the Tesseract action remains
available.

## Protected runtime settings

Set these values in deployment-owned `settings.php`, using a secret manager
or mounted files. They are deliberately absent from configuration sync:

```php
$settings['islandora_scribe'] = [
  'base_url' => 'https://scribe.example', // HTTPS origin, no path/query.
  'workspace_id' => 42, // PHP integer.
  'webhook_secret' => file_get_contents('/run/secrets/SCRIBE_WEBHOOK_SECRET'),
  'correlation_secret' => file_get_contents('/run/secrets/SCRIBE_CORRELATION_SECRET'),
  // A separate workspace-scoped read key with annotations:read.
  'api_key' => trim(file_get_contents('/run/secrets/SCRIBE_READ_API_KEY')),
];
```

Provision independent random 32–1024 byte secrets before registering the
webhook or starting ingestion. Do not append a newline to the secret files.
The correlation secret is shared only with the Scyllaridae service; the
webhook secret is shared only with Scribe's webhook subscription.

Create a separate **read** API key for Drupal in the configured Scribe
workspace, granting `annotations:read`. Drupal sends it server-side as
`X-Scribe-API-Key` with `X-Scribe-Workspace-ID` when exporting published hOCR.
Keep this key in protected settings or a mounted secret; it never goes into
configuration sync or editor links. Drupal does not require JWT issuance or
renewal to authenticate to Scribe.

Use a **write** key for Scyllaridae ingestion, granting `items:create`,
`items:write`, `transcription:read`, and `annotations:read`. Keep webhook
subscription administration in a separate workspace-admin identity.

Every editor needs an Islandora account with update access to the derivative
media, and separate Scribe workspace membership through Google OAuth.
The **Edit transcription** link contains only workspace/item/image IDs.

## ISLE and Scyllaridae deployment

Build the updated `buildkit/images/scyllaridae-scribe` image. The previous
hOCR-only service does **not** support this module's correlation contract.
Configure the service with:

```dotenv
SCRIBE_API_URL=https://scribe.example
SCRIBE_WORKSPACE_ID=42
SCRIBE_API_TOKEN=<write-api-key-from-secret-manager>
ISLANDORA_SCRIBE_CORRELATION_URL=https://repository.example/islandora-scribe/correlation
ISLANDORA_SCRIBE_CORRELATION_SECRET=<separate-secret-from-secret-manager>
```

The service uses its write API key through `SCRIBE_API_TOKEN`. Its optional
`SCRIBE_API_JWT_FILE` authentication mode remains available if operators choose
an external JWT issuer, but it is not required for this API-key setup.
Never place either credential in Drupal action arguments.

Scyllaridae's command configuration must pass `%source-mime-ext` then `%args`.
The Drupal action supplies a persisted operation ID and source media UUID.
The command reuses that operation as `StartUploadBatch.batchId`, supplies
`externalReferenceId`, and signs a callback carrying the returned item/image
IDs before returning hOCR. Retries must preserve the command arguments and
source bytes. An in-place source-file change requires a new operation after the previous
ingest completes; a digest conflict must be investigated, not bypassed.

Alpaca already supports the following route in
`isle-preserve/conf/alpaca/alpaca.properties.tmpl`:

```properties
derivative.scribe.enabled=true
derivative.scribe.in.stream=queue:islandora-scribe-hocr
derivative.scribe.service.url=<internal Scyllaridae service URL>
```

Set `ALPACA_DERIVATIVE_SCRIBE_URL` to that internal URL. Keep the existing
Tesseract route. Add the callback URL and correlation secret to the service's
Compose environment or Kubernetes Secret references in
`isle-preserve/ci/k8s/scribe.yaml`, and its write API key. Roll out a
newly built immutable image digest; the existing pinned image lacks this
contract. The service's JWKS verification must trust Islandora's queue JWTs.

All Scribe actions target the node's existing hOCR media-use term,
`https://discoverygarden.ca/use#hocr`, using Original File media as the source.
They update that single derivative media, including an existing Tesseract hOCR.
The default upload destination is
`private://derivatives/hocr/[node:nid]/[node:nid]-scribe.hocr`; ensure the file
field allows `.hocr`. Replacement bytes are staged before the media reference
changes, so a failed write leaves the current transcription intact.

If a site already has an action named `generate_hocr_from_an_image_scribe`,
rename/remove the old action before module installation, or import the module's
action YAML after enabling. In configuration sync, change its plugin to
`generate_scribe_hocr_derivative`, its module dependency to `islandora_scribe`,
and its derivative term URI to `https://discoverygarden.ca/use#hocr`. Use the site's normal
config import; never export runtime secrets.

Register `https://repository.example/islandora-scribe/webhook` with
`WebhookService.CreateWebhook` using the separately provisioned workspace
administrator and webhook secret. Expose both POST endpoints directly over
HTTPS without redirects. Configure Drupal's trusted reverse proxies correctly
so `Request::isSecure()` sees HTTPS. The initial derivative PUT endpoint uses
Islandora's existing node-update plus create-media access check and JWT auth.
Apply ingress body limits of 64 KiB for the two signed JSON endpoints, and the
normal derivative upload limit for the PUT endpoint.

Run Drupal cron or a supervised
`drush queue:run islandora_scribe_publication` worker. The publication queue
must use Drupal's reliable **database queue on the same database connection**;
the module rejects other backends because acceptance and enqueue must commit
atomically. Publication workers also lock the mapping row in the database, so
an expired Drupal lock lease cannot let an older revision replace a newer one. Queue items contain only event IDs. Failed exports/storage writes
remain queued. The lock lease is 180 seconds and export timeout is 100 seconds;
file storage must complete within the remaining lease. Event records are kept
for durable deduplication; plan database capacity for publication volume.

## Select processing contexts per action

Create a processing context in Scribe with the registered segmentor and
transcription model you want (for example, your installed Kraken/CATMuS
combination for medieval records). Record its numeric context ID.

At `/admin/config/system/actions`, create an action of type **Generate hOCR
using Scribe**, give it a descriptive label such as **Scribe — medieval CATMuS**,
and set **Scribe processing context ID** to that ID. Create another action for
each context you want to use. The action's exportable configuration includes:

```yaml
context_id: 55 # Example only: replace with your actual Scribe context ID.
```

`0` uses Scribe's automatic context selection. Positive IDs explicitly select
an existing Scribe context; segmentors, models and credentials remain configured
in Scribe. No additional integration-key scope is needed to submit the ID.

Six named actions ship with the context IDs provided for this workspace:

| Action | Context ID |
| --- | ---: |
| Letters + Gemini Pro | 11 |
| Letters + GLM-OCR | 10 |
| Newspapers + Gemini Pro | 19 |
| Newspapers + GLM-OCR | 18 |
| Medieval manuscripts + Gemini Pro | 15 |
| Medieval manuscripts + GLM-OCR | 14 |

All six actions share the Original File source, hOCR media-use term, and
destination path. The selected action replaces the node's single hOCR derivative;
there is no separate derivative per model or document type.

The selected context is immutable while an ingest is pending. After a run has
completed, executing an action reserves a new operation, even for the same
context, and supersedes the previous Scribe association. The old hOCR remains
until the replacement is saved. Queued publications from superseded operations
are skipped; new deliveries for their old Scribe resource tuples are rejected.
Only the current completed operation can publish back to the derivative.

On existing installations, `drush updatedb` installs the six missing actions
and the completed-ingest marker. Existing customized actions are preserved.
If the earlier prototype created media under the separate Scribe media-use term,
reconcile those media with the canonical node hOCR before switching to these
presets; the update does not delete or merge repository media.

For a direct service request, append the context ID to `X-Islandora-Args`:

```sh
-H "X-Islandora-Args: $OPERATION_ID $SOURCE_UUID 55"
```

The context must match the pending Drupal operation. Omitting it selects `0`.
On an existing module installation, run `drush updatedb` before using the new
context field, then rebuild Drupal's cache and deploy the updated service image.

## Recovery and health

Drupal's status report validates runtime configuration. It does not certify
remote connectivity, token scopes or editor membership.

Process one image, inspect `islandora_scribe_mapping`, verify the editor link,
then edit and publish. Confirm the mapping's `last_revision` advances and the
correct derivative points to a new managed file. Replay the same signed event
with a fresh timestamp, deliver an older revision, and simulate a storage
failure followed by retry before bulk ingestion.

The exact-revision export can return `aborted` if Scribe has already advanced
its canonical draft. The module never substitutes the current revision. Such
an event remains queued until its exact export succeeds or a later publication
advances the mapping, after which it is safely skipped. Draft saves and
transcription completion events never update Drupal.

Published updates stage a new managed `.hocr` file, save the media reference,
and advance the mapping in a database transaction. Old managed files remain
available for repository retention policy. A storage failure can leave an
unreferenced staged binary; retries reuse its deterministic destination.
Errors log only the operation ID; credentials and remote error bodies are
never logged. The module-owned tables are removed on uninstall; keep a backup
if reinstalling to preserve existing associations.

## Tests

Use a Drupal site's existing PHPUnit installation with SQLite enabled:

```sh
DRUPAL_ROOT=/path/to/drupal/web /path/to/drupal/vendor/bin/phpunit -c phpunit.xml.dist
```

Kernel tests exercise bounded signatures, resource checks, transactional
acceptance, replay, revision ordering, replacement and storage retry.

For installations configured with the former `token_provider` setting, replace
that entry with the protected read-only `api_key` setting above and rebuild
Drupal's cache. No database schema change is needed for this authentication change.

## Preserve Docker Compose secrets

Create these files on the Compose host, outside Git:

- `secrets/SCRIBE_READ_API_KEY`: a workspace-scoped read key with `annotations:read`.
- `secrets/SCRIBE_WEBHOOK_SECRET`: the signing secret registered with Scribe.
- `secrets/SCRIBE_CORRELATION_SECRET`: the same correlation signing secret used by Scyllaridae.

Compose mounts them into Drupal and Drupal cron as `/run/secrets/SCRIBE_READ_API_KEY`,
`/run/secrets/SCRIBE_WEBHOOK_SECRET`, and `/run/secrets/SCRIBE_CORRELATION_SECRET`. Set
`SCRIBE_API_URL` and `SCRIBE_WORKSPACE_ID` in the deployment environment. The
site's scaffolded settings read these files into protected runtime settings.
Do not append newlines to either signing secret. Recreate Drupal and Drupal cron
containers when adding the mounts; rebuild Drupal's cache after configuration.
