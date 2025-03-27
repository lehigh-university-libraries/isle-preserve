<?php

use Drupal\Core\Session\UserSession;
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
$action_storage = $entity_type_manager->getStorage('action');

$image_action = $action_storage->load('image_generate_a_service_file_from_an_original_file');
$tiff_action  = $action_storage->load('generate_a_jp2_service_file');

$d_args = [
  // original file taxonomy term ID
  ':original_tid' => 16,
  // the derivative taxonomy term ID we're targeting
  // to generate missing derivatives
  // in this case, Service File
  ':tid' => 18,
];
while (1) {
  $nids = \Drupal::database()->query("SELECT mo.field_media_of_target_id, field_mime_type_value
    FROM media_field_data m
    INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
    INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
    INNER JOIN media__field_mime_type mt ON m.mid = mt.entity_id
    WHERE mu.field_media_use_target_id = :original_tid
      AND m.bundle IN ('file', 'image')
      AND mo.field_media_of_target_id NOT IN (
        SELECT mo.field_media_of_target_id FROM media_field_data m
        INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
        INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
        WHERE mu.field_media_use_target_id = :tid
      )
    LIMIT 100", $d_args)->fetchAllKeyed();
  if (count($nids) == 0) {
    break;
  }

  $nodes = $node_storage->loadMultiple(array_keys($nids));
  foreach ($nids as $nid => $mime_type) {
    switch($mime_type) {
      case 'image/jp2':
      case 'image/jpeg':
        $image_action->execute([
          $nodes[$nid]
        ]);
        break;
      case 'image/tiff':
        $tiff_action->execute([
          $nodes[$nid]
        ]);
        break;
    }
  }
}
