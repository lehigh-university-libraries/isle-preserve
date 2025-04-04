#!/usr/bin/env bash

set -eou pipefail

docker exec lehigh-d10-drupal-1 \
  timeout 600 bash -c "while ! test -f /installed; do sleep 5; done"

docker exec lehigh-d10-drupal-1 \
  su nginx -s /bin/bash -c "DTT_BASE_URL='http://drupal' php vendor/bin/phpunit \
    -c phpunit.selenium.xml \
    --debug \
    --verbose"

docker exec lehigh-d10-drupal-1 \
  su nginx -s /bin/bash -c "php vendor/bin/phpunit \
    -c phpunit.unit.xml \
    --debug \
    --verbose"

echo "make sure drupal is online"
curl -vsf "https://${DOMAIN}/" -o /dev/null

echo "bring drupal containers down"
docker stop lehigh-d10-drupal-1 lehigh-d10-drupal-lehigh-1

sleep 5

echo "Send request to drupal container which should fail"
curl -vsf \
  -H "X-Forwarded-For: 128.180.1.1" \
  "https://${DOMAIN}/?cache-warmer=1" && exit 1 || echo "Failed as expected"

# make sure static site is still serving content
curl -vsf "https://${DOMAIN}/" -o /dev/null
