<?php

use Drupal\Core\Session\UserSession;
use Drupal\user\Entity\User;
use Drupal\media\Entity\Media;

// Get all hocr extract text files already created for media entities
$mids = \Drupal::database()->query("SELECT entity_id FROM media__field_hocr_extracted_text")->fetchCol();

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
foreach ($mids as $mid) {
  $media = Media::load($mid);
  if (!$media) {
    continue;
  }

  $hocr_file = $media->field_hocr_extracted_text->entity;
  if ($hocr_file) {
    $hocr_media = Media::create([
      'name' => $media->id() . '-hOCR',
      'bundle' => 'file',
      'field_media_file' => $hocr_file->id(),
      'field_media_of' => $media->field_media_of->entity->id(),
      'field_media_use' => 44903,
      'status' => $media->status->value,
    ]);
    $hocr_media->save();
  }

  $media->set('field_hocr_extracted_text', NULL);
  $media->save();
}
