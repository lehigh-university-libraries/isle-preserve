<?php

/**
 * @file
 * Post updates.
 */

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Enable lehigh_embargo module.
 */
function lehigh_islandora_post_update_enable_embargo() {
  \Drupal::service('module_installer')->install(['lehigh_embargo']);
}

/**
 * Delete original media/file and retag preservation master as original.
 */
function lehigh_islandora_fix_original_and_preservation_media_for_node($nid, array &$counts) {
  $has_media = \Drupal::database()->select('media__field_media_of', 'm')
    ->fields('m', ['entity_id'])
    ->condition('field_media_of_target_id', $nid)
    ->range(0, 1)
    ->execute()
    ->fetchField();

  if (!$has_media) {
    return;
  }

  $media_ids = \Drupal::entityQuery('media')
    ->accessCheck(FALSE)
    ->condition('field_media_of', $nid)
    ->condition('field_media_use', [16, 17], 'IN')
    ->execute();

  if (!$media_ids) {
    return;
  }

  $media_storage = \Drupal::entityTypeManager()->getStorage('media');
  $media_entities = $media_storage->loadMultiple($media_ids);
  $original_media = [];
  $preservation_master_media = [];

  foreach ($media_entities as $media) {
    if (!$media->hasField('field_media_use') ||
      $media->field_media_use->isEmpty()) {
      continue;
    }

    $media_use_tid = (int) $media->field_media_use->target_id;
    if ($media_use_tid === 16) {
      $original_media[] = $media;
    }
    elseif ($media_use_tid === 17) {
      $preservation_master_media[] = $media;
    }
  }

  if (!$original_media || !$preservation_master_media) {
    return;
  }

  foreach ($original_media as $media) {
    $file = lehigh_islandora_get_media_source_file($media);
    if (!$file || strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION)) !== 'pdf') {
      continue;
    }

    $media->delete();
    ++$counts['deleted_media'];

    if (!$file->isNew()) {
      $file->delete();
      ++$counts['deleted_files'];
    }
  }

  foreach ($preservation_master_media as $media) {
    $media->set('field_media_use', 16);
    $media->save();
    ++$counts['updated_media'];
  }

  ++$counts['updated_nodes'];
}

/**
 * Get a media entity's source file.
 */
function lehigh_islandora_get_media_source_file(ContentEntityInterface $media) {
  foreach (['field_media_document', 'field_media_file'] as $field_name) {
    if ($media->hasField($field_name) &&
      !$media->get($field_name)->isEmpty() &&
      !is_null($media->get($field_name)->entity)) {
      return $media->get($field_name)->entity;
    }
  }

  return NULL;
}

/**
 * Fix weather datasets to no longer generate PDFs.
 */
function lehigh_islandora_post_update_fix_collection_456219_original_files(&$sandbox) {
  if (!isset($sandbox['nids'])) {
    $sandbox['nids'] = array_values(lehigh_islandora_get_descendant_item_nids(456219));
    $sandbox['total'] = count($sandbox['nids']);
    $sandbox['processed'] = 0;
    $sandbox['counts'] = [
      'updated_nodes' => 0,
      'deleted_media' => 0,
      'deleted_files' => 0,
      'updated_media' => 0,
    ];
  }

  if (empty($sandbox['total'])) {
    $sandbox['#finished'] = 1;
    return 'No descendant items found for collection 456219.';
  }

  $batch_size = 25;
  $nids = array_slice($sandbox['nids'], $sandbox['processed'], $batch_size);
  foreach ($nids as $nid) {
    lehigh_islandora_fix_original_and_preservation_media_for_node($nid, $sandbox['counts']);
    ++$sandbox['processed'];
  }

  $sandbox['#finished'] = $sandbox['processed'] / $sandbox['total'];

  if ($sandbox['processed'] === $sandbox['total']) {
    return "Updated {$sandbox['counts']['updated_nodes']} nodes, deleted {$sandbox['counts']['deleted_media']} original media entities, deleted {$sandbox['counts']['deleted_files']} files, and retagged {$sandbox['counts']['updated_media']} preservation master media entities.";
  }
}

