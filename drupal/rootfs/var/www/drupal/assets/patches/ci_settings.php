<?php

$path = "/var/run/s6/container_environment/";
$settings['hash_salt'] = file_get_contents($path . 'DRUPAL_DEFAULT_SALT');
$settings['config_sync_directory'] = '/var/www/drupal/config/sync';
