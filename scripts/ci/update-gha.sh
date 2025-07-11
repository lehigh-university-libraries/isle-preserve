#!/usr/bin/env bash

set -eou pipefail

# make sure k8s svc token stays fresh
/app/scripts/ci/k8s/token.sh

docker_compose() {
    docker compose \
      --env-file .env \
      --env-file /home/rollout/.env \
      -f docker-compose.yaml \
      -f docker-compose.wight.yaml \
      "$@"
}

# Our GitHub Actions runner docker image is maintained at https://github.com/lehigh-university-libraries/docker-builds
# When that docker image gets updated, we want to automatically receive updates
# we can not roll out those updates with GitHub PRs without weird timeouts happening
# because GitHub runners container would be restarted on a rollout, causing the GitHub Action workflow to hang
# so instead we just check for updates and handle those as they come
RUNNER_CONTAINER="github-actions-runner"
RUNNER_IMAGE=$(docker_compose config "$RUNNER_CONTAINER" --format json | jq -r '.services["'"${RUNNER_CONTAINER}"'"].image')

ensure_idle() {
    if docker_compose logs --tail 1 "$RUNNER_CONTAINER" | grep -q "Running job"; then
        echo "Running a job"
        exit 0
    fi
}

ensure_idle
echo "Runner is idle. Checking for image update..."

CURRENT_IMAGE_ID=$(docker images --format "{{.ID}}" "$RUNNER_IMAGE")
docker pull "$RUNNER_IMAGE" --quiet
NEW_IMAGE_ID=$(docker images --format "{{.ID}}" "$RUNNER_IMAGE")

if [ "$CURRENT_IMAGE_ID" = "$NEW_IMAGE_ID" ]; then
    echo "No new image"
    exit 0
fi

# we may have a job since we pulled
ensure_idle

echo "New image pulled, restarting runner..."
docker_compose up \
  "$RUNNER_CONTAINER" \
  -d
