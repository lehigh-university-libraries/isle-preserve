<?php

lehigh_islandora_cron_account_switcher();

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
