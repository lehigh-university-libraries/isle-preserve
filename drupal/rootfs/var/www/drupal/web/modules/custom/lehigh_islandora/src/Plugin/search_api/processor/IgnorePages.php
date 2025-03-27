<?php

namespace Drupal\lehigh_islandora\Plugin\search_api\processor;

use Drupal\search_api\IndexInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;

/**
 * Excludes page islandora models from node indexes.
 *
 * @SearchApiProcessor(
 *   id = "ignore_pages",
 *   label = @Translation("Ignore Pages"),
 *   description = @Translation("Exclude pages from being indexed."),
 *   stages = {
 *     "alter_items" = 0,
 *   },
 * )
 */
class IgnorePages extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public static function supportsIndex(IndexInterface $index) {
    foreach ($index->getDatasources() as $datasource) {
      if ($datasource->getEntityTypeId() === 'node') {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function alterIndexedItems(array &$items) {
    foreach ($items as $item_id => $item) {
      $node = $item->getOriginalObject()->getValue();
      if ($node->hasField('field_model')
            && !is_null($node->field_model->entity)
            && !empty($node->field_model->entity->field_external_uri->uri)
            && $node->field_model->entity->field_external_uri->uri === 'http://id.loc.gov/ontologies/bibframe/part'
        ) {
        unset($items[$item_id]);
      }
    }
  }

}
