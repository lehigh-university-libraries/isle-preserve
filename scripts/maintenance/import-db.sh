#!/usr/bin/env bash

set -eou pipefail

if [ "$(hostname)" = "islandora-prod" ]; then
  echo "Not running from production server. Exiting"
  exit 1
fi

F="./tmp/drupal/drupal.sql"

sudo touch ./drupal.sql.gz ./drupal.sql
current_user=$(whoami)
sudo chown "$current_user" ./drupal.sql.gz ./drupal.sql

YEAR=$(date +"%Y")
MONTH=$(date +"%m")
DAY=$(date +"%d")
if [ -f "${F}" ]; then
  current_time=$(date +%s)
  if [[ "$OSTYPE" == "darwin"* ]]; then
    file_modification_time=$(stat -f "%m" "$F")
  else
    file_modification_time=$(stat -c "%Y" "$F")
  fi
  file_age=$((current_time - file_modification_time))
  if [[ $file_age -gt 43200 ]]; then
    echo "Removing old SQL file. It's ${file_age}s old"
    rm "${F}"
  fi
fi

if [ ! -f "${F}" ]; then
  scp islandora-prod.lib.lehigh.edu:"/opt/islandora/backups/$YEAR/$MONTH/$DAY/drupal.sql.gz" .
  gunzip drupal.sql.gz
  mv drupal.sql ./tmp/drupal/
fi
CONTAINER=$(docker container ls --format '{{.Names}}' | grep drupal)
docker exec $CONTAINER drush sqlq --debug --file /tmp/drupal.sql
docker exec $CONTAINER drush cr
