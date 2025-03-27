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

// get all the nodes that had a department added to its linked_agent field
$nodes = \Drupal::database()->query("SELECT entity_id AS nid, GROUP_CONCAT(t.name SEPARATOR '|') AS department
  FROM node__field_linked_agent
  INNER JOIN taxonomy_term_field_data t ON t.tid = field_linked_agent_target_id
  WHERE field_linked_agent_rel_type = 'label:department'
  GROUP BY entity_id")->fetchAllKeyed();

// move all department linked agents to field_department_name
foreach ($nodes as $nid => $department) {
  $node = Node::load($nid);
  if (!$node) {
    continue;
  }

  $departments = explode("|", $department);
  $field_department_name = [];
  foreach ($departments as $department) {
    $field_department_name[] = lehigh_islandora_get_tid_by_name($department, 'department', true);
  }
  $node->set('field_department_name', $field_department_name);

  // remove the department linked agents
  $linked_agents = [];
  foreach ($node->field_linked_agent as $linked_agent) {
    if ($linked_agent->rel_type != 'label:department') {
      $linked_agents[] = [
        'rel_type' => $linked_agent->rel_type,
        'target_id' => $linked_agent->target_id,
      ];
    }
  }
  $node->set('field_linked_agent', $linked_agents);
  $node->save();
}
