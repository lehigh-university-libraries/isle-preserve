#!/usr/bin/env bash

set -eou pipefail

TRAEFIK_CONFIG="./conf/traefik/config.tmpl"

check_traefik() {
    DOCKER_VARS=$(docker compose config traefik --format json | \
      jq -r '.services.traefik.environment | keys[]' | sort -u)

    TRAEFIK_VARS=$(grep "{{ env" "$TRAEFIK_CONFIG" | \
      awk -F '{{ env "' '{print $2}' | \
      awk -F '" }}' '{print $1}' | \
      sort -u)

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

php_codesniff() {
    echo "Running PHP codesniffer on custom modules"
    cd codebase
    cp web/core/phpcs.xml.dist .
    php vendor/bin/phpcs \
        --standard=Drupal \
        --extensions=module,php,inc \
        web/modules/custom && echo "PHP codesniff passed"

}

echo "Checking YML files"
ls -l ./*.yaml ./conf/**/*.yml "$TRAEFIK_CONFIG"
yq . ./*.yaml ./conf/**/*.yml "$TRAEFIK_CONFIG" > /dev/null
check_traefik
php_codesniff
