<?php

declare(strict_types=1);

namespace Drupal\islandora_rag\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\islandora_rag\Indexer\RagSolrClient;
use Drupal\islandora_rag\Indexer\SemanticIndexer;
use Drupal\node\NodeInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for Islandora RAG semantic indexing.
 */
final class IslandoraRagCommands extends DrushCommands {

  public function __construct(
    private readonly SemanticIndexer $indexer,
    private readonly RagSolrClient $solr,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly QueueFactory $queueFactory,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('islandora_rag.indexer'),
      $container->get('islandora_rag.solr_client'),
      $container->get('entity_type.manager'),
      $container->get('queue'),
    );
  }

  /**
   * Index a single node's semantic chunks synchronously (QA/debug).
   */
  #[CLI\Command(name: 'islandora-rag:index-node', aliases: ['irag:node'])]
  #[CLI\Argument(name: 'nid', description: 'The node ID to index.')]
  public function indexNode(int $nid): void {
    $this->indexer->indexNode($nid);
    $this->logger()->success(dt('Indexed node @nid.', ['@nid' => $nid]));
  }

  /**
   * Queue all published islandora_object nodes for (re)indexing.
   */
  #[CLI\Command(name: 'islandora-rag:reindex', aliases: ['irag:reindex'])]
  public function reindex(): void {
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'islandora_object')
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->execute();

    $queue = $this->queueFactory->get('islandora_rag_index');
    foreach ($ids as $nid) {
      $queue->createItem(['op' => 'index', 'nid' => (int) $nid]);
    }
    $this->logger()->success(dt('Queued @count nodes for semantic reindex.', ['@count' => count($ids)]));
  }

  /**
   * Delete vector chunks for nodes that are gone or no longer indexable.
   */
  #[CLI\Command(name: 'islandora-rag:prune', aliases: ['irag:prune'])]
  public function prune(): void {
    $storage = $this->entityTypeManager->getStorage('node');
    $deleted = 0;
    foreach ($this->solr->indexedNodeIds() as $nid) {
      $node = $storage->load($nid);
      if (!$node instanceof NodeInterface || !$this->indexer->shouldIndex($node)) {
        $this->solr->deleteByNode($nid);
        $deleted++;
      }
    }
    $this->logger()->success(dt('Deleted RAG chunks for @count orphaned or non-indexable nodes.', ['@count' => $deleted]));
  }

}
