#!/usr/bin/env bash

set -eou pipefail

apk add nss-tools

pushd /

ARCH=$(uname -m)
case "$ARCH" in
    x86_64)  PLATFORM="amd64" ;;
    aarch64) PLATFORM="arm64" ;;
    arm64)   PLATFORM="arm64" ;;
    *)       echo "Unsupported architecture: $ARCH"; exit 1 ;;
esac

curl -JL "https://dl.filippo.io/mkcert/latest?for=linux/$PLATFORM" -o /usr/local/bin/mkcert
chmod +x /usr/local/bin/mkcert

"${BASH_SOURCE[0]%/*}/generate-certs.sh"
"${BASH_SOURCE[0]%/*}/generate-secrets.sh"