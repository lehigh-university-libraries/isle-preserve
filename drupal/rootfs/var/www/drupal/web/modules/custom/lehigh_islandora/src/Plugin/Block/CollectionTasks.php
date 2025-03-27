<?php

namespace Drupal\lehigh_islandora\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Menu\LocalTaskManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Element;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a node history block.
 *
 * @Block(
 *   id="collection_tasks",
 *   admin_label = @Translation("Collection Tasks block")
 * )
 */
class CollectionTasks extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The local task manager.
   *
   * @var \Drupal\Core\Menu\LocalTaskManagerInterface
   */
  protected $localTaskManager;

  /**
   * Creates a CollectionTasks instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Menu\LocalTaskManagerInterface $local_task_manager
   *   The local task manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LocalTaskManagerInterface $local_task_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->localTaskManager = $local_task_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
          $configuration,
          $plugin_id,
          $plugin_definition,
          $container->get('plugin.manager.menu.local_task')
      );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = $this->configuration;
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheableDependency($this->localTaskManager);
    $tabs = [
      '#theme' => 'menu_local_tasks',
    ];

    $links = $this->localTaskManager->getLocalTasks('entity.node.canonical', 0);
    $cacheability = $cacheability->merge($links['cacheability']);
    $tabs['#primary'] = count(Element::getVisibleChildren($links['tabs'])) > 1 ? $links['tabs'] : [];
    $build = [];
    $cacheability->applyTo($build);
    if (empty($tabs['#primary'])) {
      return $build;
    }

    $build['#attributes']['class'][] = 'block-local-tasks-block';

    return $build + $tabs;
  }

}
