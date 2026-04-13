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

  public const DISPLAY_TABS = 'tabs';
  public const DISPLAY_INLINE = 'inline';
  public const DISPLAY_IIIP = 'iiip';

  /**
   * Returns the available display mode options.
   */
  public static function getDisplayOptions(): array {
    return [
      static::DISPLAY_TABS => t('Tabs'),
      static::DISPLAY_INLINE => t('Inline'),
      static::DISPLAY_IIIP => t('IIIP'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    $value = $this->get('value')->getValue();
    $display = $this->get('display')->getValue();
    return $this->label === NULL
      && empty($value)
      && empty($this->map)
      && empty($this->default)
      && ($display === NULL || $display === static::DISPLAY_TABS);
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
    $properties['display'] = DataDefinition::create('string')
      ->setLabel(t('Display mode'));
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
      'display' => [
        'type' => 'varchar',
        'length' => 32,
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
    $values['display'] = static::DISPLAY_TABS;

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
