#!/usr/bin/env bash

set -eou pipefail

docker logs lehigh-d10-drupal-1 | \
  grep 'HTTP/1.1" 404' | \
  awk '{print $7}'| \
  awk -F '/' '{print $2"/"$3}' | \
  sort | uniq -c | sort -n | \
  tail
