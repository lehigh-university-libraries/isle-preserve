#!/usr/bin/env bash

set -eou pipefail

DURATION=${DURATION:-600}
while true; do
  drush --uri "$DRUPAL_DRUSH_URI" queue:run lehigh_islandora_events
  sleep "$DURATION"
done
