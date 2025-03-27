<?php

use Drupal\Core\Session\UserSession;
use Drupal\user\Entity\User;
use Drupal\media\Entity\Media;

// Get all service file media entities for nodes that have more than one service file
$result = \Drupal::database()->query('SELECT GROUP_CONCAT(mo.entity_id) AS mids FROM media__field_media_use mu
  INNER JOIN media__field_media_of mo ON mo.entity_id = mu.entity_id
  WHERE field_media_use_target_id = 18
  GROUP BY field_media_of_target_id HAVING COUNT(*) > 1');

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
  $mids = explode(',', $row->mids);
  foreach ($mids as $mid) {
    $media = Media::load($mid);
    if (!$media) {
      continue;
    }

    if ($media->bundle() == 'file') {
      $media->field_media_file->entity->delete();
    }
    elseif ($media->bundle() == 'image') {
      $media->field_media_image->entity->delete();
    }
    else {
      continue;
    }

    $media->delete();
  }
}
