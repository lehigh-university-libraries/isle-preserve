#!/usr/bin/env bash

set -eou pipefail

SCRIPT_DIR=$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )
. "${SCRIPT_DIR}"/init.sh

# splay so 10% of all runs cleanup docker
# to avoid running out of disk space
random_number=$(( RANDOM % 5 ))
if [ "${CI}" = "true" ] && [ "$random_number" -eq 1 ]; then
  docker system prune -af
fi
