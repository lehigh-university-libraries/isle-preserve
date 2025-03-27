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

$image_action    = $action_storage->load('image_generate_a_thumbnail_from_an_original_file');
$document_action = $action_storage->load('digital_document_generate_a_thumbnail_from_an_original_file');

while (1) {
  $nids = \Drupal::database()->query("SELECT mo.field_media_of_target_id, m.bundle
    FROM media_field_data m
    INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
    INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
    WHERE mu.field_media_use_target_id = '16'
      AND m.bundle IN ('document', 'file', 'image')
      AND mo.field_media_of_target_id NOT IN (
        SELECT mo.field_media_of_target_id FROM media_field_data m
        INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
        INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
        WHERE mu.field_media_use_target_id = '19'
      )
    LIMIT 100")->fetchAllKeyed();

  if (count($nids) == 0) {
    break;
  }
  $nodes = $node_storage->loadMultiple(array_keys($nids));
  foreach($nids as $nid => $bundle) {
    if ($bundle == 'document') {
      $document_action->execute([
        $nodes[$nid]
      ]);
    }
    else {
      $image_action->execute([
        $nodes[$nid]
      ]);
    }
  }
}
