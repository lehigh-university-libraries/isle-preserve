#!/usr/bin/env bash

set -eou pipefail

cd codebase
cp web/core/phpcs.xml.dist .
php vendor/bin/phpcs \
    --standard=Drupal \
    --extensions=module,php,inc \
    web/modules/custom
