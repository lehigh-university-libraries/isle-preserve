<?php

use Drupal\Core\Session\UserSession;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\user\Entity\User;

$userid = 1;
$account = User::load($userid);
$accountSwitcher = Drupal::service('account_switcher');
$userSession = new UserSession([
  'uid'   => $account->id(),
  'name'  => $account->getDisplayName(),
  'roles' => $account->getRoles(),
]);
$accountSwitcher->switchTo($userSession);

$entity_type_manager = \Drupal::entityTypeManager();
$node_storage   = $entity_type_manager->getStorage('node');
$file_system = \Drupal::service('file_system');
$base_url = \Drupal::request()->getSchemeAndHttpHost();

$rows = \Drupal::database()->query("SELECT field_media_of_target_id, media_of.entity_id
  FROM media__field_media_of media_of
  INNER JOIN media__field_media_use u ON u.entity_id = media_of.entity_id
  INNER JOIN node_field_data n ON n.nid = field_media_of_target_id
  WHERE n.status = 1
    AND media_of.bundle IN ('audio', 'video')
    AND field_media_use_target_id = 16
    AND field_media_of_target_id NOT IN (
      SELECT mo.field_media_of_target_id FROM media_field_data m
      INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
      INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
      WHERE mu.bundle IN ('audio', 'video') AND mu.field_media_use_target_id = 18
    )
  ORDER BY media_of.entity_id")->fetchAllKeyed();


foreach($rows as $nid => $mid) {
  $media = Media::load($mid);
  if (!$media) {
    continue;
  }
  $file_field = $media->bundle() == 'audio' ? 'field_media_audio_file' : 'field_media_video_file';
  $fileEntity = $media->get($file_field)->entity;
  if (is_null($fileEntity)) {
    continue;
  }

  $file = lehigh_islandora_fcrepo_realpath($fileEntity->uri->value);
  if (!file_exists($file) || is_dir($file)) {
    continue;
  }

  $uri = "public://derivatives/hls/node/$nid/$mid.m3u8";
  $dir = dirname($uri);
  $file_system->prepareDirectory($dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
  $base_dir = $file_system->realpath($dir);

  $cmd = "ffmpeg -i \"$file\" -vf \"format=yuv420p\" -profile:v baseline -level 3.0 -s 640x360 -start_number 0 -hls_time 10 -hls_list_size 0 -f hls -b:v 800k -maxrate 800k -bufsize 1200k -b:a 96k $base_dir/$mid.m3u8";
  exec($cmd);
  if (!file_exists("$base_dir/$mid.m3u8")) {
    continue;
  }

  $file = File::create([
    'filename' => "$mid.m3u8",
    'uri' => $uri,
    'status' => 1,
    'filemime' => 'application/vnd.apple.mpegurl',
  ]);
  $file->save();
  $media = Media::create([
    'name' => "$nid - HLS",
    'bundle' => $media->bundle(),
    $file_field => $file->id(),
    'field_media_of' => $nid,
    'field_media_use' => 18,
    'status' => 1,
    'field_mime_type' => 'application/vnd.apple.mpegurl',
  ]);
  $media->save();
}
