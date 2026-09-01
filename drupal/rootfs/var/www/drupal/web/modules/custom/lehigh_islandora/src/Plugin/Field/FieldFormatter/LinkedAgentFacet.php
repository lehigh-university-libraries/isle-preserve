<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceLabelFormatter;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Links typed agent relations to the browse creator filter.
 */
#[FieldFormatter(
  id: 'typed_relation_facet',
  label: new TranslatableMarkup('Typed relation browse filter link'),
  field_types: [
    'typed_relation',
  ],
)]
final class LinkedAgentFacet extends EntityReferenceLabelFormatter {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = parent::viewElements($items, $langcode);

    foreach ($items as $delta => $item) {

      $rel_types = $item->getRelTypes();
      $rel_type = $rel_types[$item->rel_type] ?? $item->rel_type;
      if (!empty($rel_type)) {
        $elements[$delta]['#prefix'] = $rel_type . ': ';
      }

      $options = [
        'query' => [
          'creator' => [$item->entity?->label() ?? (string) $item->target_id],
        ],
        'attributes' => [
          'rel' => 'nofollow',
        ],
      ];

      $url = Url::fromUri('internal:/browse', $options);
      $elements[$delta]['#url'] = $url;
    }

    return $elements;
  }

}
