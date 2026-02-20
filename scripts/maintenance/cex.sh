#!/usr/bin/env bash

set -eou pipefail

SERVER=$1

ssh $SERVER "docker exec lehigh-d10-drupal-1 rm /tmp/config.tar.gz || echo config-does-not-exist"
ssh $SERVER "docker exec lehigh-d10-drupal-1 drush cex -y"
ssh $SERVER "docker exec lehigh-d10-drupal-1 tar -zcvf /tmp/config.tar.gz config"
ssh $SERVER "docker cp lehigh-d10-drupal-1:/tmp/config.tar.gz \$HOME/"
scp $SERVER:~/config.tar.gz .
tar -zxvf config.tar.gz
rm -rf codebase/config
mv config codebase/
rm config.tar.gz
ssh -t $SERVER "cd /opt/islandora/d10_lehigh_agile; sudo rm -rf drupal/rootfs/var/www/drupal/config/sync/*; sudo git checkout -- drupal/rootfs/var/www/drupal/config"
