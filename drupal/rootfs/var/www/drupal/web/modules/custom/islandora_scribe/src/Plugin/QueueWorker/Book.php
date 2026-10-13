<?php

namespace Drupal\islandora_scribe\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\islandora_scribe\Integration;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Imports a public book into Scribe.
 *
 * @QueueWorker(
 *   id = "islandora_scribe_book",
 *   title = @Translation("Scribe book transcription"),
 *   cron = {"time" = 60}
 * )
 */
final class Book extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, private Integration $integration) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('islandora_scribe.integration'));
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $this->integration->processBookOperation($data);
  }

}
