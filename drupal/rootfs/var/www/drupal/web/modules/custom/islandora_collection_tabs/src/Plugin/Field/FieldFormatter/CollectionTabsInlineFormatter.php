<?php

declare(strict_types=1);

namespace Drupal\islandora_collection_tabs\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Renders collection tabs as inline sections.
 */
#[FieldFormatter(
  id: 'islandora_collection_tabs_inline',
  label: new TranslatableMarkup('Inline sections'),
  field_types: [
    'islandora_collection_tabs',
  ],
)]
final class CollectionTabsInlineFormatter extends CollectionTabsDefaultFormatter {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    return $this->buildInlineElements($items);
  }

}
