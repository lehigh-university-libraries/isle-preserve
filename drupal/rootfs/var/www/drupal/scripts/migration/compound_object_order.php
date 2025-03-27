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

$results = \Drupal::database()->query("SELECT field_member_of_target_id AS parent, m.entity_id AS nid
  FROM node__field_model mo
  INNER JOIN node__field_member_of m ON field_member_of_target_id = mo.entity_id
  LEFT JOIN node__field_weight w ON w.entity_id = m.entity_id
  INNER JOIN node__field_pid p ON p.entity_id = m.entity_id
  WHERE field_model_target_id = 30 AND field_weight_value IS NULL
  ORDER BY field_member_of_target_id, field_pid_value");
$field_weight = 1;
$parent = 0;
foreach ($results as $row) {
  $node = Node::load($row->nid);
  if (!$node) {
    continue;
  }

  if ($parent != $row->parent) {
    $field_weight = 1;
    $parent = $row->parent;
  }

  $node->set('field_weight', $field_weight);
  try {
    $node->save();
  } catch (Exception $e) {

  }

  $field_weight += 1;
}
