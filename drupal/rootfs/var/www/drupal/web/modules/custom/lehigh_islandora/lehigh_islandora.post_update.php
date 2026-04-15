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
