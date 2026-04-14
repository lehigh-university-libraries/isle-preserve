<?php

/**
 * @file
 * Post updates for islandora_collection_tabs.
 */

/**
 * Updates the collection tabs field storage definition.
 */
function islandora_collection_tabs_post_update_add_display_mode(&$sandbox = NULL): void {
  $entity_definition_update_manager = \Drupal::entityDefinitionUpdateManager();
  $entity_field_manager = \Drupal::service('entity_field.manager');

  $field_storage_definitions = $entity_field_manager->getFieldStorageDefinitions('node');
  if (!isset($field_storage_definitions['field_collection_tabs'])) {
    return;
  }

  $entity_definition_update_manager->updateFieldStorageDefinition($field_storage_definitions['field_collection_tabs']);
}
