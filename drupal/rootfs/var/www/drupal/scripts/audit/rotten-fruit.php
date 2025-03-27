<?php

echo date("c"), "\t", "check for images that cause cantaloupe trouble\n";

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

$client = new Client();
$count = 0;
$uris = \Drupal::database()->query("SELECT `uri` from media__field_media_use u
  LEFT JOIN media__field_media_image i ON i.entity_id = u.entity_id
  LEFT JOIN media__field_media_file mf ON mf.entity_id = u.entity_id
  INNER JOIN file_managed f ON f.fid = field_media_image_target_id OR f.fid = field_media_file_target_id
  INNER JOIN media_field_data m ON m.mid = u.entity_id
  INNER JOIN media__field_media_of mo ON mo.entity_id = u.entity_id
  INNER JOIN node_field_data n ON n.nid = field_media_of_target_id
  WHERE field_media_use_target_id = 18 AND m.status = 1 AND n.status = 1")->fetchCol();

foreach ($uris as $uri) {
  $original_uri = $uri;
  $search = [
    "public://",
    "private://",
    "fedora://",
  ];
  $replace = [
    'https://islandora-prod.lib.lehigh.edu/sites/default/files/',
    'https://islandora-prod.lib.lehigh.edu/system/files/',
    'https://islandora-prod.lib.lehigh.edu/_flysystem/fedora/',
  ];
  $uri = str_replace($search, $replace, $uri);
  $uri = urlencode($uri);
  $url = "https://islandora-prod.lib.lehigh.edu/cantaloupe/iiif/2/$uri/square/100,/0/default.jpg";
  try {
    $response = $client->request('GET', $url);
    if ($response->getStatusCode() !== 200) {
      echo $original_uri, "\t", $url, "\n";
    }
  } catch (RequestException $e) {
    echo $original_uri, "\t", $url, "\n";
  }
  if (++$count % 100 == 0) {
    echo date("c"), "processed $count\n";
  }
}
