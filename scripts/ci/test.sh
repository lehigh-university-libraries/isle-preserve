#!/usr/bin/env bash

set -eou pipefail

SCRIPT_DIR=$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )
. "${SCRIPT_DIR}"/init.sh

echo "Waiting for container $ISLE_PHP_FPM to become healthy..."
while true; do
  STATUS=$(docker inspect --format='{{.State.Health.Status}}' "$ISLE_PHP_FPM")
  if [ "$STATUS" == "healthy" ]; then
    echo "Container $ISLE_PHP_FPM is healthy."
    break
  elif [ "$STATUS" == "unhealthy" ]; then
    echo "Container $ISLE_PHP_FPM is unhealthy."
    exit 1
  elif [ -z "$STATUS" ]; then
    echo "Error: Container $ISLE_PHP_FPM does not exist or has no health status."
    exit 1
  fi

  sleep 2
done
exit 0
docker exec \
  "$ISLE_PHP_FPM" \
  rm -rf private/iiif private/canonical

docker exec \
  "$ISLE_PHP_FPM" \
  su nginx -s /bin/bash -c "php vendor/bin/phpunit \
    -c phpunit.unit.xml \
    --debug \
    --verbose"

docker exec \
  "$ISLE_PHP_FPM" \
  su nginx -s /bin/bash -c "DTT_BASE_URL='http://drupal' php vendor/bin/phpunit \
    -c phpunit.selenium.xml \
    --debug \
    --verbose"
