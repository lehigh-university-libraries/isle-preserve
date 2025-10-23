<?php

declare(strict_types=1);

namespace Drupal\islandora_collection_tabs\Plugin\Field\FieldType;

use Drupal\Component\Utility\Random;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Defines the 'islandora_collection_tabs' field type.
 *
 * @FieldType(
 *   id = "islandora_collection_tabs",
 *   label = @Translation("Collection Tabs"),
 *   description = @Translation("Some description."),
 *   default_widget = "islandora_collection_tabs",
 *   default_formatter = "islandora_collection_tabs_default",
 * )
 */
final class CollectionTabsItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    $value = $this->get('value')->getValue();
    return $this->label === NULL && empty($value);
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {

    $properties['label'] = DataDefinition::create('string')
      ->setLabel(t('Tab Label'));
    $properties['value'] = DataDefinition::create('string')
      ->setLabel(t('Tab Content'));
    $properties['default'] = DataDefinition::create('boolean')
      ->setLabel(t('Default'));
    $properties['map'] = DataDefinition::create('boolean')
      ->setLabel(t('Map'));
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function getConstraints(): array {
    $constraints = parent::getConstraints();

    // @todo Add more constraints here.
    return $constraints;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition): array {

    $columns = [
      'label' => [
        'type' => 'varchar',
        'length' => 255,
      ],
      'value' => [
        'type' => 'text',
        'size' => 'big',
      ],
      'default' => [
        'type' => 'int',
        'size' => 'tiny',
      ],
      'map' => [
        'type' => 'int',
        'size' => 'tiny',
      ],
    ];

    $schema = [
      'columns' => $columns,
    ];

    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition): array {

    $random = new Random();

    $values['label'] = $random->word(mt_rand(1, 255));

    $values['value'] = $random->paragraphs(5);

    $values['default'] = (bool) mt_rand(0, 1);

    $values['map'] = (bool) mt_rand(0, 1);

    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public static function allowedFormatValues(): array {
    // @todo set dynamically
    return [
      'full_html' => t('Full HTML'),
    ];
  }

}
