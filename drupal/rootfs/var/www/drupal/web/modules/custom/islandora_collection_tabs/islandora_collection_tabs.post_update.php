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
  $schema = \Drupal::database()->schema();

  $field_storage_definitions = $entity_field_manager->getFieldStorageDefinitions('node');
  if (!isset($field_storage_definitions['field_collection_tabs'])) {
    return;
  }

  $column_spec = [
    'type' => 'varchar',
    'length' => 32,
    'not null' => FALSE,
  ];
  foreach (['node__field_collection_tabs', 'node_revision__field_collection_tabs'] as $table) {
    if ($schema->tableExists($table) && !$schema->fieldExists($table, 'field_collection_tabs_display')) {
      $schema->addField($table, 'field_collection_tabs_display', $column_spec);
    }
  }

  $entity_definition_update_manager->updateFieldStorageDefinition($field_storage_definitions['field_collection_tabs']);
}
