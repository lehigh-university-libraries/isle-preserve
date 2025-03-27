#!/usr/bin/env bash

cd /var/www/drupal

for FILE in scripts/cron.hourly/*.php; do
  nohup drush scr --uri https://preserve.lehigh.edu/ "$FILE" &
  sleep 20
done
