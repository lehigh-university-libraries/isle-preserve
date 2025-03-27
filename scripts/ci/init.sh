#!/usr/bin/env bash

set -eou pipefail

export CI_COMMIT_BRANCH=${CI_COMMIT_BRANCH:=$(git rev-parse --abbrev-ref HEAD)}
export CI_COMMIT_SHORT_SHA=${CI_COMMIT_SHORT_SHA:=$(git rev-parse --short HEAD)}
ISLE_PHP_FPM=$(docker container ls --format '{{.Names}}' | grep drupal)
export ISLE_PHP_FPM
