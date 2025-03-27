#!/usr/bin/env bash

set -eou pipefail

SCRIPT_DIR=$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )
. "${SCRIPT_DIR}"/init.sh

source .env

ISLANDORA_DRUPAL_TAG=${ISLANDORA_TAG%.*}

FLAGS=(
  "--build-arg=ISLANDORA_DRUPAL_TAG=${ISLANDORA_DRUPAL_TAG}"
  "-t"
  "us-docker.pkg.dev/lehigh-preserve-isle/isle/drupal:${CI_COMMIT_BRANCH}"
  "--pull"
)
if [[ $# -gt 0 ]]; then
  FLAGS+=(
    "--platform"
    "linux/amd64,linux/arm64/v8"
    "--push"
  )
else
  FLAGS+=(
    "--platform"
    "linux/amd64"
    "--load"
  )
fi
FLAGS+=("drupal")

docker pull islandora/drupal:"${ISLANDORA_DRUPAL_TAG}"
docker buildx create --name isle-builder --use || echo "continuing"
docker buildx build "${FLAGS[@]}"
