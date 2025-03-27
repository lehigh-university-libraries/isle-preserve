<?php

use Drupal\Core\Session\UserSession;
use Drupal\user\Entity\User;
use Drupal\media\Entity\Media;

// Get all service file media entities for nodes that have a jp2 original file
$result = \Drupal::database()->query("SELECT mosf.entity_id FROM media__field_mime_type mt
  INNER JOIN media__field_media_use mu ON mu.entity_id = mt.entity_id
  INNER JOIN media__field_media_of mo ON mo.entity_id = mt.entity_id
  INNER JOIN media__field_media_of mosf ON mo.field_media_of_target_id = mosf.field_media_of_target_id
  INNER JOIN media__field_media_use musf ON musf.entity_id = mosf.entity_id AND musf.delta = 0
  WHERE field_mime_type_value = 'image/jp2'
    AND mu.field_media_use_target_id = 16
    AND musf.field_media_use_target_id = 18");

$userid = 1;
$account = User::load($userid);
$accountSwitcher = Drupal::service('account_switcher');
$userSession = new UserSession([
  'uid'   => $account->id(),
  'name'  => $account->getDisplayName(),
  'roles' => $account->getRoles(),
]);
$accountSwitcher->switchTo($userSession);

$media_storage = \Drupal::service('entity_type.manager')->getStorage('media');
foreach ($result as $row) {
    $media = Media::load($row->entity_id);
    if (!$media) {
      continue;
    }

    foreach($media->field_media_use as $mu) {
      if ($mu->entity->id() == 16) {
        continue 2;
      }
    }


    if ($media->bundle() == 'file') {
      if (!$media->field_media_file->isEmpty() && !is_null($media->field_media_file->entity)) {
        $media->field_media_file->entity->delete();
      }
    }
    elseif ($media->bundle() == 'image') {
      if (!$media->field_media_image->isEmpty() && !is_null($media->field_media_image->entity)) {
        $media->field_media_image->entity->delete();
      }
    }
    else {
      continue;
    }
    $media->delete();
}
