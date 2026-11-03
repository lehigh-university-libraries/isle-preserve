<?php

/**
 * @file
 * Splits reviewed geographic subject references from a CSV export.
 *
 * Run with drush php:script split-geographic-subjects.php -- FILE.
 */

require_once __DIR__ . '/term-normalize.php';

$path = $extra[0] ?? '';
if (!$path || !is_readable($path)) {
  throw new \InvalidArgumentException('Provide a readable CSV file after --.');
}
$handle = fopen($path, 'rb');
$headers = fgetcsv($handle, 0, ',', '"', '');
if ($headers) {
  $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
}
if (!$headers || array_diff(['node_id', 'field', 'term_id', 'term'], $headers)) {
  throw new \InvalidArgumentException('CSV needs node_id, field, term_id, and term columns.');
}
$nodes = \Drupal::entityTypeManager()->getStorage('node');
$terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$selected = [];
$skipped = 0;
while (($values = fgetcsv($handle, 0, ',', '"', '')) !== FALSE) {
  if ($values === [NULL]) {
    continue;
  }
  if (count($values) !== count($headers)) {
    throw new \InvalidArgumentException('Malformed CSV row.');
  }
  $row = array_combine($headers, $values);
  if ($row['field'] !== 'field_geographic_subject') {
    continue;
  }
  if (!ctype_digit($row['node_id']) || !ctype_digit($row['term_id'])) {
    throw new \InvalidArgumentException('Every geographic row must specify a node ID and term ID.');
  }
  $node = $nodes->load($row['node_id']);
  $term = $terms->load($row['term_id']);
  if (!$node || !$term || !$node->hasField($row['field']) || $term->label() !== $row['term']) {
    throw new \RuntimeException('Missing node or changed term in CSV row ' . $row['node_id'] . '/' . $row['term_id']);
  }
  $normal = source_browser_normalize_term($term->label());
  if (count($normal['parts']) < 2) {
    ++$skipped;
    continue;
  }
  if (in_array('', $normal['parts'], TRUE)) {
    throw new \RuntimeException('Empty split part for term ' . $term->id());
  }
  $references = array_column($node->get($row['field'])->getValue(), 'target_id');
  if (!in_array($term->id(), $references)) {
    ++$skipped;
    continue;
  }
  $selected[(int) $node->id()][(int) $term->id()] = [
    'vocabulary' => $term->bundle(),
    'parts' => array_values(array_unique($normal['parts'])),
  ];
}
fclose($handle);

// Resolve every label before modifying nodes. Ambiguous existing labels need a
// deliberate choice instead of silently merging separate authority records.
$targets = [];
foreach ($selected as $items) {
  foreach ($items as $item) {
    foreach ($item['parts'] as $part) {
      $key = $item['vocabulary'] . ':' . $part;
      if (isset($targets[$key])) {
        continue;
      }
      $matches = $terms->loadByProperties(['vid' => $item['vocabulary'], 'name' => $part]);
      if (count($matches) > 1) {
        throw new \RuntimeException('Multiple exact matches in ' . $item['vocabulary'] . ' for ' . $part);
      }
      $targets[$key] = $matches ? (int) reset($matches)->id() : NULL;
    }
  }
}
$report = [
  'nodes' => count($selected),
  'references' => array_sum(array_map('count', $selected)),
  'new_terms' => count(array_filter($targets, static fn($id) => $id === NULL)),
  'skipped' => $skipped,
  'written' => 0,
];
$transaction = \Drupal::database()->startTransaction();
try {
  foreach ($selected as $nid => $items) {
    $node = $nodes->load($nid);
    $values = $node->get('field_geographic_subject')->getValue();
    $output = [];
    $seen = [];
    foreach ($values as $value) {
      $tid = (int) $value['target_id'];
      if (!isset($items[$tid])) {
        if (!isset($seen[$tid])) {
          $output[] = $value;
          $seen[$tid] = TRUE;
        }
        continue;
      }
      foreach ($items[$tid]['parts'] as $part) {
        $vocabulary = $items[$tid]['vocabulary'];
        $key = $vocabulary . ':' . $part;
        if ($targets[$key] === NULL) {
          $created = $terms->create(['vid' => $vocabulary, 'name' => $part]);
          $created->save();
          $targets[$key] = (int) $created->id();
        }
        $target = $targets[$key];
        if (!isset($seen[$target])) {
          $output[] = ['target_id' => $target];
          $seen[$target] = TRUE;
        }
      }
    }
    $node->set('field_geographic_subject', $output);
    $node->setNewRevision(TRUE);
    $node->setRevisionLogMessage('Split reviewed geographic subjects into individual terms.');
    $node->save();
    ++$report['written'];
  }
}
catch (\Throwable $error) {
  $transaction->rollBack();
  throw $error;
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
