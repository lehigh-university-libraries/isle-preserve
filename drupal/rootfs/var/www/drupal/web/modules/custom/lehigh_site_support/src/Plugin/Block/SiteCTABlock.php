<?php

namespace Drupal\lehigh_site_support\Plugin\Block;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an example block.
 *
 * @Block(
 *   id = "lehigh_site_cta_block",
 *   admin_label = @Translation("Lehigh Site CTA Block"),
 *   category = @Translation("Lehigh")
 * )
 */
class SiteCTABlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The module config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Constructs a SiteCTABlock instance.
   *
   * @param array $configuration
   *   A configuration array containing plugin instance information.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->config = $config_factory->get('lehigh_site_support.settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $about_text_value = $this->config->get('about_text');

    $build['content'] = [
      '#theme' => 'lehigh_site_cta_block',
      '#about_text' => Xss::filter($about_text_value['value']),
    ];

    $build['#attributes']['class'][] = 'col-sm-12 col-md-10';

    return $build;
  }

}
