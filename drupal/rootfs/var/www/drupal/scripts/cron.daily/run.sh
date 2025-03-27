#!/usr/bin/env bash

cd /var/www/drupal

nohup scripts/parallel.sh scripts/performance/warm-nodes.php &
nohup drush --uri https://preserve.lehigh.edu/ cron &
nohup drush xmlsitemap:regenerate --uri https://preserve.lehigh.edu/ &

exit 0

for FILE in scripts/derivatives/*.php; do
  if [ "$FILE" = "scripts/derivatives/action.php" ] || [ "$FILE" = "scripts/derivatives/action-rerun.php" ]; then
    continue;
  fi
  nohup drush scr --uri https://preserve.lehigh.edu/ "$FILE" &
  sleep 20
done
