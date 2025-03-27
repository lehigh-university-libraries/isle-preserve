<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Defines the 'textfield_attr' field type.
 *
 * @FieldType(
 *   id = "textfield_attr",
 *   label = @Translation("Textfield with attributes"),
 *   category = @Translation("General"),
 *   default_widget = "attr_default",
 *   default_formatter = "attr_default",
 * )
 */
final class TextfieldAttrItem extends AttrItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {

    $properties['attr0'] = DataDefinition::create('string')
      ->setLabel(t('Attribute One'));
    $properties['attr1'] = DataDefinition::create('string')
      ->setLabel(t('Attribute Two'));
    $properties['value'] = DataDefinition::create('string')
      ->setLabel(t('Value'));

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition): array {

    $columns = [
      'attr0' => [
        'type' => 'varchar',
        'length' => 255,
      ],
      'attr1' => [
        'type' => 'varchar',
        'length' => 255,
      ],
      'value' => [
        'type' => 'varchar',
        'length' => 255,
      ],
    ];

    $schema = [
      'columns' => $columns,
    ];

    return $schema;
  }

}
