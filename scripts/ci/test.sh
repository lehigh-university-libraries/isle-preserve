#!/usr/bin/env bash

set -eou pipefail

if [ ! -f .env ]; then
  cp sample.env .env
fi

echo "waiting for drupal to come online"
docker compose exec drupal \
  timeout 600 bash -c "while ! test -f /installed; do sleep 5; done"

echo -e "\n\n============================================="
echo "running tests against live site config"
echo -e "=============================================\n\n"
docker compose exec drupal \
  su nginx -s /bin/bash -c \
    "php vendor/bin/phpunit -c phpunit.unit.xml --debug"
