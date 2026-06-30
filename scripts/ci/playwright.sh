#!/usr/bin/env bash

set -euo pipefail

BASE_URL="${BASE_URL:-}"
if [ -z "$BASE_URL" ] && [ -n "${DOMAIN:-}" ]; then
  BASE_URL="https://${DOMAIN}"
fi
BASE_URL="${BASE_URL:-https://wight.cc.lehigh.edu}"

PLAYWRIGHT_VERSION="${PLAYWRIGHT_VERSION:-1.56.1}"
PLAYWRIGHT_IMAGE="${PLAYWRIGHT_IMAGE:-mcr.microsoft.com/playwright:v${PLAYWRIGHT_VERSION}-noble}"

echo "running Playwright tests against ${BASE_URL}"

if command -v docker >/dev/null 2>&1; then
  tar -C "$PWD" -cf - tests/playwright | docker run --rm -i \
    --network host \
    -e BASE_URL="${BASE_URL}" \
    -e CI="${CI:-}" \
    "${PLAYWRIGHT_IMAGE}" \
    bash -lc 'mkdir -p /tmp/work && cd /tmp/work && tar -xf - && npm init -y >/dev/null && npm install --no-audit --no-fund --no-save "@playwright/test@'"${PLAYWRIGHT_VERSION}"'" && npx playwright test --config=tests/playwright/playwright.config.js'
  exit 0
fi

tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT
cp -R tests "$tmpdir/tests"
cd "$tmpdir"
npm init -y >/dev/null
npm install --no-audit --no-fund --no-save "@playwright/test@${PLAYWRIGHT_VERSION}"
npx playwright install chromium
BASE_URL="${BASE_URL}" npx playwright test --config=tests/playwright/playwright.config.js
