<?php

$drupalRoot = getenv('DRUPAL_ROOT');
if (!$drupalRoot || !is_file($drupalRoot . '/core/tests/bootstrap.php')) {
  throw new RuntimeException('Set DRUPAL_ROOT to the Drupal web directory.');
}
require $drupalRoot . '/core/tests/bootstrap.php';
$loader = require $drupalRoot . '/../vendor/autoload.php';
$loader->addPsr4('Drupal\\islandora\\', $drupalRoot . '/modules/contrib/islandora/src');
$loader->addPsr4('Drupal\\media\\', $drupalRoot . '/core/modules/media/src');
$loader->addPsr4('Drupal\\file\\', $drupalRoot . '/core/modules/file/src');
$loader->addPsr4('Drupal\\islandora_scribe\\', dirname(__DIR__) . '/src');
