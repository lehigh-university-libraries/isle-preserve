#!/usr/bin/env bash

set -eou pipefail

echo "Fetching top rate limited IP ranges"
curl -s https://preserve.lehigh.edu/captcha-protect/stats | \
  jq -r '.rate | to_entries | sort_by(.value) | .[] | "\(.key): \(.value)"' | \
  tail -25
