#!/usr/bin/env bash

set -eou pipefail

echo "waiting for drupal to come online"
docker exec lehigh-d10-drupal-1 \
  timeout 600 bash -c "while ! test -f /installed; do sleep 5; done"

echo "running selenium tests"
docker exec lehigh-d10-drupal-1 \
  su nginx -s /bin/bash -c "DTT_BASE_URL='http://drupal' php vendor/bin/phpunit \
    -c phpunit.selenium.xml \
    --debug \
    --verbose"

echo "running tests against live site config"
docker exec lehigh-d10-drupal-1 \
  su nginx -s /bin/bash -c "php vendor/bin/phpunit \
    -c phpunit.unit.xml \
    --debug \
    --verbose"

echo -e "\n\n============================================="
echo "testing HA setup"
echo -e "=============================================\n\n"

echo "make sure drupal is online"
curl -ksf "https://${DOMAIN}/" -o /dev/null

echo "bring drupal containers down"
docker stop lehigh-d10-drupal-1

sleep 11

echo "Send request to drupal container which should fail"
curl -ksf "https://${DOMAIN}/?foo=bar" -o /dev/null \
  && exit 1 || echo "Failed as expected"

echo "make sure static site is still serving content"
curl -ksf "https://${DOMAIN}/" -o /dev/null

echo "all is well. Bring containers back up"
docker start lehigh-d10-drupal-1

echo "Checking site is still OK if static service is down"
docker stop lehigh-d10-drupal-static-1

sleep 11

curl -ksf "https://${DOMAIN}/" -o /dev/null

echo "all is well. Bring containers back up"
docker start lehigh-d10-drupal-static-1

echo "Ensuring redirects work"
ensure_redirect() {
  curl -svk \
    -H "Host: $2" \
    "$1" 2>&1 \
  | grep -i "location: https://${DOMAIN}" > /dev/null
}
HOSTS=(
  "digitalcollections.lib.lehigh.edu"
  "preserve.lib.lehigh.edu"
)
for HOST in "${HOSTS[@]}"; do
  ensure_redirect "https://${DOMAIN}" "$HOST"
  echo "$HOST redirected to https://$DOMAIN"
done

ensure_redirect "http://${DOMAIN}" "$DOMAIN"
echo "http redirected to https for ${DOMAIN}"
