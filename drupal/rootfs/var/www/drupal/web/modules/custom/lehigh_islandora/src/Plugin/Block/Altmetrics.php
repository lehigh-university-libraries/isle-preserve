<?php

namespace Drupal\lehigh_islandora\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;

/**
 * Display altemetrics.
 *
 * @Block(
 *   id = "altmetrics",
 *   admin_label = @Translation("Altmetrics"),
 *   category = "Lehigh Islandora"
 * )
 */
class Altmetrics extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [];
    $node = \Drupal::routeMatch()->getParameter('node');
    if (!$node ||
      !$node->hasField('field_identifier') ||
      $node->field_identifier->isEmpty()) {
      return $build;
    }

    $doi = FALSE;
    foreach ($node->field_identifier as $identifier) {
      if ($identifier->attr0 === 'doi') {
        $doi = strip_tags($identifier->value);
        $doi = substr($doi, strpos('10.', $doi));
      }
    }

    if (!$doi) {
      return $build;
    }

    $build['altmetrics'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['row', 'd-flex', 'justify-content-center'],
      ],
      '#value' => '',
      'content' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'data-badge-popover' => 'left',
          'data-badge-type' => 'medium-donut',
          'data-doi' => $doi,
          'data-hide-no-mentions' => 'true',
          'class' => ['altmetric-embed'],
        ],
      ],
    ];
    $build['#attached']['library'][] = 'lehigh_islandora/altmetrics';

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    if ($node = \Drupal::routeMatch()->getParameter('node')) {
      return Cache::mergeTags(parent::getCacheTags(), ['node:' . $node->id()]);
    }
    else {
      return parent::getCacheTags();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), ['route']);
  }

}
