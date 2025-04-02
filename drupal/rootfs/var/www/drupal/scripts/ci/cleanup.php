<?php

if (getenv("DRUPAL_DEFAULT_SITE_URL") !== "wight.cc.lehigh.edu") {
  exit(1);
}

$connection = \Drupal::database();
$patterns = ['node%', 'media%'];
foreach ($patterns as $pattern) {
  $result = $connection->query("SHOW TABLES LIKE :pattern", [':pattern' => $pattern]);
  $tables = $result->fetchCol();
  foreach ($tables as $table) {
    echo "Truncating table: $table\n";
    $connection->query("TRUNCATE TABLE `$table`");
  }
}
echo "Done\n";
