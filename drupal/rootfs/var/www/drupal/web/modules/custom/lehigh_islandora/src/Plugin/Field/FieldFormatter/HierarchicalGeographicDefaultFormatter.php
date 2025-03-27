<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;

/**
 * Plugin implementation of the 'hierarchical_geographic_default' formatter.
 *
 * @FieldFormatter(
 *   id = "hierarchical_geographic_default",
 *   label = @Translation("Default"),
 *   field_types = {"hierarchical_geographic"},
 * )
 */
final class HierarchicalGeographicDefaultFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $element = [];

    foreach ($items as $delta => $item) {

      if ($item->continent) {
        $element[$delta]['continent'] = [
          '#type' => 'item',
          '#title' => $this->t('Continent'),
          '#markup' => $item->continent,
        ];
      }

      if ($item->country) {
        $element[$delta]['country'] = [
          '#type' => 'item',
          '#title' => $this->t('Country'),
          '#markup' => $item->country,
        ];
      }

      if ($item->region) {
        $element[$delta]['region'] = [
          '#type' => 'item',
          '#title' => $this->t('Region'),
          '#markup' => $item->region,
        ];
      }

      if ($item->state) {
        $element[$delta]['state'] = [
          '#type' => 'item',
          '#title' => $this->t('State'),
          '#markup' => $item->state,
        ];
      }

      if ($item->territory) {
        $element[$delta]['territory'] = [
          '#type' => 'item',
          '#title' => $this->t('Territory'),
          '#markup' => $item->territory,
        ];
      }

      if ($item->county) {
        $element[$delta]['county'] = [
          '#type' => 'item',
          '#title' => $this->t('County'),
          '#markup' => $item->county,
        ];
      }

      if ($item->city) {
        $element[$delta]['city'] = [
          '#type' => 'item',
          '#title' => $this->t('City'),
          '#markup' => $item->city,
        ];
      }

      if ($item->city_section) {
        $element[$delta]['city_section'] = [
          '#type' => 'item',
          '#title' => $this->t('City Section'),
          '#markup' => $item->city_section,
        ];
      }

      if ($item->island) {
        $element[$delta]['island'] = [
          '#type' => 'item',
          '#title' => $this->t('Island'),
          '#markup' => $item->island,
        ];
      }

      if ($item->area) {
        $element[$delta]['area'] = [
          '#type' => 'item',
          '#title' => $this->t('Area'),
          '#markup' => $item->area,
        ];
      }

      if ($item->extraterrestrial_area) {
        $element[$delta]['extraterrestrial_area'] = [
          '#type' => 'item',
          '#title' => $this->t('Extraterrestrial Area'),
          '#markup' => $item->extraterrestrial_area,
        ];
      }

    }

    return $element;
  }

}
