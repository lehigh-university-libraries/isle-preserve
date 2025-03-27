<?php


use Drupal\Core\Session\UserSession;
use Drupal\media\Entity\Media;
use Drupal\user\Entity\User;

$userid = 21;
$account = User::load($userid);
$accountSwitcher = Drupal::service('account_switcher');
$userSession = new UserSession([
  'uid'   => $account->id(),
  'name'  => $account->getDisplayName(),
  'roles' => $account->getRoles(),
]);
$accountSwitcher->switchTo($userSession);

$mids = \Drupal::database()->query("SELECT u.entity_id
  FROM media__field_media_use u
  INNER JOIN media__field_media_of mo ON mo.entity_id = u.entity_id
  INNER JOIN node_field_data n ON n.nid = field_media_of_target_id
  INNER JOIN media__field_media_file mf ON mf.entity_id = u.entity_id
  INNER JOIN file_managed f on f.fid = field_media_file_target_id
  LEFT JOIN media__field_width w ON w.entity_id = u.entity_id
  WHERE n.status = 1
    AND  w.entity_id IS NULL
    AND f.uri LIKE '%.jp2'
    AND field_media_use_target_id = 18
  ORDER BY RAND()
  LIMIT 100")->fetchCol();

if (count($mids) == 0) {
  exit(1);
}

$iiif = \Drupal::service('islandora_iiif');
$lock = \Drupal::lock();

foreach ($mids as $mid) {
  $media = Media::load($mid);
  if (!$media) {
    continue;
  }
  $lock_id = 'lehigh_media_hw_cache_' . $media->id();
  if (!$lock->acquire($lock_id)) {
    continue;
  }

  [$width, $height] = $iiif->getImageDimensions($media->field_media_file->entity);
  if ($width && $height) {
    $media->set('field_width', $width);
    $media->set('field_height', $height);
    $media->save();
  }
  $lock->release($lock_id);
}
