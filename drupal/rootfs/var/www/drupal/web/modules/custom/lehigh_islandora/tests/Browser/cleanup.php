<?php

/**
 * @file
 * Deletes only fixture entities recorded by fixture.php.
 */

if (!in_array(getenv('DRUPAL_DEFAULT_SITE_URL'), ['islandora.dev', 'wight.cc.lehigh.edu'], TRUE)) {
  throw new \RuntimeException('Fixture cleanup is restricted to disposable test sites.');
}
$path = $extra[0] ?? '';
if (!str_starts_with($path, '/tmp/source-browser-fixture-') || !is_file($path)) {
  throw new \InvalidArgumentException('Missing fixture state file.');
}
$state = json_decode(file_get_contents($path), TRUE, 512, JSON_THROW_ON_ERROR);
$manager = \Drupal::entityTypeManager();
foreach (['media', 'node', 'file', 'taxonomy_term', 'user'] as $type) {
  $storage = $manager->getStorage($type);
  $entities = $storage->loadMultiple($state['created'][$type] ?? []);
  if ($entities) {
    $storage->delete($entities);
  }
}
$source_id = $state['source'] ?? $state['created']['node'][0] ?? NULL;
if ($source_id) {
  $directory = 'private://source-browser-test-' . (int) $source_id;
  if (is_dir($directory)) {
    \Drupal::service('file_system')->deleteRecursive($directory);
  }
}
unlink($path);
