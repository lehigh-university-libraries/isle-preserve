<?php

namespace Drupal\islandora_scribe\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\islandora_scribe\Integration;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Applies published Scribe revisions.
 *
 * @QueueWorker(
 *   id = "islandora_scribe_publication",
 *   title = @Translation("Scribe publication updates"),
 *   cron = {"time" = 60}
 * )
 */
final class Publication extends QueueWorkerBase implements ContainerFactoryPluginInterface {
  public function __construct(array $configuration, $plugin_id, $plugin_definition, private Integration $integration) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('islandora_scribe.integration'));
  }

  public function processItem($data) {
    $this->integration->apply($data['event_id']);
  }
}
