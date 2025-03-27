#!/usr/bin/env bash

set -eou pipefail

if [ ! -v HOST ]; then
  HOST=$(hostname)
fi

if [ "$HOST" != "wight" ]; then
  # safeguard to staging for stage+prod
  export GIT_BRANCH=staging
fi

handle_error() {
  curl -s -o /dev/null -XPOST $SLACK_WEBHOOK -d '{
    "msg": "🚨 Roll out failed 🚨"
  }'
  exit 1
}

trap 'handle_error' ERR

compose_up() {
    docker compose \
      --env-file .env \
      --env-file /home/rollout/.env \
      -f docker-compose.yaml \
      -f docker-compose.$HOST.yaml \
      up -d
}

wait_for_drupal_container() {
    local failure_count=0
    while [[ $failure_count -lt 5 ]]; do
        if docker container ls --format "{{ .Names }}" | grep drupal || false; then
            echo "Drupal container started..."
            return 0
        else
            failure_count=$((failure_count + 1))
            echo "Drupal container not found.."
            sleep 5
        fi
    done
    echo "Timeout exceeded, exiting"
    exit 1
}

wait_for_mysql() {
    local failure_count=0
    DRUPAL_CONTAINER=$(docker container ls --format "{{ .Names }}" | grep drupal)
    while [[ $failure_count -lt 20 ]]; do
        if docker exec $DRUPAL_CONTAINER drush status | grep -E "^Database.*Connected" || false; then
            echo "Drupal connected to MySQL..."
            return 0
        else
            failure_count=$((failure_count + 1))
            echo "Drupal not connected to MySQL.."
            sleep 5
        fi
    done
    echo "Timeout exceeded, exiting"
    exit 1
}

cd /opt/islandora/d10_lehigh_agile
git fetch origin

if [ "$HOST" = "islandora-prod" ]; then
  curl -s -o /dev/null -XPOST $SLACK_WEBHOOK -d '{
    "msg": "Rolling out changes to https://preserve.lehigh.edu :rocket: :shipit: :rocket:"
  }'
elif [ "$HOST" = "islandora-test" ]; then
  curl -s -o /dev/null -XPOST $SLACK_WEBHOOK -d '{
    "msg": "Rolling out changes to https://islandora-stage.lib.lehigh.edu :rocket: :shipit: :rocket:"
  }'
else
  curl -s -o /dev/null -XPOST $SLACK_WEBHOOK -d '{
    "msg": "Rolling out changes to https://'$HOST'.lib.lehigh.edu :rocket: :shipit: :rocket:"
  }'
fi

JIRA_TICKETS=$(git log --format="%s" HEAD..origin/staging | grep -E "^IS-[0-9]+" | sort || echo "")
if [ "$JIRA_TICKETS" != "" ]; then
  MSG="Changes include:\n"
  MSG+=$(echo "$JIRA_TICKETS"| sed 's/^/https:\/\/lehigh.atlassian.net\/browse\//; s/$/\\n/')
  ESC_MSG=$(echo "$MSG" | sed 's/"/\\"/g')
  # webhooks have a rate limit of 1s
  # also, sometimes they are out of order so fix that
  sleep 10
  curl -s -o /dev/null -XPOST $SLACK_WEBHOOK -d '{
    "msg": "'"$ESC_MSG"'"
  }'
fi

# make sure our sidecar images are on latest
for IMAGE in $(docker compose config --format json | \
  jq -r .services[].image | \
  grep -E "(lehighlts|us\-docker\.pkg\.dev)" | \
  grep -v drupal)
do
  docker pull $IMAGE
done

compose_up

git reset --hard
git checkout $GIT_BRANCH
git pull origin $GIT_BRANCH

DRUPAL_CONTAINER=$(docker container ls --format "{{ .Names }}" | grep drupal)

if [ $HOST != "wight" ]; then
  docker pull us-docker.pkg.dev/lehigh-preserve-isle/isle/drupal:staging
fi

docker exec $DRUPAL_CONTAINER drush state:set system.maintenance_mode 1 --input-format=integer
docker exec $DRUPAL_CONTAINER drush cr

compose_up

wait_for_drupal_container
wait_for_mysql

docker exec $DRUPAL_CONTAINER drush updb -y
docker exec $DRUPAL_CONTAINER drush state:set system.maintenance_mode 0 --input-format=integer
docker exec $DRUPAL_CONTAINER drush cr

chown -R 100:101 drupal/rootfs/var/www/drupal/config

# make 100% sure we're up
compose_up

curl -s -o /dev/null -XPOST $SLACK_WEBHOOK -d '{
  "msg": "Roll out complete 🎉"
}'

if [ "$HOST" = "islandora-prod" ]; then
  sleep 10
  # flush the cache for all authenticated users (everything except /var/www/drupal/private/canonical/0)
  docker exec $DRUPAL_CONTAINER find /var/www/drupal/private/canonical/preserve.lehigh.edu -maxdepth 1 -type d -regex '.*/[1-9]\([0-9]*\)' -exec rm -rf {} \;
  docker exec $DRUPAL_CONTAINER drush scr scripts/performance/cache-warmer.php
else
  docker exec $DRUPAL_CONTAINER rm -rf /var/www/drupal/private/canonical/islandora-stage.lib.lehigh.edu || echo "No dir"
fi
