<?php

lehigh_islandora_cron_account_switcher();

$action_name = 'generate_a_jp2_service_file';
$entity_type_manager = \Drupal::entityTypeManager();
$node_storage   = $entity_type_manager->getStorage('node');
$media_storage   = $entity_type_manager->getStorage('media');
$action_storage = $entity_type_manager->getStorage('action');
$action = $action_storage->load($action_name);

$sql = "SELECT f.entity_id, field_media_of_target_id
FROM media__field_media_file f
INNER JOIN media__field_media_of mo ON mo.entity_id = f.entity_id
INNER JOIN media__field_media_use mu ON mu.entity_id = f.entity_id
INNER JOIN file_managed fi ON fid = field_media_file_target_id
WHERE field_media_use_target_id = :stid
  AND fi.uri LIKE '%.jp2'
  AND f.entity_id NOT IN (
    SELECT entity_id
      FROM media__field_media_use
      WHERE field_media_use_target_id = :otid
    )
";
$d_args = [
  ':stid' => lehigh_islandora_get_tid_by_name("Service File", "islandora_media_use"),
  ':otid' => lehigh_islandora_get_tid_by_name("Original File", "islandora_media_use"),
];
$results = \Drupal::database()->query($sql, $d_args)->fetchAllKeyed();

foreach ($results as $mid => $nid) {
  $media = $media_storage->load($mid);
  $media->field_media_file->delete();
  $media->delete();


  $node = $node_storage->load($nid);
  $action->execute([$node]);
}
