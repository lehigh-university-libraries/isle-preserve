<?php

namespace Drupal\lehigh_islandora\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Display altemetrics.
 *
 * @Block(
 *   id = "altmetrics",
 *   admin_label = @Translation("Altmetrics"),
 *   category = "Lehigh Islandora"
 * )
 */
class Altmetrics extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The route match service.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs an Altmetrics block.
   *
   * @param array $configuration
   *   A configuration array containing plugin instance information.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteMatchInterface $route_match) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [];
    $node = $this->routeMatch->getParameter('node');
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
    if ($node = $this->routeMatch->getParameter('node')) {
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
