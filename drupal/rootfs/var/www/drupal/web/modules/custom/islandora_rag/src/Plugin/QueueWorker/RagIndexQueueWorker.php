<?php

declare(strict_types=1);

namespace Drupal\islandora_rag\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\islandora_rag\Exception\TransientDependencyException;
use Drupal\islandora_rag\Indexer\SemanticIndexer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes deferred semantic (re)index / delete jobs.
 */
#[QueueWorker(
  id: 'islandora_rag_index',
  title: new TranslatableMarkup('Islandora RAG semantic indexer'),
  cron: ['time' => 60]
)]
final class RagIndexQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly SemanticIndexer $indexer,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('islandora_rag.indexer'),
      $container->get('logger.channel.islandora_rag'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $nid = (int) ($data['nid'] ?? 0);
    if ($nid <= 0) {
      return;
    }
    try {
      if (($data['op'] ?? 'index') === 'delete') {
        $this->indexer->deleteNode($nid);
        return;
      }
      $this->indexer->indexNode($nid);
    }
    catch (\RuntimeException $e) {
      if ($e instanceof TransientDependencyException) {
        throw new SuspendQueueException('Islandora RAG dependency unavailable: ' . $e->getMessage(), 0, $e);
      }
      $this->logger->error('Dropping Islandora RAG queue item for node @nid after non-transient failure: @msg', [
        '@nid' => $nid,
        '@msg' => $e->getMessage(),
      ]);
    }
  }

}
