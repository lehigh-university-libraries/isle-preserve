<?php

declare(strict_types=1);

namespace Drupal\islandora_collection_tabs\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;

/**
 * Plugin implementation of the 'islandora_collection_tabs_inline' formatter.
 *
 * @FieldFormatter(
 *   id = "islandora_collection_tabs_inline",
 *   label = @Translation("Inline sections"),
 *   field_types = {"islandora_collection_tabs"},
 * )
 */
final class CollectionTabsInlineFormatter extends CollectionTabsDefaultFormatter {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    return $this->buildInlineElements($items);
  }

}
