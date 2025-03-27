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

$nids = \Drupal::database()->query("SELECT s.entity_id from node__field_subject_lcsh s
  INNER JOIN node__field_keywords k ON k.entity_id = s.entity_id
  GROUP BY s.entity_id")->fetchCol();
foreach ($nids as $nid) {
  $node = Node::load($nid);
  if (!$node) {
    continue;
  }

  $subjects = [];
  foreach ($node->field_subject_lcsh as $lcsh) {
    if (is_null($lcsh->entity)) {
      continue;
    }
    $unique = TRUE;
    foreach ($node->field_keywords as $keyword) {
      if (is_null($keyword->entity)) {
        continue;
      }

      if (strtolower($lcsh->entity->label()) == strtolower($keyword->entity->label())) {
        $unique = FALSE;
        break;
      }
    }
    if ($unique) {
      $subjects[] = $lcsh->entity->id();
    }
  }
  $node->set('field_subject_lcsh', $subjects);
  try {
    $node->save();
  } catch (Exception $e) {}
}
