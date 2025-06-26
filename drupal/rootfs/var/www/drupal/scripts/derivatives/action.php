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
  $queue_name = $action_name . '_' . $nid;

  $insert = TRUE;
  $data = \Drupal::database()->query('SELECT `data`
    FROM {queue}
    WHERE name = :name', [
    ':name' => $queue_name,
  ])->fetchField();
  if (!$data) {
    $data = ['count' => 0];
  }
  elseif (is_string($data)) {
    $insert = FALSE;
    $data = unserialize($data);
  }

  if ($data['count'] > 3) {
    continue;
  }

  $data['count'] += 1;

  if ($insert) {
    \Drupal::database()->query("INSERT INTO {queue} (`name`, `data`, `expire`, `created`) VALUES
      (:name, :data, :expire, :created)", [
        ':name' => $queue_name,
        ':data' => serialize($data),
        ':expire' => 0,
        ':created' => time(),
      ]);
  }
  else {
    \Drupal::database()->query("UPDATE {queue} SET `data` = :data WHERE name = :name", [
        ':name' => $queue_name,
        ':data' => serialize($data),
      ]);
  }

  try {
    $nodes = $node_storage->loadMultiple([$nid]);
  } catch (Exception $e) {
    continue;
  }
  $action->execute(array_values($nodes));
}

// splay how long we sleep so our cron derivative replay
// won't overwhelm the server
$t = rand(5, 300);
echo "Sleeping for $t\n";
sleep($t);
