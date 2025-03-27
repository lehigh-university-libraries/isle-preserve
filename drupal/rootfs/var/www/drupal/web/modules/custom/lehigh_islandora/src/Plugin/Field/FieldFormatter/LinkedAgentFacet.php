<?php

namespace Drupal\lehigh_islandora\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceLabelFormatter;
use Drupal\Core\Url;

/**
 * Plugin implementation of the 'LinkedAgentFacet'.
 *
 * @FieldFormatter(
 *   id = "typed_relation_facet",
 *   label = @Translation("Typed Relation Facet Link"),
 *   field_types = {
 *     "typed_relation"
 *   }
 * )
 */
class LinkedAgentFacet extends EntityReferenceLabelFormatter {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = parent::viewElements($items, $langcode);

    foreach ($items as $delta => $item) {

      $rel_types = $item->getRelTypes();
      $rel_type = $rel_types[$item->rel_type] ?? $item->rel_type;
      if (!empty($rel_type)) {
        $elements[$delta]['#prefix'] = $rel_type . ': ';
      }

      $options = [
        'query' => [
          'f[0]' => 'contributor:' . $item->target_id,
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
