<?php

/**
 * Ensure jp2 service files are valid.
 */

lehigh_islandora_cron_account_switcher();

$action_name = 'generate_a_jp2_service_file';
$entity_type_manager = \Drupal::entityTypeManager();
$node_storage   = $entity_type_manager->getStorage('node');
$action_storage = $entity_type_manager->getStorage('action');
$action = $action_storage->load($action_name);

$sql = "SELECT mo.field_media_of_target_id AS nid, f.uri
  FROM media_field_data m
  INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
  INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
  INNER JOIN media__field_media_file mf ON m.mid = mf.entity_id
  INNER JOIN file_managed f ON f.fid = field_media_file_target_id
  WHERE m.created > UNIX_TIMESTAMP()-3600
    AND mu.field_media_use_target_id = :tid
    AND f.uri LIKE '%.jp2'
  GROUP BY mo.field_media_of_target_id";
$d_args = [
  ':tid' => lehigh_islandora_get_tid_by_name("Service File", "islandora_media_use"),
];
$items = \Drupal::database()->query($sql, $d_args);

foreach ($items as $item) {
  $item->uri = str_replace("private://", "/var/www/drupal/private/", $item->uri);

  if (!file_exists($item->uri) || is_dir($item->uri)) {
    echo "Unable to find file for $item->nid $item->uri\n";
    continue;
  }

  $cmd = "timeout 1 identify -verbose \"$item->uri\"";
  $output = [];
  $exit_code = 0;
  exec($cmd, $output, $exit_code);
  if ($exit_code != 1) {
    echo "$item->uri is OK\n";
    continue;
  }

  echo "Need new JP2 for $item->nid at $item->uri.";
  try {
    $nodes = $node_storage->loadMultiple([$item->nid]);
  } catch (Exception $e) {
    echo "Unable to load $item->nid\n";
    continue;
  }
  $action->execute(array_values($nodes));
}
