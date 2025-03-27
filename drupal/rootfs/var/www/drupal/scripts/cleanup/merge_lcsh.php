<?php

use Drupal\Core\Session\UserSession;
use Drupal\user\Entity\User;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

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

// get all the nodes that had a field_subject or field_lcsh_topic term
$nids = \Drupal::database()->query("SELECT DISTINCT(entity_id) FROM {node__field_subject}")->fetchAllKeyed(0, 0);
$nids += \Drupal::database()->query("SELECT DISTINCT(entity_id) FROM {node__field_lcsh_topic}")->fetchAllKeyed(0, 0);
$terms_to_delete = [];
$fields = [
  'field_lcsh_topic',
  'field_subject',
];
foreach ($nids as $nid) {
  $node = Node::load($nid);
  if (!$node) {
    continue;
  }

  $field_subject_lcsh = [];
  $skip_alt = [];
  foreach ($fields as $field) {
    foreach ($node->$field as $term) {
      if (is_null($term->entity)) {
        continue;
      }

      $label = $term->entity->label();
      $label = trim($label);

      // don't process Agile's LCSH transforms
      if (!empty($skip_alt[$label])) {
        $terms_to_delete[$tid] = TRUE;
        continue;
      }

      // make sure agile's transformed imports don't cause duplicates
      if (strpos($label, '--') !== FALSE) {
        $alt = str_replace('--', ", ", $label);
        $skip_alt[$alt] = TRUE;
      }

      $tid = lehigh_islandora_get_tid_by_name($label, 'subject_lcsh', TRUE);
      if (!in_array($tid, $field_subject_lcsh)) {
        $field_subject_lcsh[] = $tid;
      }

      $tid = $term->entity->id();
      $terms_to_delete[$tid] = TRUE;
    }
    $node->set($field, null);
  }

  $node->set('field_subject_lcsh', $field_subject_lcsh);
  try {
    $node->save();
  } catch (Exception $e) {
  }
}

foreach ($terms_to_delete as $tid => $foo) {
  $term = Term::load($tid);
  if (!$term) {
    continue;
  }
  $term->delete();
}
