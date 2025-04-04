#!/usr/bin/env bash

set -eou pipefail

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
