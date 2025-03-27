#!/usr/bin/env bash

set -eou pipefail

SCRIPT_DIR=$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )
. "${SCRIPT_DIR}"/init.sh

docker exec "$ISLE_PHP_FPM" cp web/core/phpcs.xml.dist .
docker exec "$ISLE_PHP_FPM" php vendor/bin/phpcs \
    --standard=Drupal \
    --extensions=module,php,inc \
    web/modules/custom
