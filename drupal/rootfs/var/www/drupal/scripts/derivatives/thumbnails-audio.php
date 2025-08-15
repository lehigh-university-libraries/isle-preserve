<?php

use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;

lehigh_islandora_cron_account_switcher();

$entity_type_manager = \Drupal::entityTypeManager();
$node_storage   = $entity_type_manager->getStorage('node');
$file_system = \Drupal::service('file_system');

// audio items
// that do not have a thumbnail
$rows = \Drupal::database()->query("SELECT mo.field_media_of_target_id, mo.entity_id
  FROM media_field_data m
  INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
  INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
  INNER JOIN node_field_data n ON n.nid = field_media_of_target_id
  WHERE mu.field_media_use_target_id = 16
    AND mo.bundle IN ('audio')
    AND mo.field_media_of_target_id NOT IN (
      SELECT mo.field_media_of_target_id FROM media_field_data m
      INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
      INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
      WHERE mu.field_media_use_target_id = 19
    )
  GROUP BY n.nid")->fetchAllKeyed();

if (count($rows) == 0) {
  exit(1);
}

$year = date('Y');
$month = date('m');
foreach($rows as $nid => $mid) {
  $media = Media::load($mid);
  if (!$media) {
    continue;
  }
  $fileEntity = $media->get('field_media_audio_file')->entity;
  if (is_null($fileEntity)) {
    continue;
  }

  $file = lehigh_islandora_fcrepo_realpath($fileEntity->uri->value);
  if (!file_exists($file) || is_dir($file)) {
    continue;
  }

  $uri = "public://derivatives/audio/node/$year/$month/$nid.jpg";
  $dir = dirname($uri);
  $file_system->prepareDirectory($dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
  $base_dir = $file_system->realpath($dir);

  $escapedFile = str_replace("\$", "\\$", $file);
  $cmd = "ffmpeg -i \"$escapedFile\" -filter_complex \"showwavespic=colors=#FFC627\" -frames:v 1 -f image2pipe -vcodec mjpeg $base_dir/$nid.jpg";
  exec($cmd);
  if (!file_exists("$base_dir/$nid.jpg")) {
    continue;
  }

  $file = File::create([
    'filename' => "$nid.jpg",
    'uri' => $uri,
    'status' => 1,
    'filemime' => 'image/jpeg',
  ]);
  $file->save();
  $media = Media::create([
    'name' => "$nid - thumbnail",
    'bundle' => 'image',
    'field_media_image' => $file->id(),
    'field_media_of' => $nid,
    'field_media_use' => 19,
    'status' => 1,
    'field_mime_type' => 'image/jpeg',
  ]);
  $media->save();
}
