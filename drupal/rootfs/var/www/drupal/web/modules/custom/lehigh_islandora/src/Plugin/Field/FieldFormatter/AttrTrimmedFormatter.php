<?php

namespace Drupal\lehigh_islandora\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\text\Plugin\Field\FieldFormatter\TextTrimmedFormatter;

/**
 * Plugin implementation of the 'attr_trimmed' formatter.
 *
 * @FieldFormatter(
 *   id = "attr_trimmed",
 *   label = @Translation("Trimmed"),
 *   field_types = {
 *     "textarea_attr"
 *   }
 * )
 */
class AttrTrimmedFormatter extends TextTrimmedFormatter {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    foreach ($items as &$item) {
      if (empty($item->format)) {
        $item->format = 'full_html';
      }
    }

    return parent::viewElements($items, $langcode);
  }

}
