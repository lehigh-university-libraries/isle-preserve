<?php

use Drupal\Core\File\FileSystemInterface;
use Drupal\node\Entity\Node;

$serializer = \Drupal::service('serializer');
$fileSystem = \Drupal::service('file_system');

$nids = \Drupal::database()->query("SELECT entity_id
  FROM node__field_member_of
  GROUP BY entity_id
  ORDER BY RAND()")->fetchCol();
foreach ($nids as $nid) {
  $cache_path = 'private://serialized/node/' . $nid . '.json';
  $file_path = $fileSystem->realpath($cache_path);
  if (file_exists($file_path)) {
    continue;
  }

  $node = Node::load($nid);
  $base_dir = dirname($cache_path);
  $fileSystem->prepareDirectory($base_dir, FileSystemInterface::CREATE_DIRECTORY);
  $json = $serializer->serialize($node, 'json', ['plugin_id' => 'entity']);
  $file_path = $base_dir . "/$nid.json";
  $f = fopen($file_path, 'w');
  if ($f) {
    fwrite($f, $json);
    fclose($f);
  }
}
