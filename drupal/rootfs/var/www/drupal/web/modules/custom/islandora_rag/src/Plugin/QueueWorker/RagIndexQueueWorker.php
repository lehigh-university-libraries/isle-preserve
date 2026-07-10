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
        $this->status(sprintf('RAG delete node %d', $nid));
        $this->indexer->deleteNode($nid);
        $this->status(sprintf('RAG deleted node %d', $nid));
        return;
      }
      $this->status(sprintf('RAG indexing node %d', $nid));
      $chunks = $this->indexer->indexNode($nid);
      $this->status(sprintf('RAG indexed node %d (%d chunks)', $nid, $chunks));
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

  /**
   * Emit queue progress to logs and CLI queue runners.
   */
  private function status(string $message): void {
    $this->logger->notice($message);
    if (PHP_SAPI === 'cli' && defined('STDERR')) {
      fwrite(STDERR, $message . PHP_EOL);
    }
  }

}
