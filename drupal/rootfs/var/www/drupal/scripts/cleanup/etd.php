<?php

use Drupal\redirect\Entity\Redirect;
use Drupal\Core\Url;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;

$media_storage = \Drupal::entityTypeManager()->getStorage('media');
$utils = \Drupal::service('islandora.utils');

$filename = __DIR__ . '/preserve.csv';
$rows = array_map('str_getcsv', file($filename));
$headers = array_map('trim', $rows[0]);
$headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
$data = array_slice($rows, 1);

$keeps = [];
$redirects = [];

foreach ($data as $row) {
  $record = array_combine($headers, $row);
  $title = normalize_title($record['Title']);

  if (strtolower($record['Keep?']) !== 'keep') {
    continue;
  }
  $keeps[$title] = [
    'id' => $record['nid'],
    'url' => $record['URL']
  ];
}

foreach ($data as $row) {
  $record = array_combine($headers, $row);
  if (strtolower($record['Keep?']) !== 'delete') {
    continue;
  }
  $title = normalize_title($record['Title']);
  if (!isset($keeps[$title])) {
    echo $record['Title'], "\n";exit;
  }
  $redirects[$title][] = [
    'from_id' => $record['nid'],
    'from_url' => $record['URL'],
    'to_id'   => $keeps[$title]['id'],
  ];
}

foreach ($redirects as $redirect) {
  $create_redirect = count($redirect) == 1;
  foreach ($redirect as $node) {
    echo "DELETING ", $node['from_id'], "\n";
    $entity = Node::load($node['from_id']);
    $media_ids = $media_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_media_of.target_id', $entity->id())
      ->execute();

    $media = $media_storage->loadMultiple($media_ids);
    $utils->deleteMediaAndFiles($media);
    $entity->delete();

    if (!$create_redirect) {
      continue;
    }
    $redirect = Redirect::create([
      'redirect_source' => [
        'path' => '/node/' . $node['from_id'],
      ],
      'redirect_redirect' => [
        'uri' => 'internal:/node/' . $node['to_id'],
      ],
      'status_code' => 301,
    ]);
    $redirect->save();

    $redirect = Redirect::create([
      'redirect_source' => [
        'path' => str_replace('https://preserve.lehigh.edu', '', $node['from_url']),
      ],
      'redirect_redirect' => [
        'uri' => 'internal:/node/' . $node['to_id'],
      ],
      'status_code' => 301,
    ]);

    $redirect->save();
  }
  if (!$create_redirect) {
    print_r($redirect);
  }
}

function normalize_title($title) {
    $title = trim($title);
    $title = preg_replace('/\s+/', ' ', $title);
    $title = strtolower(html_entity_decode($title));
    return $title;
}
