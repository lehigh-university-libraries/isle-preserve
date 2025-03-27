#!/usr/bin/env bash

set -eou pipefail
shopt -s nullglob

YEAR=$(date +"%Y")
MONTH=$(date +"%m")
DAY=$(date +"%d")

cd /opt/islandora/backups
mkdir -p "$YEAR/$MONTH/$DAY"
cd "$YEAR/$MONTH/$DAY"

docker exec lehigh-d10-drupal-1 \
  drush sql-dump -y --skip-tables-list=cache,cache_*,watchdog \
    --structure-tables-list=cache,cache_*,watchdog \
    --debug > drupal.sql
gzip drupal.sql

docker exec lehigh-d10-fcrepo-1 \
  mysqldump -h mariadb -u fcrepo \
    -p"$(cat /opt/islandora/d10_lehigh_agile/secrets/FCREPO_DB_PASSWORD)" \
    fcrepo > fcrepo.sql
gzip fcrepo.sql

# remove all backups older than one week
# except ones ran on the first of the month so we have some history
find /opt/islandora/backups -type f -mtime +14 | grep -Ev "\/01\/[a-z\.]" | xargs rm || echo "No files older than 14d"

# remove empty directories
find /opt/islandora/backups -type d -empty -exec rm -rf {} \; || echo "No non empty directories"

# we're running backups nightly
# so lets go ahead and keep the filesystem trim from old images
docker system prune -af

docker exec lehigh-d10-drupal-prod-1 drush cr

# clear out all bad cached manifests
for JSON in /opt/islandora/volumes/drupal-private-files/iiif/preserve.lehigh.edu/*/node/*/book-manifest.json; do
  if [ ! -f "$JSON" ]; then
    continue
  fi

  CLEANUP=0
  jq . "$JSON" > /dev/null || CLEANUP=1

  if [ "$CLEANUP" -eq 0 ]; then
    continue
  fi

  echo "Cleaning up $JSON"
  rm "$JSON" || echo "File doesn't exist"
done

docker exec lehigh-d10-drupal-prod-1 scripts/cron.daily/run.sh
