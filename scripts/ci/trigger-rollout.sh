#!/usr/bin/env bash

set -eou pipefail

echo "Triggering rollout via $ROLLOUT_URL"

PAYLOAD=$(cat <<EOF
{
  "git-branch": "${GITHUB_REF_NAME}",
  "docker-tag": "${GITHUB_REF_NAME}"
}
EOF
)

COUNT=0
while true; do
  # Deployment and backoff can outlive GitHub's short-lived OIDC token.
  echo "Fetching GitHub OIDC token"
  TOKEN=$(curl -fsS \
    -H "Accept: application/json; api-version=2.0" \
    -H "Content-Type: application/json" -d "{}" \
    -H "Authorization: bearer $ACTIONS_ID_TOKEN_REQUEST_TOKEN" \
    "$ACTIONS_ID_TOKEN_REQUEST_URL" | jq -er '.value')

  # Add some buffer to avoid iat issues.
  sleep 5

  STATUS=$(curl -sSk \
    --max-time 300 \
    -w '%{http_code}' \
    -o /dev/null  \
    -d "$PAYLOAD" \
    -H "Authorization: bearer ${TOKEN}" \
    -H "X-Forwarded-For: 128.180.2.69" \
    "${ROLLOUT_URL}") || STATUS=000

  echo "Received $STATUS"
  if [ "$STATUS" = 200 ]; then
    echo "Rollout complete"
    exit 0
  fi

  COUNT=$(( COUNT + 1 ))
  if [ "$COUNT" -gt 3 ]; then
    break
  fi

  SLEEP_INTERVAL=$(( 10 * COUNT ))
  echo "trying again in ${SLEEP_INTERVAL}s"
  sleep "${SLEEP_INTERVAL}"
done

echo "Rollout failed. Check logs"
exit 1
