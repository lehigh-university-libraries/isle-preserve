<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\Plugin\Field\FieldType;

use Drupal\Component\Utility\Random;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Defines the 'related_item' field type.
 *
 * @FieldType(
 *   id = "related_item",
 *   label = @Translation("Related Item"),
 *   category = @Translation("General"),
 *   default_widget = "related_item",
 *   default_formatter = "related_item_default",
 * )
 */
final class RelatedItemItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    return empty($this->identifier) && empty($this->number) && empty($this->title);
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {

    $properties['identifier'] = DataDefinition::create('string')
      ->setLabel(t('Identifier'));
    $properties['identifier_type'] = DataDefinition::create('string')
      ->setLabel(t('Identifier Type'));
    $properties['number'] = DataDefinition::create('string')
      ->setLabel(t('Number'));
    $properties['title'] = DataDefinition::create('string')
      ->setLabel(t('Title'));

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function getConstraints(): array {
    $constraints = parent::getConstraints();

    $options['identifier_type']['AllowedValues'] = array_keys(RelatedItemItem::allowedIdentifierTypeValues());

    $constraint_manager = \Drupal::typedDataManager()->getValidationConstraintManager();
    $constraints[] = $constraint_manager->create('ComplexData', $options);
    // @todo Add more constraints here.
    return $constraints;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition): array {

    $columns = [
      'identifier' => [
        'type' => 'varchar',
        'length' => 255,
      ],
      'identifier_type' => [
        'type' => 'varchar',
        'length' => 255,
      ],
      'number' => [
        'type' => 'varchar',
        'length' => 255,
      ],
      'title' => [
        'type' => 'varchar',
        'length' => 1024,
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

    $values['identifier'] = $random->word(mt_rand(1, 255));

    $values['identifier_type'] = array_rand(self::allowedIdentifierTypeValues());

    $values['number'] = $random->word(mt_rand(1, 255));

    $values['title'] = $random->word(mt_rand(1, 255));

    return $values;
  }

  /**
   * Returns allowed values for 'identifier_type' sub-field.
   */
  public static function allowedIdentifierTypeValues(): array {
    return [
      'l-issn' => t('ISSN'),
      'audio-file' => t('Audio File'),
      'transcription-file' => t('Transcription File'),
      'uri' => t('URI'),
    ];
  }

}
