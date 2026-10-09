<?php

if (!isset($extra[0]) || !ctype_digit((string) $extra[0])) {
  fwrite(STDERR, "Usage: drush scr scripts/derivatives/scribe-hocr.php <parent-nid>\n");
  exit(1);
}

lehigh_islandora_cron_account_switcher();

$action = \Drupal::entityTypeManager()->getStorage('action')->load('generate_hocr_from_an_image_scribe');
if (!$action) {
  fwrite(STDERR, "Scribe hOCR action is not installed.\n");
  exit(1);
}

$database = \Drupal::database();
$parent_nid = (int) $extra[0];
$pending = [$parent_nid];
$nids = [];
$visited = [];
$storage = \Drupal::entityTypeManager()->getStorage('node');
if (!$storage->load($parent_nid)) {
  fwrite(STDERR, "Parent node was not found.\n");
  exit(1);
}

while ($pending) {
  $nid = array_shift($pending);
  if (isset($visited[$nid])) {
    continue;
  }
  $visited[$nid] = TRUE;
  if ($nid !== $parent_nid) {
    $nids[$nid] = $nid;
  }
  $pending = array_merge($pending, $database->select('node__field_member_of', 'member')
    ->fields('member', ['entity_id'])
    ->condition('field_member_of_target_id', $nid)
    ->execute()
    ->fetchCol());
}

$nodes = $storage->loadMultiple(array_values($nids));
foreach ($nodes as $node) {
  $action->execute([$node]);
}

fwrite(STDOUT, 'Queued Scribe hOCR for ' . count($nodes) . " nodes.\n");
