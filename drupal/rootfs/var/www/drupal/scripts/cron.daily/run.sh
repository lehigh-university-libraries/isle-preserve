#!/usr/bin/env bash

cd /var/www/drupal

nohup scripts/parallel.sh scripts/performance/warm-nodes.php &
nohup drush --uri https://preserve.lehigh.edu/ cron &
nohup drush xmlsitemap:regenerate --uri https://preserve.lehigh.edu/ &
