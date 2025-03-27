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

// get all the nodes that had a field_subjects_name term that isn't in the LCNAF vocabulary
$nids = \Drupal::database()->query("SELECT n.entity_id from node__field_subjects_name n
  INNER JOIN taxonomy_term_field_data t ON t.tid = field_subjects_name_target_id
  WHERE vid <> 'subject_lcnaf'
  GROUP BY n.entity_id")->fetchAllKeyed(0, 0);

foreach ($nids as $nid) {
  $node = Node::load($nid);
  if (!$node) {
    continue;
  }
  $field_subjects_name = [];
  foreach ($node->field_subjects_name as $name) {
    $field_subjects_name[] = lehigh_islandora_get_tid_by_name($name->entity->label(), 'subject_lcnaf', TRUE);
  }
  $node->set('field_subjects_name', $field_subjects_name);
  try {
    $node->save();
  } catch (Exception $e) {
    echo "Update for ", $node->label(), " failed\n";
    echo $e->getMessage(), "\n";
    exit(1);
  }
}
