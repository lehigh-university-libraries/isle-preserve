#!/bin/sh

set -eou pipefail

RUNNER_CONTAINER="github-actions-runner"
RUNNER_IMAGE="us-docker.pkg.dev/lehigh-lts-images/internal/actions-runner:main"

if docker compose logs --tail 1 "$RUNNER_CONTAINER" | grep -q "Running job"; then
    echo "Running a job"
    exit 0
fi

echo "Runner is idle. Checking for image update..."

CURRENT_IMAGE_ID=$(docker images --format "{{.ID}}" "$RUNNER_IMAGE")
docker pull "$RUNNER_IMAGE" --quiet
NEW_IMAGE_ID=$(docker images --format "{{.ID}}" "$RUNNER_IMAGE")

if [ "$CURRENT_IMAGE_ID" = "$NEW_IMAGE_ID" ]; then
    echo "No new image"
    exit 0
fi

echo "New image pulled, restarting runner..."
docker compose up github-actions-runner -d
