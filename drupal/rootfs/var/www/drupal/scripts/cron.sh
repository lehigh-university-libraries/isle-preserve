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

DURATION=${DURATION:-600}
while true; do
  drush --uri "$DRUPAL_DRUSH_URI" queue:run lehigh_islandora_events
  sleep "$DURATION"
done
