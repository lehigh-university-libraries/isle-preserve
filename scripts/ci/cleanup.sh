#!/usr/bin/env bash

set -eou pipefail

docker exec lehigh-d10-drupal-1 drush scr scripts/ci/cleanup.php
docker exec lehigh-d10-drupal-1 rm -rf private/derivatives/
docker exec lehigh-d10-drupal-1 rm -rf private/canonical/
docker exec lehigh-d10-drupal-1 drush cr
docker exec lehigh-d10-cantaloupe-1 rm -rf /data/*
