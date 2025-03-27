<?php

/**
 * Create the hiearchical node aliases. This assumes a pattern like
 * [node:field_member_of:0:entity:url:path]/[node:title]
 * So we need to ensure parent aliases are generated first.
 */
function generateAliases($nid) {
  $node_storage = \Drupal::entityTypeManager()->getStorage('node');
  $pathauto_generator = \Drupal::service('pathauto.generator');
  
  $node = $node_storage->load($nid);
  $pathauto_generator->createEntityAlias($node, 'en');

  $children = \Drupal::database()->query("SELECT nid FROM {node_field_data} n
    INNER JOIN {node__field_member_of} m ON m.entity_id = n.nid
    INNER JOIN {node__field_model} model ON model.entity_id = n.nid
    WHERE field_model_target_id != 28 AND type = 'islandora_object' AND field_member_of_target_id = :nid", [
      ':nid' => $nid,
    ])->fetchCol();
  foreach ($children as $child) {
    generateAliases($child);
  }
}

$nids = \Drupal::database()->query("select nid from node_field_data where type = 'islandora_object' AND nid NOT IN (SELECT entity_id FROM node__field_member_of)")->fetchCol();
foreach ($nids as $nid) {
  generateAliases($nid);
}
