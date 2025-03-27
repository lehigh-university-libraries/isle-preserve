<?php

use Drupal\node\Entity\Node;

$nids = \Drupal::database()->query("SELECT entity_id FROM node__field_model
  WHERE field_model_target_id = 30")->fetchCol();
foreach ($nids as $nid) {
  $node = Node::load($nid);
  if (!$node) {
    continue;
  }
  $node->save();
}
