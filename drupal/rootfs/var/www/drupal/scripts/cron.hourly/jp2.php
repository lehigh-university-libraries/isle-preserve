<?php

$action_name = 'generate_a_jp2_service_file';
$nids = [];
$return_var = 0;
exec('/var/www/drupal/scripts/derivatives/jp2-errors.sh', $nids, $return_var);

if ($return_var !== 0) {
    echo "Error running script\n";
    exit(1);
}

if (count($nids)) {
  require_once "/var/www/drupal/scripts/derivatives/action-rerun.php";
}
