#!/usr/bin/env

set -eou pipefail

cd /opt/islandora/volumes/tmp

DIRS=(
  cantaloupe
  drupal
  nginx
  drupal-static
  nginx-static
)

for DIR in "${DIR[@]}"; do
  if [ ! -d "$DIR" ]; then
    mkdir "$DIR"
  fi

  chown -R 100.101 "$DIR"
done
