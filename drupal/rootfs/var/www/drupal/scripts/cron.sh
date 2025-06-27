#!/usr/bin/env bash

set -eou pipefail

# copy JS/CSS/IMG files to /tmp
# this is so our static nginx frontend has access to those assets
# that are baked into the drupal image
#
# IMO we really should only need to build ONE drupal image
# rather than one image for drupal
# and another image for this static nginx frontend
#
# so we get those assets in our drupal image into nginx static frontend
# if everytime our drupal-cron service starts it copies
# over the current drupal CSS/JS/IMG assets into /tmp/web
# which we then mount in the nginx static frontend to serve those files
# TODO: better document this in an ADR
echo "Copying static assets into temp so our static site has access"
DIRS=(
  "core"
  "themes"
  "modules"
)
for DIR in "${DIRS[@]}"; do
  mkdir -p /tmp/web/$DIR
  rsync -av \
    --include='*/' \
    --include='*.js' \
    --include='*.css' \
    --include='*.jpg' \
    --include='*.png' \
    --include='*.ico' \
    --include='*.svg' \
    --exclude='*' \
  /var/www/drupal/web/$DIR/ /tmp/web/$DIR/ && ls -l /tmp/web
done

echo "Putting docker secrets in place"
cp /run/secrets/JWT_PRIVATE_KEY /opt/keys/jwt/private.key
for f in /run/secrets/*; do
  name=$(basename "$f")
  export "$name"="$(< "$f")"
done

cd /var/www/drupal || exit 1

echo "Starting cron"
DURATION=${DURATION:-900}
while true; do
  echo "$(date +"%Y-%m-%dT%H:%M:%S%z") Starting cron loop"
  time drush --uri "$DRUPAL_DRUSH_URI/" queue:run lehigh_islandora_events
  time drush --uri "$DRUPAL_DRUSH_URI/" scr scripts/audit/paged-content-pdf.php
  time drush --uri "$DRUPAL_DRUSH_URI/" scr scripts/audit/jp2.php
  for FILE in scripts/derivatives/*.php; do
    if [ "$FILE" = "scripts/derivatives/action.php" ] || [ "$FILE" = "scripts/derivatives/action-rerun.php" ]; then
      continue;
    fi
    echo "$(date +"%Y-%m-%dT%H:%M:%S%z") Processing $FILE"
    # when a derivative script has nothing to process
    # it has a non-zero exit code. So when that happens just continue
    # to avoid sleeping
    time drush --uri "$DRUPAL_DRUSH_URI/" scr "$FILE" || continue

    # splay how long we sleep so our cron derivative replay
    # won't overwhelm the server
    T=$(shuf -i 5-300 -n 1)
    echo "Sleeping for $T";
    sleep "$T"
  done
  echo "$(date +"%Y-%m-%dT%H:%M:%S%z") End cron loop. Sleeping for $DURATION"
  sleep "$DURATION"
done
