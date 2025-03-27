<?php

use Drupal\Core\Session\UserSession;
use Drupal\user\Entity\User;
use Drupal\taxonomy\Entity\Term;

$userid = 1;
$account = User::load($userid);
$accountSwitcher = Drupal::service('account_switcher');
$userSession = new UserSession([
  'uid'   => $account->id(),
  'name'  => $account->getDisplayName(),
  'roles' => $account->getRoles(),
]);
$accountSwitcher->switchTo($userSession);

$results = \Drupal::database()->query("SELECT GROUP_CONCAT(tid), CONCAT(vid, ':', name) AS t
  FROM {taxonomy_term_field_data}
  GROUP BY CONCAT(vid, ':', name) HAVING COUNT(*) > 1")->fetchAllKeyed();
foreach ($results as $tids => $vidName) {
  $tids = explode(',', $tids);
  $tid = array_shift($tids);
  $tids = implode(',', $tids);

  $vidName = explode(':', $vidName);
  $vid = array_shift($vidName);

  $table = FALSE;
  switch($vid) {
    case 'person':
    case 'corporate_body':
      $table = "field_linked_agent";
      break;
    case 'lcsh_topic':
      $table = "field_lcsh_topic";
      break;
    case 'physical_form':
      $table = "field_physical_form";
      break;
    case 'geographic_naf':
      $table = "field_geographic_subject";
      break;
  }

  if (!$table) {
    \Drupal::messenger()->addError("Unable to merge $vid");
    continue;
  }

  $entity_tables = [
    'node',
    'node_revision',
  ];
  $d_args = [
    ':tid' => $tid,
    ':tids' => $tids,
  ];
  foreach ($entity_tables as $entity_table) {
    \Drupal::database()->query("UPDATE {$entity_table}__{$table}
      SET {$table}_target_id = :tid
      WHERE {$table}_target_id IN (:tids)", $d_args);
  }
  \Drupal::database()->query("UPDATE {taxonomy_index}
    SET tid = :tid
    WHERE tid IN (:tids)", $d_args);

  foreach (explode(',', $tids) as $tid) {
    $term = Term::load($tid);
    if ($term) {
      $term->delete();
    }
  }
}
