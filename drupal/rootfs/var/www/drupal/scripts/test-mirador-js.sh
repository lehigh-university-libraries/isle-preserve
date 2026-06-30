#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")/.."

if ! command -v node >/dev/null 2>&1; then
  echo "Node.js is required to run Mirador JavaScript tests." >&2
  exit 1
fi

node --check web/themes/custom/lehigh/js/node.js
node --test web/modules/custom/lehigh_islandora/tests/js/*.test.js
