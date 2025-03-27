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
$node_storage = $entity_type_manager->getStorage('node');
$fits_action  = $entity_type_manager->getStorage('action')
  ->load('generate_a_technical_metadata_derivative');

$d_args = [
  // original file taxonomy term ID
  ':original_tid' => 16,
  // the derivative taxonomy term ID we're targeting
  // to generate missing derivatives
  // in this case, FITS
  ':tid' => 32,
];
while (1) {
  $nids = \Drupal::database()->query("SELECT mo.field_media_of_target_id
    FROM media_field_data m
    INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
    INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
    WHERE mu.field_media_use_target_id = :original_tid
      AND m.bundle IN ('document', 'file', 'image', 'audio', 'video')
      AND mo.field_media_of_target_id NOT IN (
        SELECT mo.field_media_of_target_id FROM media_field_data m
        INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
        INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
        WHERE mu.field_media_use_target_id = :tid
      )
    LIMIT 50", $d_args)->fetchCol();
  if (count($nids) == 0) {
    break;
  }

  $nodes = $node_storage->loadMultiple($nids);
  $fits_action->execute($nodes);
  sleep(10);
}
