#!/usr/bin/env bash

set -eou pipefail

echo "waiting for drupal to come online"
docker exec lehigh-d10-drupal-1 \
  timeout 600 bash -c "while ! test -f /installed; do sleep 5; done"

echo "running selenium tests"
docker exec lehigh-d10-drupal-1 \
  su nginx -s /bin/bash -c "DTT_BASE_URL='http://drupal' php vendor/bin/phpunit \
    -c phpunit.selenium.xml \
    --debug \
    --verbose"

echo "running tests against live site config"
docker exec lehigh-d10-drupal-1 \
  su nginx -s /bin/bash -c "php vendor/bin/phpunit \
    -c phpunit.unit.xml \
    --debug \
    --verbose"

echo "\n\n============================================="
echo "testing HA setup"
echo "=============================================\n\n"

echo "make sure drupal is online"
curl -ksf \
  -H "X-Forwarded-For: 128.180.1.1" \
  "https://${DOMAIN}/" -o /dev/null

echo "bring drupal containers down"
docker stop lehigh-d10-drupal-1

sleep 5

echo "Send request to drupal container which should fail"
curl -ksf \
  -H "X-Forwarded-For: 128.180.1.1" \
  "https://${DOMAIN}/?cache-warmer=1"  -o /dev/null \
&& exit 1 || echo "Failed as expected"

echo "make sure static site is still serving content"
curl -ksf "https://${DOMAIN}/" -o /dev/null

echo "all is well. Bring containers back up"
docker start lehigh-d10-drupal-1

docker stop lehigh-d10-drupal-static-1

sleep 5

echo "now test when drupal container is up, but static is down"
curl -ksf "https://${DOMAIN}/" -o /dev/null

docker start lehigh-d10-drupal-static-1
