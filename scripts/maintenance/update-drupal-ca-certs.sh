#!/usr/bin/env bash

set -eou pipefail

COUNT=0
DOMAINS=(
  "wight.cc.lehigh.edu"
  "islandora-test.lib.lehigh.edu"
  "islandora-stage.lib.lehigh.edu"
  "preserve.lehigh.edu"
  "islandora-prod.lib.lehigh.edu"
  "isle-microservices.cc.lehigh.edu"
)
for DOMAIN in "${DOMAINS[@]}"; do
  echo "Fetching CA for $DOMAIN"
  CERTS=$(openssl s_client -connect "$DOMAIN:443" -showcerts </dev/null 2>/dev/null | sed -ne '/-BEGIN CERTIFICATE-/,/-END CERTIFICATE-/p')
  while read -r CERT; do
    if [[ "$CERT" == *"BEGIN CERTIFICATE"* ]]; then
      FILENAME="drupal/rootfs/usr/local/share/ca-certificates/ca_${DOMAIN%%.*}.crt"
      COUNT=$(( COUNT + 1 ))
      rm -f "$FILENAME"
    fi
    echo "$CERT" >> "$FILENAME"
  done <<< "$CERTS"
done


declare -A seen
for file in drupal/rootfs/usr/local/share/ca-certificates/ca_*; do
  hash=$(md5sum "$file" | awk '{print $1}')
  if [[ -n "${seen[$hash]:-}" ]]; then
    echo "Deleting duplicate file $file (duplicate of ${seen[$hash]})"
    rm -f "$file"
  else
    seen[$hash]=$file
  fi
done
