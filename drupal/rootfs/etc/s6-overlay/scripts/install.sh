#!/command/with-contenv bash
# shellcheck shell=bash
set -e

# shellcheck disable=SC1091
source /etc/islandora/utilities.sh

readonly SITE="default"

function configure {
    # Starter site post install steps.
    drush --root=/var/www/drupal cache:rebuild
    drush --root=/var/www/drupal user:role:add fedoraadmin admin
    drush --root=/var/www/drupal pm:uninstall pgsql sqlite
    drush --root=/var/www/drupal migrate:import --userid=1 islandora_tags,islandora_defaults_tags,islandora_fits_tags
    drush --root=/var/www/drupal cron || true
    drush --root=/var/www/drupal search-api:index || true
    drush --root=/var/www/drupal cache:rebuild
}

function install {
    wait_for_service "${SITE}" db
    create_database "${SITE}"
    install_site "${SITE}"
    wait_for_service "${SITE}" broker
    wait_for_service "${SITE}" fcrepo
    wait_for_service "${SITE}" fits
    wait_for_service "${SITE}" solr
    wait_for_service "${SITE}" triplestore
    create_blazegraph_namespace_with_default_properties "${SITE}"
    if [[ "${DRUPAL_DEFAULT_FCREPO_URL}" == https* ]]; then
      # Certificates might need to be generated which can take a minute or more.
      if timeout 300 curl -X HEAD "${DRUPAL_DEFAULT_FCREPO_URL}" &>/dev/null; then
          echo "Valid certificate"
      else
          echo "Invalid certificate"
          exit 1
      fi
    fi
    configure
}

function mysql_count_query {
    cat <<-EOF
SELECT COUNT(DISTINCT table_name)
FROM information_schema.columns
WHERE table_schema = '${DRUPAL_DEFAULT_DB_NAME}';
EOF
}

# Check the number of tables to determine if it has already been installed.
function installed {
    local count
    count=$(execute-sql-file.sh <(mysql_count_query) -- -N 2>/dev/null) || exit $?
    [[ $count -ne 0 ]]
}

# Required even if not installing.
function setup() {
    local site drupal_root subdir site_directory public_files_directory private_files_directory twig_cache_directory
    site="${1}"
    shift

    drupal_root=/var/www/drupal/web
    subdir=$(drupal_site_env "${site}" "SUBDIR")
    site_directory="${drupal_root}/sites/${subdir}"
    public_files_directory="${site_directory}/files"
    private_files_directory="/var/www/drupal/private"
    twig_cache_directory="${private_files_directory}/php"

    # Ensure the files directories are writable by nginx, as when it is a new volume it is owned by root.
    mkdir -p "${site_directory}" "${public_files_directory}" "${private_files_directory}" "${twig_cache_directory}"
    chown nginx:nginx "${site_directory}" "${public_files_directory}" "${private_files_directory}" "${twig_cache_directory}"
    chmod ug+rw "${site_directory}" "${public_files_directory}" "${private_files_directory}" "${twig_cache_directory}"
}

function drush_cache_setup {
    # Make sure the default drush cache directory exists and is writeable.
    mkdir -p /tmp/drush-/cache
    chmod a+rwx /tmp/drush-/cache
}

# External processes can look for `/installed` to check if installation is completed.
function finished {
    touch /installed
    cat <<-EOT


#####################
# Install Completed #
#####################
EOT
}

function main() {
    cd /var/www/drupal
    drush_cache_setup
    for_all_sites setup

    if installed; then
        echo "Already Installed"
    else
        echo "Installing"
        install
    fi
    finished
}
main
