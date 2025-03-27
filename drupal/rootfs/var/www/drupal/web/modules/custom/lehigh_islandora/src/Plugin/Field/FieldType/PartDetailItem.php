<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\Plugin\Field\FieldType;

use Drupal\Component\Utility\Random;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Defines the 'part_detail' field type.
 *
 * @FieldType(
 *   id = "part_detail",
 *   label = @Translation("PartDetail"),
 *   category = @Translation("General"),
 *   default_widget = "part_detail",
 *   default_formatter = "part_detail_default",
 * )
 */
final class PartDetailItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    return empty($this->caption) && empty($this->number) && empty($this->title);
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {

    $properties['type'] = DataDefinition::create('string')
      ->setLabel(t('Type'));
    $properties['caption'] = DataDefinition::create('string')
      ->setLabel(t('Caption'));
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

    $options['type']['AllowedValues'] = array_keys(PartDetailItem::allowedTypeValues());

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
      'type' => [
        'type' => 'varchar',
        'length' => 255,
      ],
      'caption' => [
        'type' => 'varchar',
        'length' => 255,
      ],
      'number' => [
        'type' => 'varchar',
        'length' => 255,
      ],
      'title' => [
        'type' => 'varchar',
        'length' => 255,
      ],
    ];

    $schema = [
      'columns' => $columns,
      // @DCG Add indexes here if necessary.
    ];

    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition): array {

    $random = new Random();

    $values['type'] = array_rand(self::allowedTypeValues());

    $values['caption'] = $random->word(mt_rand(1, 255));

    $values['number'] = $random->word(mt_rand(1, 255));

    $values['title'] = $random->word(mt_rand(1, 255));

    return $values;
  }

  /**
   * Returns allowed values for 'type' sub-field.
   */
  public static function allowedTypeValues(): array {
    // @todo Update allowed values.
    return [
      'article' => t('Article'),
      'heading' => t('Heading'),
      'illustration' => t('Illustration'),
      'page' => t('Page Numbers'),
      'issue' => t('Issue'),
      'section' => t('Section'),
      'volume' => t('Volume'),
    ];
  }

}
