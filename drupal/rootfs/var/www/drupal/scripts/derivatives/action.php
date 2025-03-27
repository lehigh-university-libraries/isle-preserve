<?php

use Drupal\Core\Session\UserSession;
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

$entity_type_manager = \Drupal::entityTypeManager();
$node_storage   = $entity_type_manager->getStorage('node');
$action_storage = $entity_type_manager->getStorage('action');
$action = $action_storage->load($action_name);

$nids = \Drupal::database()->query($sql)->fetchCol();
if (count($nids) == 0) {
  exit(0);
}

foreach ($nids as $nid) {
  try {
    $nodes = $node_storage->loadMultiple([$nid]);
  } catch (Exception $e) {
    continue;
  }
  $action->execute(array_values($nodes));
}
