<?php

use Drupal\media\Entity\Media;

$action_name = 'generate_a_jp2_service_file';

// TIFF and JP2s original files
// that do not have a service file
$sql = "SELECT mo.field_media_of_target_id
  FROM media_field_data m
  INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
  INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
  INNER JOIN media__field_media_file mf ON mf.entity_id = mu.entity_id
  INNER JOIN file_managed f ON f.fid = field_media_file_target_id
  WHERE mu.field_media_use_target_id = 16
    AND (uri LIKE '%.tif' OR uri LIKE '%.tiff' OR uri LIKE '%.jp2')
    AND mo.field_media_of_target_id IN (
      SELECT mo.field_media_of_target_id FROM media_field_data m
      INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
      INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
      INNER JOIN media__field_media_image mi ON m.mid = mi.entity_id
      WHERE mu.field_media_use_target_id = 18
    )
  GROUP BY mo.field_media_of_target_id";

lehigh_islandora_cron_account_switcher();

$entity_type_manager = \Drupal::entityTypeManager();
$node_storage   = $entity_type_manager->getStorage('node');
$action_storage = $entity_type_manager->getStorage('action');
$action = $action_storage->load($action_name);

// check the queue depth
// islandora's actions have the queue set in the config
// and we're mounting the activemq pass into the container as a secret
$queueName = $action->configuration['queue'];
$data = lehigh_islandora_get_queue_depth($queueName);
$depth = $data[$queueName] ?? null;
if (is_null($depth)) {
  echo "Unable to find queue depth for ", $queueName, "\n";
  exit(1);
}
if ($depth > 0) {
  echo "Queue depth for ", $queueName, " greater than zero ", $depth, "\n";
  exit(1);
}

$nids = \Drupal::database()->query($sql)->fetchCol();
if (count($nids) == 0) {
  exit(1);
}

foreach ($nids as $nid) {
  try {
    $nodes = $node_storage->loadMultiple([$nid]);
  } catch (Exception $e) {
    continue;
  }
  $mid = \Drupal::database()->query("SELECT mo.entity_id FROM media__field_media_of mo
    INNER JOIN media__field_media_use mu ON mu.entity_id = mo.entity_id
    WHERE field_media_use_target_id = 18 AND field_media_of_target_id = :nid", [':nid' => $nid])->fetchField();
  $media = Media::load($mid);
  if ($media) {
    if (!is_null($media->field_media_image->entity)) $media->field_media_image->entity->delete();
    $media->delete();
  }
  $action->execute(array_values($nodes));
}
