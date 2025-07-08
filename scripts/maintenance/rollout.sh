#!/usr/bin/env bash

set -eou pipefail


# update .env variables
# this is so we can shell into the dev VM
# which may have a git branch checked out
# and use docker compose commands
# and not worry about overriding the current rollout
update_env() {
    VAR_NAME="$1"
    VALUE="$2"
    if grep -Eq "^${VAR_NAME}=" .env; then
        sed -i "s/^$VAR_NAME=.*/$VAR_NAME=$VALUE/" .env
    else
        echo "${VAR_NAME}=${VALUE}" | tee -a .env
    fi
}

while IFS='=' read -r key val; do
    export "$key"="$val"
done < <(grep -Ev '^($|#|GIT_BRANCH|DRUPAL_DOCKER_TAG)' /opt/islandora/d10_lehigh_agile/.env)

GIT_BRANCH=${GIT_BRANCH:-main}
DRUPAL_DOCKER_TAG=${DOCKER_TAG:-main}

update_env "GIT_BRANCH" "${GIT_BRANCH}"
update_env "DRUPAL_DOCKER_TAG" "${DRUPAL_DOCKER_TAG}"

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
      -f docker-compose.yaml \
      -f "docker-compose.$HOST.yaml" \
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
  ESC_MSG="${MSG//\"/\\\"}"
  # slack webhooks have a rate limit of 1s
  # and we already called the webhook to alert on the rollout
  # also, sometimes they are out of order so fix that
  sleep 5
  send_slack_message "$ESC_MSG"
fi

git reset --hard
git checkout "$GIT_BRANCH"
git pull origin "$GIT_BRANCH"

# make sure drupal is already online
docker_compose up drupal -d

# pull before putting site into maintenance mode
# to keep downtime to a minimum
docker_compose pull --quiet

docker compose exec drupal drush state:set system.maintenance_mode 1 --input-format=integer

docker_compose down memcached

echo "bring up all containers"
docker_compose up \
  --remove-orphans \
  --wait \
  --pull missing \
  --quiet-pull \
  -d

docker compose exec drupal drush updb -y || echo "db update failed"
docker compose exec drupal drush state:set system.maintenance_mode 0 --input-format=integer
docker compose exec drupal drush cr || echo "cache rebuild failed"

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

if [ "$HOST" != "islandora-prod" ]; then
  docker compose exec drupal drush cim -y || echo "drush config import failed"

  # remove dangling branches
  if [ "$GIT_BRANCH" = "main" ]; then
    git branch | grep -v "main" | xargs git branch -D
  fi

  # splay a docker system prune to keep filesystem clean
  if [ "$(( RANDOM % 10 ))" -eq 0 ]; then
    docker system prune -af
  fi
fi
