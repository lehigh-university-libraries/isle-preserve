<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

$nids = \Drupal::database()->query("SELECT entity_id, a.alias from node__field_model m
  INNER JOIN path_alias a ON a.path = CONCAT('/node/', m.entity_id)
  WHERE field_model_target_id IN (27, 29, 30)
  GROUP BY m.entity_id
  ORDER BY RAND()")->fetchAllKeyed();
$client = new Client();
$dir = '/var/www/drupal/private';
$options = [
  'timeout' => 600,
];
foreach($nids as $nid => $path) {
  if (!file_exists("$dir/iiif/preserve.lehigh.edu/294de3557d9d00b3d2d8a1e6aab028cf/node/$nid/book-manifest.json")) {
    echo "Crawling https://preserve.lehigh.edu/node/$nid/book-manifest\n";
    try {
      $response = $client->request('GET', "https://preserve.lehigh.edu/node/$nid/book-manifest", $options);
    } catch (RequestException $e) {}
  }
  if (!file_exists("$dir/preserve.lehigh.edu/canonical/0$path/index.html")) {
    echo "Crawling https://preserve.lehigh.edu$path\n";
    try {
      $response = $client->request('GET', "https://preserve.lehigh.edu$path", $options);
    } catch (RequestException $e) {}
  }
}

$nids = \Drupal::database()->query("SELECT entity_id, a.alias from node__field_model m
  INNER JOIN path_alias a ON a.path = CONCAT('/browse-items/', m.entity_id)
  WHERE field_model_target_id IN (23, 27821)
  GROUP BY m.entity_id")->fetchAllKeyed();
foreach($nids as $nid => $path) {
  if (!file_exists("$dir/canonical/preserve.lehigh.edu/0$path/index.html")) {
    echo "Crawling https://preserve.lehigh.edu$path\n";
    try{
      $response = $client->request('GET', "https://preserve.lehigh.edu$path", $options);
    } catch (RequestException $e) {}
  }
}
