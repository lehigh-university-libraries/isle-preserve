#!/usr/bin/env bash
set -euo pipefail

# This test modifies the disposable CI site. Never point it at staging or prod.
: "${DOMAIN:?Set DOMAIN to the disposable test site}"
if [[ "$DOMAIN" != wight.cc.lehigh.edu && "$DOMAIN" != islandora.dev ]]; then
  echo 'Source browser fixtures are restricted to disposable test sites.' >&2
  exit 1
fi
browser_dir=drupal/rootfs/var/www/drupal/web/modules/custom/lehigh_islandora/tests/Browser
state_file="/tmp/source-browser-fixture-${GITHUB_RUN_ID:-local}-$$.json"
workdir=$(mktemp -d)
browser_container=''
cleanup() {
  local status=$?
  trap - EXIT
  if [[ -n "$browser_container" ]]; then
    docker stop "$browser_container" >/dev/null || status=1
    docker rm "$browser_container" >/dev/null || status=1
  fi
  if docker compose exec -T drupal test -f "$state_file"; then
    docker compose exec -T drupal drush php:script web/modules/custom/lehigh_islandora/tests/Browser/cleanup.php -- "$state_file" || status=1
  fi
  rm -r "$workdir" || status=1
  exit "$status"
}
trap cleanup EXIT

docker compose exec -T drupal drush php:script web/modules/custom/lehigh_islandora/tests/Browser/fixture.php -- "$state_file" > "$workdir/fixture.json"
docker compose exec -T drupal drush search-api:index default_solr_index
# Keep the short-lived login URL out of the workflow log.
fixture_uid=$(jq -er '.user | select(type == "number" and . > 1)' "$workdir/fixture.json")
login_url=$(docker compose exec -T drupal drush user:login --uid="$fixture_uid" --uri="https://$DOMAIN")
browser_container=$(docker create --network host --entrypoint sleep \
  mcr.microsoft.com/playwright@sha256:47b510dd9230bf0b9bfc9b20102241e577ea7a99334ab4c3ca19f19770e9b8ea infinity)
docker start "$browser_container" >/dev/null
docker exec "$browser_container" mkdir -p /work
docker cp "$browser_dir/." "$browser_container:/work/"
docker cp "$workdir/fixture.json" "$browser_container:/work/fixture.json"
docker exec -w /work "$browser_container" npm ci --ignore-scripts --no-audit --no-fund
# The UI tests use a local image response; the manifest and canvases remain real.
docker exec "$browser_container" node -e 'require("fs").writeFileSync("/work/image.png", Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=", "base64"))'
docker exec -w /work -e "BROWSER_BASE_URL=https://$DOMAIN" -e "BROWSER_LOGIN_URL=$login_url" \
  -e BROWSER_IMAGE_FIXTURE=/work/image.png "$browser_container" node check.cjs /work/fixture.json
