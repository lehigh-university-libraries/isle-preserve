<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Stores curated back-of-book page ranges as Drupal-managed content.
 *
 * @ContentEntityType(
 *   id = "lehigh_source_index_entry",
 *   label = @Translation("Source index entry"),
 *   base_table = "lehigh_source_index_entry",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid"
 *   }
 * )
 */
final class SourceIndexEntry extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    foreach (['source', 'issue', 'page'] as $name) {
      $fields[$name] = BaseFieldDefinition::create('entity_reference')
        ->setLabel(ucfirst($name))->setSetting('target_type', 'node')->setRequired(TRUE);
    }
    $fields['subject'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel('Subject')->setSetting('target_type', 'taxonomy_term')->setRequired(TRUE);
    foreach (['start_page', 'end_page', 'volume'] as $name) {
      $fields[$name] = BaseFieldDefinition::create('integer')->setLabel(ucfirst(str_replace('_', ' ', $name)))->setRequired(TRUE);
    }
    $fields['legacy_id'] = BaseFieldDefinition::create('string')->setLabel('Import row ID')->setRequired(TRUE)->setSetting('max_length', 64);
    return $fields;
  }

}
