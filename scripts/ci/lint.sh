#!/usr/bin/env bash

set -eou pipefail

TRAEFIK_CONFIG="./conf/traefik/config.yaml"

# need ggrep on mac OS for grep -P
grep() {
  if [[ "$OSTYPE" == "darwin"* ]]; then
    ggrep "$@"
    return
  fi
  grep "$@"
}

check_traefik() {
    DOCKER_VARS=$(docker compose config traefik --format json | \
      jq -r '.services.traefik.environment | keys[]' | sort -u)

    TRAEFIK_VARS=$(grep -oP '(?<!\$)\$\{[^}]+\}' "$TRAEFIK_CONFIG" | \
      sed 's/[${}]//g' | sort -u)

    echo "Environment variables defined in docker-compose (traefik):"
    printf "%s\n" "$DOCKER_VARS"
    echo "Environment variable references in traefik.yaml:"
    printf "%s\n" "$TRAEFIK_VARS"

    missing_vars=()
    for var in $TRAEFIK_VARS; do
        if ! echo "$DOCKER_VARS" | grep -q "^${var}$"; then
            missing_vars+=("$var")
        fi
    done

    if [ ${#missing_vars[@]} -eq 0 ]; then
        echo "All docker-compose environment variables are referenced in ${TRAEFIK_CONFIG}."
    else
        echo "Missing variables in ${TRAEFIK_CONFIG}:"
        for var in "${missing_vars[@]}"; do
            echo " - $var"
        done
        exit 1
    fi
}

php_codesnff() {
    echo "Running PHP codesniffer on custom modules"
    cd codebase
    cp web/core/phpcs.xml.dist .
    php vendor/bin/phpcs \
        --standard=Drupal \
        --extensions=module,php,inc \
        web/modules/custom && echo "PHP codesniff passed"

}

check_traefik
php_codesnff
