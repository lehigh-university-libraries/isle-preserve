<?php

use Drupal\node\Entity\Node;
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

$action_storage = \Drupal::entityTypeManager()->getStorage('action');
$image_action = $action_storage->load('image_generate_a_thumbnail_from_an_original_file');
$document_action = $action_storage->load('digital_document_generate_a_thumbnail_from_an_original_file');

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
    )")->fetchAllKeyed();

$count = 0;
foreach($nids as $nid => $bundle) {
  $node = Node::load($nid);
  if (!$node) {
    continue;
  }
  if ($bundle == 'document') {
    $document_action->execute([$node]);
  }
  else {
    $image_action->execute([$node]);
  }
  if (++$count % 100 == 0) {
    sleep(10);
  }
}