/**
 * Provide the collection-level source browser display hint.
 */
function lehigh_islandora_post_update_source_browser_display_hint(): string {
  $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  if (!$storage->loadByProperties(['vid' => 'islandora_display', 'name' => 'Journal Browser'])) {
    $storage->create([
      'vid' => 'islandora_display',
      'name' => 'Journal Browser',
      'description' => 'Use the source browser for this collection and its contents.',
    ])->save();
  }
  return 'Select Journal Browser in a collection’s Display Hints to enable the source browser.';
}

/**
 * Install curated source-index storage and browser configuration.
 */
function lehigh_islandora_post_update_source_browser_storage(): string {
  $manager = \Drupal::entityDefinitionUpdateManager();
  if (!$manager->getEntityType('lehigh_source_index_entry')) {
    $manager->installEntityType(\Drupal::entityTypeManager()->getDefinition('lehigh_source_index_entry'));
  }
  $config = \Drupal::configFactory()->getEditable('lehigh_islandora.journal_browser');
  foreach (['page_size' => 25, 'search_index' => 'default_solr_index', 'default_tab' => 'pages'] as $key => $value) {
    if ($config->get($key) === NULL) {
      $config->set($key, $value);
    }
  }
  $config->save();
  return 'Source index storage and browser settings installed.';
}

/**
 * Enable the Pfaffs browser and organize newspaper issues into collections.
 */
