<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\lehigh_islandora\Plugin\Field\FieldType\RelatedItemItem;

/**
 * Plugin implementation of the 'related_item_default' formatter.
 *
 * @FieldFormatter(
 *   id = "related_item_default",
 *   label = @Translation("Default"),
 *   field_types = {"related_item"},
 * )
 */
final class RelatedItemDefaultFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $element = [];

    foreach ($items as $delta => $item) {

      if ($item->identifier) {
        $element[$delta]['identifier'] = [
          '#type' => 'item',
          '#title' => $this->t('Identifier'),
          '#markup' => $item->identifier,
        ];
      }

      if ($item->identifier_type) {
        $allowed_values = RelatedItemItem::allowedIdentifierTypeValues();
        $element[$delta]['identifier_type'] = [
          '#type' => 'item',
          '#title' => $this->t('Identifier Type'),
          '#markup' => $allowed_values[$item->identifier_type],
        ];
      }

      if ($item->number) {
        $element[$delta]['number'] = [
          '#type' => 'item',
          '#title' => $this->t('Number'),
          '#markup' => $item->number,
        ];
      }

      if ($item->title) {
        $element[$delta]['title'] = [
          '#type' => 'item',
          '#title' => $this->t('Title'),
          '#markup' => $item->title,
        ];
      }

    }

    return $element;
  }

}
