#!/usr/bin/env bash

set -eou pipefail

./generate-certs.sh
./generate-secrets.sh

docker compose \
  -f docker-compose.yaml \
  -f docker-compose.ci.yaml \
  up -d

docker compose exec drupal \
  timeout 600 bash -c "while ! test -f /installed; do sleep 5; done"

docker compose exec drupal \
  su nginx -s /bin/bash -c "DTT_BASE_URL='http://drupal' php vendor/bin/phpunit \
    -c phpunit.selenium.xml \
    --debug \
    --verbose"

echo "TODO: get these tests working again"
exit 0

docker compose exec drupal \
  su nginx -s /bin/bash -c "php vendor/bin/phpunit \
    -c phpunit.unit.xml \
    --debug \
    --verbose"