function lehigh_islandora_post_update_pfaffs_browser_collections(&$sandbox): string {
  $nodes = \Drupal::entityTypeManager()->getStorage('node');
  $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  $newspapers = [
    'Saturday Press' => 'The New York Saturday Press',
    'Vanity Fair' => 'Vanity Fair',
    'New York Leader' => 'The New York Leader',
  ];

  if (!isset($sandbox['collections'])) {
    if (!$nodes->load(1)) {
      throw new \RuntimeException('Required parent node 1 is missing.');
    }
    foreach ([21, 26, 453243, 453244] as $nid) {
      $node = $nodes->load($nid);
      if (!$node || !$node->hasField('field_member_of') || !$node->hasField('field_display_hints')) {
        throw new \RuntimeException("Required Pfaffs node $nid or its collection fields are missing.");
      }
    }
    $model = $terms->load(23);
    if (!$model || $model->bundle() !== 'islandora_models') {
      throw new \RuntimeException('Required collection model term 23 is missing.');
    }
    $transaction = \Drupal::database()->startTransaction();
    try {
      $hints = $terms->loadByProperties(['vid' => 'islandora_display', 'name' => 'Journal Browser']);
      if (count($hints) > 1) {
        throw new \RuntimeException('Multiple Journal Browser display hints exist.');
      }
      $hint = $hints ? reset($hints) : $terms->create([
        'vid' => 'islandora_display',
        'name' => 'Journal Browser',
        'description' => 'Use the source browser for this collection and its contents.',
      ]);
      if ($hint->isNew()) {
        $hint->save();
      }
      $collections = [];
      foreach ($newspapers as $title => $prefix) {
        $ids = $nodes->getQuery()->accessCheck(FALSE)
          ->condition('type', 'islandora_object')->condition('title', $title)
          ->condition('field_member_of', 1)->execute();
        if (count($ids) > 1) {
          throw new \RuntimeException("Multiple collections named $title belong to node 1.");
        }
        $collection = $ids ? $nodes->load(reset($ids)) : $nodes->create([
          'type' => 'islandora_object',
          'title' => $title,
          'status' => 1,
          'field_member_of' => 1,
          'field_model' => 23,
        ]);
        if (!$collection->isNew() && !in_array(23, array_column($collection->get('field_model')->getValue(), 'target_id'))) {
          throw new \RuntimeException("Existing collection $title does not have model 23.");
        }
        if (!in_array($hint->id(), array_column($collection->get('field_display_hints')->getValue(), 'target_id'))) {
          $collection->get('field_display_hints')->appendItem($hint->id());
        }
        $collection->save();
        $collections[$prefix] = (int) $collection->id();
      }
      foreach ([21, 26, 453243, 453244] as $nid) {
        $node = $nodes->load($nid);
        if (!in_array($hint->id(), array_column($node->get('field_display_hints')->getValue(), 'target_id'))) {
          $node->get('field_display_hints')->appendItem($hint->id());
          $node->save();
        }
      }
      $sandbox['collections'] = $collections;
      $sandbox['moved'] = 0;
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  foreach ($sandbox['collections'] as $prefix => $collection_id) {
    $ids = $nodes->getQuery()->accessCheck(FALSE)
      ->condition('field_member_of', 26)->condition('title', $prefix, 'STARTS_WITH')
      ->range(0, 50)->execute();
    if (!$ids) {
      continue;
    }
    foreach ($nodes->loadMultiple($ids) as $node) {
      $parents = array_values(array_filter($node->get('field_member_of')->getValue(),
        static fn(array $item): bool => (int) $item['target_id'] !== 26));
      if (!in_array($collection_id, array_column($parents, 'target_id'))) {
        $parents[] = ['target_id' => $collection_id];
      }
      $node->set('field_member_of', $parents);
      $node->save();
      $sandbox['moved']++;
    }
    $sandbox['#finished'] = 0;
    return '';
  }
  $sandbox['#finished'] = 1;
  return 'Enabled Pfaffs browser collections and moved ' . $sandbox['moved'] . ' newspaper items.';
}

/**
 * Apply newspaper issue dates using the original Pfaffs identifiers.
 */
function lehigh_islandora_post_update_pfaffs_issue_dates(&$sandbox): string {
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  if (!isset($sandbox['dates'])) {
    $directory = dirname(DRUPAL_ROOT) . '/scripts/migration/source-browser';
    $dates = [];
    $missing = [];
    $identifiers = [];
    foreach (['vfair.csv', 'sat.csv', 'nyleader.csv'] as $name) {
      $file = new \SplFileObject($directory . '/' . $name);
      $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
      $file->setCsvControl(',', '"', '');
      $headers = $file->fgetcsv();
      if ($headers) {
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
      }
      if (!$headers || array_diff(['Date', 'CDM ID'], $headers)) {
        throw new \RuntimeException("Missing date or identifier column in $name.");
      }
      while (!$file->eof()) {
        $values = $file->fgetcsv();
        if (!$values || $values === [NULL]) {
          continue;
        }
        if (count($values) !== count($headers)) {
          throw new \RuntimeException("Malformed row in $name.");
        }
        $row = array_combine($headers, $values);
        $id = trim($row['CDM ID']);
        $date_text = trim($row['Date']);
        if (!ctype_digit($id) || isset($identifiers[$id]) ||
          !preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/D', $date_text, $parts) ||
          !checkdate((int) $parts[1], (int) $parts[2], (int) $parts[3])) {
          throw new \RuntimeException("Invalid or duplicate date mapping in $name for CDM ID $id.");
        }
        $identifiers[$id] = TRUE;
        $identifier = 'pfaffs_' . $id;
        $matches = $storage->getQuery()->accessCheck(FALSE)
          ->condition('field_identifier.value', $identifier)->execute();
        if (count($matches) > 1) {
          throw new \RuntimeException("Multiple nodes match identifier $identifier.");
        }
        if (!$matches) {
          $missing[] = $identifier;
          continue;
        }
        $node = $storage->load(reset($matches));
        if (!$node || !$node->hasField('field_edtf_date_issued')) {
          throw new \RuntimeException("Missing issued-date field for $identifier.");
        }
        if (isset($dates[(int) $node->id()])) {
          throw new \RuntimeException("Multiple CSV identifiers match node {$node->id()}.");
        }
        $dates[(int) $node->id()] = sprintf('%04d-%02d-%02d', $parts[3], $parts[1], $parts[2]);
      }
    }
    $sandbox['dates'] = $dates;
    $sandbox['missing'] = $missing;
    $sandbox['updated'] = 0;
  }

  foreach (array_slice($sandbox['dates'], 0, 50, TRUE) as $nid => $date) {
    $node = $storage->load($nid);
    if (!$node || !$node->hasField('field_edtf_date_issued')) {
      throw new \RuntimeException("Date target node $nid is missing.");
    }
    if ((string) $node->get('field_edtf_date_issued')->value !== $date) {
      $node->set('field_edtf_date_issued', $date);
      $node->setNewRevision(TRUE);
      $node->setRevisionLogMessage('Imported Pfaffs newspaper issue date.');
      $node->save();
      $sandbox['updated']++;
    }
    unset($sandbox['dates'][$nid]);
  }
  if ($sandbox['dates']) {
    $sandbox['#finished'] = 0;
    return '';
  }
  $sandbox['#finished'] = 1;
  if ($sandbox['missing']) {
    \Drupal::logger('lehigh_islandora')->warning('No node matched these Pfaffs identifiers: @identifiers', [
      '@identifiers' => implode(', ', $sandbox['missing']),
    ]);
  }
  return 'Updated ' . $sandbox['updated'] . ' Pfaffs issue dates; ' . count($sandbox['missing']) . ' identifiers were not found (see Drupal log).';
}

/**
 * Correct the Saturday Press collection and move any remaining issues.
 */
function lehigh_islandora_post_update_pfaffs_saturday_press(&$sandbox): string {
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  if (!isset($sandbox['collection'])) {
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'islandora_object')
      ->condition('field_member_of', 1)
      ->condition('title', ['Saturday Press', 'Saturday Morning Press'], 'IN')->execute();
    if (count($ids) !== 1) {
      throw new \RuntimeException('Expected exactly one Saturday Press collection under node 1.');
    }
    $collection = $storage->load(reset($ids));
    if (!in_array(23, array_column($collection->get('field_model')->getValue(), 'target_id'))) {
      throw new \RuntimeException('The Saturday Press collection does not have model 23.');
    }
    if ($collection->label() !== 'Saturday Press') {
      $collection->setTitle('Saturday Press');
      $collection->setNewRevision(TRUE);
      $collection->setRevisionLogMessage('Corrected Saturday Press collection title.');
      $collection->save();
    }
    $sandbox['collection'] = (int) $collection->id();
    $sandbox['moved'] = 0;
  }
  $query = $storage->getQuery()->accessCheck(FALSE)->condition('field_member_of', 26);
  $query->condition($query->orConditionGroup()
    ->condition('title', 'The New York Saturday Press', 'STARTS_WITH')
    ->condition('title', 'The Saturday Press', 'STARTS_WITH')
    ->condition('title', 'Saturday Press', 'STARTS_WITH'));
  $ids = $query->range(0, 50)->execute();
  if ($ids) {
    foreach ($storage->loadMultiple($ids) as $node) {
      $parents = array_values(array_filter($node->get('field_member_of')->getValue(),
        static fn(array $item): bool => (int) $item['target_id'] !== 26));
      if (!in_array($sandbox['collection'], array_column($parents, 'target_id'))) {
        $parents[] = ['target_id' => $sandbox['collection']];
      }
      $node->set('field_member_of', $parents);
      $node->save();
      $sandbox['moved']++;
    }
    $sandbox['#finished'] = 0;
    return '';
  }
  $sandbox['#finished'] = 1;
  return 'Corrected Saturday Press and moved ' . $sandbox['moved'] . ' additional items.';
}
