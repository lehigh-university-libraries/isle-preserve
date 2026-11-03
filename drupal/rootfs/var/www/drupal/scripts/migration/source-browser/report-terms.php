<?php

/**
 * @file
 * Read-only inventory: drush php:script report-terms.php -- ROOT_NODE_ID > terms.csv.
 */

require_once __DIR__ . '/term-normalize.php';

$root = $extra[0] ?? NULL;
if (!$root || !ctype_digit((string) $root) || !\Drupal::entityTypeManager()->getStorage('node')->load($root)) {
  throw new \InvalidArgumentException('Pass an existing collection node ID after --.');
}
$storage = \Drupal::entityTypeManager()->getStorage('node');
$seen = [(int) $root => TRUE];
$frontier = [(int) $root];
while ($frontier) {
  $children = $storage->getQuery()->accessCheck(FALSE)->condition('field_member_of', $frontier, 'IN')->execute();
  $frontier = [];
  foreach ($children as $id) {
    $id = (int) $id;
    if (!isset($seen[$id])) {
      $seen[$id] = TRUE;
      $frontier[] = $id;
    }
  }
}
$fields = [
  'field_subject', 'field_subject_general', 'field_subject_lcsh',
  'field_subjects_name', 'field_geographic_subject', 'field_temporal_subject',
];
$rows = [];
$labels = [];
foreach (array_chunk(array_keys($seen), 250) as $ids) {
  foreach ($storage->loadMultiple($ids) as $node) {
    foreach ($fields as $field) {
      if (!$node->hasField($field)) {
        continue;
      }
      foreach ($node->get($field)->referencedEntities() as $term) {
        $label = $term->label();
        $normalized = source_browser_normalize_term($label);
        $key = $term->bundle() . ':' . mb_strtolower(preg_replace('/\s+/u', ' ', trim($normalized['label'])));
        $labels[$key][$term->id()] = $term->id();
        $rows[] = [
          $key, $node->id(), $node->label(), (int) $node->isPublished(),
          $field, $term->id(), $term->bundle(), $label, $normalized,
        ];
      }
    }
  }
  $storage->resetCache($ids);
}
$out = fopen('php://output', 'w');
fputcsv($out, [
  'node_id', 'node_title', 'published', 'field', 'term_id', 'vocabulary',
  'term', 'normalized_term', 'possible_split_parts', 'review_flags', 'same_label_term_ids',
]);
foreach ($rows as [$key, $nid, $title, $published, $field, $tid, $vocabulary, $label, $normalized]) {
  $parts = count($normalized['parts']) > 1 ? json_encode($normalized['parts'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : '';
  $duplicates = count($labels[$key]) > 1 ? implode(';', $labels[$key]) : '';
  fputcsv($out, [
    $nid, $title, $published, $field, $tid, $vocabulary, $label,
    $normalized['label'], $parts, implode('; ', $normalized['flags']), $duplicates,
  ]);
}
fclose($out);
