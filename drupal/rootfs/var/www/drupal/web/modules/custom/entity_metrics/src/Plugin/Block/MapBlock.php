<?php

namespace Drupal\entity_metrics\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a map block.
 *
 * @Block(
 *   id="entity_metrics_map",
 *   admin_label = @Translation("Metrics Map block")
 * )
 */
class MapBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructor for Mirador Block.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the formatter.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match interface.
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
    $build['header'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'id' => ['map-header'],
      ],
      '#prefix' => '<h2>Collection Views</h2>',
    ];
    $build['map'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'id' => ['map'],
      ],
    ];
    $build['#attached']['library'][] = 'entity_metrics/map';

    $results = \Drupal::database()->query("SELECT d.entity_id, d.timestamp, latitude, longitude, city, region, country
      FROM entity_metrics_data d
      INNER JOIN entity_metrics_regions r ON r.id = d.region_id
      INNER JOIN node__field_member_of m ON m.entity_id = d.entity_id
      WHERE d.entity_type = 'node' AND field_member_of_target_id = :id
      GROUP BY FROM_UNIXTIME(d.timestamp, 'YYYMMMDD'), entity_id, city ", [
        ':id' => $this->routeMatch->getParameter('node')->id(),
      ]);

    foreach ($results as $result) {
      $node = Node::load($result->entity_id);
      if ($node->access()) {
        $build['#attached']['drupalSettings']['entityMetrics'][] = [
          'label' => $node->label(),
          'link' => $node->toUrl()->toString(),
          'date' => date('m/d/Y', $result->timestamp),
          'latitude' => $result->latitude,
          'longitude' => $result->longitude,
          'city' => $result->city,
          'region' => $result->region,
          'country' => $result->country,
        ];
      }
    }

    return $build;
  }

}
