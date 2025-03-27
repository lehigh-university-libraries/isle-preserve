<?php

use Drupal\Core\Session\UserSession;
use Drupal\user\Entity\User;
use Drupal\node\Entity\Node;

// login drush as UID 1
$userid = 1;
$account = User::load($userid);
$accountSwitcher = Drupal::service('account_switcher');
$userSession = new UserSession([
  'uid'   => $account->id(),
  'name'  => $account->getDisplayName(),
  'roles' => $account->getRoles(),
]);
$accountSwitcher->switchTo($userSession);

$nids = \Drupal::database()->query("SELECT DISTINCT(entity_id) FROM {node__field_date_other}")->fetchCol();
foreach ($nids as $nid) {
  $node = Node::load($nid);
  if (!$node) {
    continue;
  }

  $other = [];
  foreach ($node->field_date_other as $date) {
    if (in_array($date->attr0, ['start', 'end'])) {
      continue;
    }

    if ($date->attr0 != 'season') {
      $other[] = [
        'attr0' => $date->attr0,
        'value' => $date->value,
      ];
    }

    $tid = lehigh_islandora_get_tid_by_name($date->value, 'season', TRUE);
    $node->set('field_date_season', $tid);
  }
  $node->set('field_date_other', $other);
  try {
    $node->save();
  } catch (Exception $e) {}
}
