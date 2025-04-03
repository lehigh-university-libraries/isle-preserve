#!/usr/bin/env bash

set -eou pipefail

ENV_FILES=(
    .env
    /home/rollout/.env
)
for ENV in "${ENV_FILES[@]}"; do
    export $(grep -Ev '^($|#|GIT_BRANCH|DRUPAL_DOCKER_TAG)' "$ENV" | xargs)
done

GIT_BRANCH=${GIT_BRANCH:-main}
DRUPAL_DOCKER_TAG=${DOCKER_TAG:-main}

if [ "$HOST" = "islandora-prod" ]; then
  # safeguard to main for prod
  GIT_BRANCH=main
  DRUPAL_DOCKER_TAG=main
fi

echo "Deploying git branch $GIT_BRANCH, docker tag $DRUPAL_DOCKER_TAG"
export DRUPAL_DOCKER_TAG

send_slack_message() {
    escaped_message=$(echo "$@" | jq -Rsa .)
    curl -s -o /dev/null -XPOST "$SLACK_WEBHOOK" -d '{
      "msg": '"$escaped_message"'
    }'
}

handle_error() {
    send_slack_message "🚨 Roll out failed 🚨"
    exit 1
}

trap 'handle_error' ERR

docker_compose() {
    docker compose \
      --env-file .env \
      --env-file /home/rollout/.env \
      -f docker-compose.yaml \
      -f docker-compose.$HOST.yaml \
      "$@"
}

cd /opt/islandora/d10_lehigh_agile
git fetch origin

send_slack_message "Rolling out changes to https://$DOMAIN :rocket: :shipit: :rocket:"

# check for any commit messages that start with a JIRA tag for our project
JIRA_TICKETS=$(git log --format="%s" HEAD..origin/main | grep -E "^IS-[0-9]+" | sort || echo "")
if [ "$JIRA_TICKETS" != "" ]; then
  MSG="Changes include:\n"
  MSG+=$(echo "$JIRA_TICKETS"| sed 's/^/https:\/\/lehigh.atlassian.net\/browse\//; s/$/\\n/')
  ESC_MSG=$(echo "$MSG" | sed 's/"/\\"/g')
  # slack webhooks have a rate limit of 1s
  # and we already called the webhook to alert on the rollout
  # also, sometimes they are out of order so fix that
  sleep 5
  send_slack_message "$ESC_MSG"
fi

git reset --hard
git checkout "$GIT_BRANCH"
git pull origin "$GIT_BRANCH"

# pull before putting site into maintenance mode
# to keep downtime to a minimum
docker_compose pull --quiet

docker compose exec drupal drush state:set system.maintenance_mode 1 --input-format=integer

docker_compose down drupal memcached

echo "bring up all containers"
docker_compose up \
  --remove-orphans \
  --wait \
  --pull missing \
  --quiet-pull \
  -d

docker compose exec drupal drush updb -y
docker compose exec drupal drush state:set system.maintenance_mode 0 --input-format=integer
docker compose exec drupal drush cr

# ensure drupal nginx user owns drupal config folder
chown -R 100:101 drupal/rootfs/var/www/drupal/config

echo "ensuring all containers are online"
docker_compose up \
  --remove-orphans \
  --wait \
  --pull missing \
  --quiet-pull \
  -d

send_slack_message "Roll out complete 🎉"

if [ "$HOST" = "islandora-prod" ]; then
  sleep 10
  # flush the cache for all authenticated users (everything except /var/www/drupal/private/canonical/0)
  docker compose exec drupal find /var/www/drupal/private/canonical/preserve.lehigh.edu -maxdepth 1 -type d -regex '.*/[1-9]\([0-9]*\)' -exec rm -rf {} \;
  docker compose exec drupal drush scr scripts/performance/cache-warmer.php
else
  docker compose exec drupal rm -rf /var/www/drupal/private/canonical/islandora-stage.lib.lehigh.edu || echo "No dir"
fi
