<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\Plugin\Field\FieldType;

use Drupal\Component\Utility\Random;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Defines the 'hierarchical_geographic' field type.
 *
 * @FieldType(
 *   id = "hierarchical_geographic",
 *   label = @Translation("Hierarchical Geographic"),
 *   category = @Translation("General"),
 *   default_widget = "hierarchical_geographic",
 *   default_formatter = "hierarchical_geographic_default",
 * )
 */
final class HierarchicalGeographicItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    return $this->continent === NULL && $this->country === NULL && $this->region === NULL && $this->state === NULL && $this->territory === NULL && $this->county === NULL && $this->city === NULL && $this->city_section === NULL && $this->island === NULL && $this->area === NULL && $this->extraterrestrial_area === NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {

    $properties['continent'] = DataDefinition::create('string')
      ->setLabel(t('Continent'));
    $properties['country'] = DataDefinition::create('string')
      ->setLabel(t('Country'));
    $properties['region'] = DataDefinition::create('string')
      ->setLabel(t('Region'));
    $properties['state'] = DataDefinition::create('string')
      ->setLabel(t('State'));
    $properties['territory'] = DataDefinition::create('string')
      ->setLabel(t('Territory'));
    $properties['county'] = DataDefinition::create('string')
      ->setLabel(t('County'));
    $properties['city'] = DataDefinition::create('string')
      ->setLabel(t('City'));
    $properties['city_section'] = DataDefinition::create('string')
      ->setLabel(t('City Section'));
    $properties['island'] = DataDefinition::create('string')
      ->setLabel(t('Island'));
    $properties['area'] = DataDefinition::create('string')
      ->setLabel(t('Area'));
    $properties['extraterrestrial_area'] = DataDefinition::create('string')
      ->setLabel(t('Extraterrestrial Area'));

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
      'continent' => [
        'type' => 'varchar',
        'length' => 255,
      ],
      'country' => [
        'type' => 'varchar',
        'length' => 255,
      ],
      'region' => [
        'type' => 'varchar',
        'length' => 255,
      ],
      'state' => [
        'type' => 'varchar',
        'length' => 255,
      ],
      'territory' => [
        'type' => 'varchar',
        'length' => 255,
      ],
      'county' => [
        'type' => 'varchar',
        'length' => 255,
      ],
      'city' => [
        'type' => 'varchar',
        'length' => 255,
      ],
      'city_section' => [
        'type' => 'varchar',
        'length' => 255,
      ],
      'island' => [
        'type' => 'varchar',
        'length' => 255,
      ],
      'area' => [
        'type' => 'varchar',
        'length' => 255,
      ],
      'extraterrestrial_area' => [
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

    $values['continent'] = $random->word(mt_rand(1, 255));

    $values['country'] = $random->word(mt_rand(1, 255));

    $values['region'] = $random->word(mt_rand(1, 255));

    $values['state'] = $random->word(mt_rand(1, 255));

    $values['territory'] = $random->word(mt_rand(1, 255));

    $values['county'] = $random->word(mt_rand(1, 255));

    $values['city'] = $random->word(mt_rand(1, 255));

    $values['city_section'] = $random->word(mt_rand(1, 255));

    $values['island'] = $random->word(mt_rand(1, 255));

    $values['area'] = $random->word(mt_rand(1, 255));

    $values['extraterrestrial_area'] = $random->word(mt_rand(1, 255));

    return $values;
  }

}
