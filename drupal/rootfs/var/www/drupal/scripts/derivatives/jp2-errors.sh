#!/usr/bin/env bash

set -eou pipefail

find /var/www/drupal/private/derivatives/service/node -type f -name "*.jp2" -mtime -7 | \
xargs -P 5 -I {} bash -c '
  JP2="{}"
  
  # the identify command exits ~200ms if the file is not valid
  # but it can take many seconds for large legit files
  # so just set a 1s timeout and check the exit code for legit errors
  # if timeout kills the process, the exit code will be 143
  # so exit code 1 means identify failed
  EXIT_CODE=0
  timeout 1 identify -verbose "$JP2" > /dev/null 2>&1 || EXIT_CODE=$?
  if [ $EXIT_CODE != 1 ]; then
    exit 0
  fi

  # print the NID from the file path
  echo "$(dirname "$JP2" | xargs basename)"
'
