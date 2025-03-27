#!/usr/bin/env bash

set -eou pipefail

INFINITE=true
if [ $# -gt 1 ]; then
  INFINITE=false
fi

while [ true ]; do
  job_ids=()
  for i in $(seq 1 5); do
    drush scr $1 --uri ${DRUPAL_DRUSH_URI}/ &
    job_ids+=($!)
  done
  for job_id in "${job_ids[@]}"; do
    wait "$job_id"
  done
  if [ "$INFINITE" = "false" ]; then
    exit 0
  fi
done
