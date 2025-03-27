<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\lehigh_islandora\Plugin\Field\FieldType\PartDetailItem;

/**
 * Plugin implementation of the 'part_detail_default' formatter.
 *
 * @FieldFormatter(
 *   id = "part_detail_default",
 *   label = @Translation("Default"),
 *   field_types = {"part_detail"},
 * )
 */
final class PartDetailDefaultFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $element = [];

    foreach ($items as $delta => $item) {

      if ($item->type) {
        $allowed_values = PartDetailItem::allowedTypeValues();
        $element[$delta]['type'] = [
          '#type' => 'item',
          '#title' => $this->t('Type'),
          '#markup' => $allowed_values[$item->type],
        ];
      }

      if ($item->caption) {
        $element[$delta]['caption'] = [
          '#type' => 'item',
          '#title' => $this->t('Caption'),
          '#markup' => $item->caption,
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
