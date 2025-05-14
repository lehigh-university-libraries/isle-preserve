<?php

/**
 * Ensure thumbnail images are valid.
 */

lehigh_islandora_cron_account_switcher();

$entity_type_manager = \Drupal::entityTypeManager();
$node_storage   = $entity_type_manager->getStorage('node');
$action_storage = $entity_type_manager->getStorage('action');

// different media types have different actions for generating their thumbnails
// so load them all so for bad thumbnails we can kick off the correct action
$actions = [
  'document' => $action_storage->load('digital_document_generate_a_thumbnail_from_an_original_file'),
  'image' => $action_storage->load('image_generate_a_thumbnail_from_an_original_file'),
  'file' => $action_storage->load('image_generate_a_thumbnail_from_an_original_file'),
  'video' => $action_storage->load('video_generate_a_thumbnail_at_0_00_03'),
];

$sql = "SELECT mo.field_media_of_target_id AS nid, f.uri, pmu.bundle
  FROM media_field_data m
  INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
  INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
  INNER JOIN media__field_media_image mf ON m.mid = mf.entity_id
  INNER JOIN file_managed f ON f.fid = field_media_image_target_id
  INNER JOIN media__field_media_of pmo ON pmo.field_media_of_target_id = mo.field_media_of_target_id
  INNER JOIN media__field_media_use pmu ON pmu.entity_id = pmo.entity_id
  WHERE m.created > UNIX_TIMESTAMP()-86400
    AND mu.field_media_use_target_id = :tid
    AND pmu.field_media_use_target_id = :ptid
  GROUP BY mo.field_media_of_target_id";
$d_args = [
  ':tid' => lehigh_islandora_get_tid_by_name("Thumbnail File", "islandora_media_use"),
  ':ptid' => lehigh_islandora_get_tid_by_name("Original File", "islandora_media_use"),

];
$items = \Drupal::database()->query($sql, $d_args);

foreach ($items as $item) {
  $item->uri = str_replace("public://", "/var/www/drupal/web/sites/default/files/", $item->uri);

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

  echo "Need new thumbnail for $item->nid at $item->uri.";
  try {
    $nodes = $node_storage->loadMultiple([$item->nid]);
  } catch (Exception $e) {
    echo "Unable to load $item->nid\n";
    continue;
  }
  $actions[$item->bundle]->execute(array_values($nodes));
}
