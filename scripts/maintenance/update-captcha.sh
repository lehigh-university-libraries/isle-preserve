#!/usr/bin/env bash

set -eou pipefail

pushd conf/traefik/plugins
rm -rf captcha-protect
git clone https://github.com/libops/captcha-protect
pushd captcha-protect
rm -rf ci .github .git .gitignore README.md renovate.json5
find . -name "*_test.go" -exec rm {} \;
popd
popd

