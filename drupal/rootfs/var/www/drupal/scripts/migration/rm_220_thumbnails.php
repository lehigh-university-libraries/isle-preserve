<?php

$file_storage = \Drupal::service('entity_type.manager')->getStorage('file');

while(1) {
  $fids = \Drupal::database()->query("SELECT f.fid FROM media_field_data m
    INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
    INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
    INNER JOIN media__field_media_image mi ON m.mid = mi.entity_id
    INNER JOIN file_managed f ON f.fid = mi.field_media_image_target_id
    WHERE mu.field_media_use_target_id = '19' AND mi.field_media_image_width <= 220
    LIMIT 100")->fetchCol();
  if (!count($fids)) {
    break;
  }

  $files = $file_storage->loadMultiple($fids);
  $file_storage->delete($files);
}


$media_storage = \Drupal::service('entity_type.manager')->getStorage('media');
while(1) {
  $mids = \Drupal::database()->query("SELECT m.mid FROM media_field_data m
    INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
    INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
    INNER JOIN media__field_media_image mi ON m.mid = mi.entity_id
    WHERE mu.field_media_use_target_id = '19' AND mi.field_media_image_width <= 220
    LIMIT 100")->fetchCol();
  if (!count($mids)) {
    break;
  }

  $media_entities = $media_storage->loadMultiple($mids);
  $media_storage->delete($media_entities);
}
