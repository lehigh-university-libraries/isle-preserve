<?php

/**
 * Ensure paged content PDFs have as many pages as children.
 */

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

$action_name = 'paged_content_created_aggregated_pdf';
$entity_type_manager = \Drupal::entityTypeManager();
$node_storage   = $entity_type_manager->getStorage('node');
$action_storage = $entity_type_manager->getStorage('action');
$action = $action_storage->load($action_name);

$sql = "SELECT m.entity_id AS nid, f.uri, COUNT(*) AS children
  FROM node_field_data n
  INNER JOIN node__field_model m ON m.entity_id = n.nid
  INNER JOIN node__field_member_of c ON c.field_member_of_target_id = m.entity_id
  INNER JOIN media__field_media_of mo ON m.entity_id = field_media_of_target_id
  INNER JOIN media__field_media_document d ON d.entity_id = mo.entity_id
  INNER JOIN file_managed f ON f.fid = field_media_document_target_id
  WHERE field_model_target_id = :tid
    AND n.created > UNIX_TIMESTAMP()-86400
  GROUP BY m.entity_id
  ORDER BY m.entity_id";
$d_args = [
  ':tid' => lehigh_islandora_get_tid_by_name("Paged Content", "islandora_models"),
];
$items = \Drupal::database()->query($sql, $d_args);

foreach ($items as $item) {
  if (strpos($item->uri, "fedora://") !== FALSE) {
    $item->uri = lehigh_islandora_fcrepo_realpath($item->uri);
  }
  else {
    $item->uri = str_replace("private://", "/var/www/drupal/private/", $item->uri);
  }

  if (!file_exists($item->uri) || is_dir($item->uri)) {
    echo "Unable to find file for $item->nid $item->uri\n";
    continue;
  }

  $cmd = "pdfinfo \"$item->uri\" | awk '/Pages/ {print \$2}'";
  $output = [];
  exec($cmd, $output);
  if (!empty($output[0]) && $output[0] == $item->children) {
    continue;
  }

  echo "Need new PDF for $item->nid at $item->uri. $output[0] !== $item->children\n";
  try {
    $nodes = $node_storage->loadMultiple([$item->nid]);
  } catch (Exception $e) {
    echo "Unable to load $item->nid\n";
    continue;
  }
  $action->execute(array_values($nodes));
}
