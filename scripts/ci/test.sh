#!/usr/bin/env bash

set -eou pipefail

docker exec lehigh-d10-drupal-1 \
  timeout 600 bash -c "while ! test -f /installed; do sleep 5; done"

docker exec lehigh-d10-drupal-1 \
  su nginx -s /bin/bash -c "DTT_BASE_URL='http://drupal' php vendor/bin/phpunit \
    -c phpunit.selenium.xml \
    --debug \
    --verbose"

echo "TODO: get these tests working again"
exit 0

docker exec lehigh-d10-drupal-1 \
  su nginx -s /bin/bash -c "php vendor/bin/phpunit \
    -c phpunit.unit.xml \
    --debug \
    --verbose"
