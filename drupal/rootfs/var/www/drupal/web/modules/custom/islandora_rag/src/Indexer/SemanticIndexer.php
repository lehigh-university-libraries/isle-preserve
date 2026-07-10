<?php

declare(strict_types=1);

namespace Drupal\islandora_rag\Indexer;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\islandora_rag\Chunk;
use Drupal\islandora_rag\Chunker\ChunkerInterface;
use Drupal\islandora_rag\ContentGatherer;
use Drupal\islandora_rag\Embedding\EmbeddingClientInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates chunk-level semantic indexing of a node.
 */
final class SemanticIndexer {

  private const VECTOR_DIMENSION = 1024;
  private const DEFAULT_EMBEDDING_BATCH_SIZE = 8;
  private const DEFAULT_MAX_CHUNKS_PER_NODE = 2000;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ContentGatherer $gatherer,
    private readonly ChunkerInterface $chunker,
    private readonly EmbeddingClientInterface $embedder,
    private readonly RagSolrClient $solr,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Reindexes a node's chunks, or removes them if it should not be indexed.
   */
  public function indexNode(int $nid): int {
    $this->assertVectorDimension();

    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || !$this->shouldIndex($node)) {
      $this->solr->deleteByNode($nid);
      return 0;
    }

    $chunks = $this->buildChunks($node);
    if ($chunks === []) {
      $this->solr->deleteByNode($nid);
      return 0;
    }

    $vectors = $this->embedChunks($chunks);

    $config = $this->configFactory->get('islandora_rag.settings');
    $model = (string) $config->get('embedding_model');
    $dimension = (int) $config->get('embedding_dimension');
    $shared = $this->sharedFields($node);

    $docs = [];
    foreach ($chunks as $i => $chunk) {
      $docs[] = $shared + [
        'id' => sprintf('node-%d:%s:%06d', $nid, $chunk->type, $chunk->index),
        'chunk_type' => $chunk->type,
        'chunk_index' => $chunk->index,
        'page_number' => $chunk->pageNumber,
        'media_id' => $chunk->mediaId !== NULL ? (string) $chunk->mediaId : NULL,
        'text' => $chunk->text,
        'embedding' => $vectors[$i],
        'embedding_model' => $model,
        'embedding_dimension' => $dimension,
        'source_hash' => hash('sha256', $chunk->embedText),
      ];
    }

    // Replace: clear prior chunks then write the current set.
    $this->solr->deleteByNode($nid);
    $this->solr->addDocuments($docs);
    $this->logger->info('Indexed @count chunks for node @nid.', ['@count' => count($docs), '@nid' => $nid]);
    return count($docs);
  }

  /**
   * Remove a node's chunks from the vector core.
   */
  public function deleteNode(int $nid): void {
    $this->solr->deleteByNode($nid);
  }

  /**
   * Build chunks from all of a node's text sources.
   *
   * @return \Drupal\islandora_rag\Chunk[]
   *   Ordered chunks (metadata always index 0; OCR reindexed per source).
   */
  private function buildChunks(NodeInterface $node): array {
    $header = $this->gatherer->metadataHeader($node);
    $chunks = [];
    foreach ($this->gatherer->gather($node) as $source) {
      if ($source['type'] === 'metadata') {
        // One chunk; text already carries title/description.
        $chunks[] = new Chunk('metadata', 0, $source['text'], $source['text']);
        continue;
      }
      foreach ($this->chunker->chunk($source['text']) as $index => $text) {
        $chunks[] = new Chunk(
          type: $source['type'],
          index: $index,
          text: $text,
          // Prepend metadata context so passages embed with their object.
          embedText: $header . "\n\n" . $text,
          mediaId: $source['media_id'],
        );
      }
    }
    $maxChunks = (int) ($this->configFactory->get('islandora_rag.settings')->get('max_chunks_per_node') ?: self::DEFAULT_MAX_CHUNKS_PER_NODE);
    if ($maxChunks > 0 && count($chunks) > $maxChunks) {
      $this->logger->warning('Node @nid produced @count RAG chunks; indexing first @max chunks.', [
        '@nid' => $node->id(),
        '@count' => count($chunks),
        '@max' => $maxChunks,
      ]);
      $chunks = array_slice($chunks, 0, $maxChunks);
    }
    return $chunks;
  }

  /**
   * Embed chunks in bounded batches.
   *
   * @param \Drupal\islandora_rag\Chunk[] $chunks
   *   Chunks to embed.
   *
   * @return array<int, array<int, float>>
   *   Vectors in chunk order.
   */
  private function embedChunks(array $chunks): array {
    $vectors = [];
    foreach (array_chunk($chunks, $this->embeddingBatchSize()) as $batch) {
      $texts = array_map(static fn(Chunk $c): string => $c->embedText, $batch);
      array_push($vectors, ...$this->embedder->embedDocuments($texts));
    }
    return $vectors;
  }

  /**
   * Number of chunks to send to the embedding service per request.
   */
  private function embeddingBatchSize(): int {
    $batch_size = (int) (getenv('EMBEDDING_BATCH_SIZE') ?: self::DEFAULT_EMBEDDING_BATCH_SIZE);
    return max(1, $batch_size);
  }

  /**
   * Fields shared by every chunk doc of a node.
   *
   * @return array<string, mixed>
   *   Shared Solr field values.
   */
  private function sharedFields(NodeInterface $node): array {
    $collections = [];
    if ($node->hasField('field_member_of')) {
      foreach ($node->get('field_member_of') as $item) {
        $collections[] = (string) $item->target_id;
      }
    }
    $model = NULL;
    if ($node->hasField('field_model') && !$node->get('field_model')->isEmpty()) {
      $model = (string) $node->get('field_model')->target_id;
    }

    return [
      'node_id' => (string) $node->id(),
      'title' => $node->label(),
      'collection_ids' => $collections,
      'content_model' => $model !== NULL ? [$model] : [],
      'published' => TRUE,
      'access' => ['public'],
      'object_url' => $node->toUrl('canonical', ['absolute' => FALSE])->toString(),
    ];
  }

  /**
   * Whether a node should have semantic chunks indexed.
   */
  public function shouldIndex(NodeInterface $node): bool {
    $config = $this->configFactory->get('islandora_rag.settings');
    if (!$config->get('enabled')) {
      return FALSE;
    }
    if ($node->bundle() !== 'islandora_object' || !$node->isPublished()) {
      return FALSE;
    }
    // Anonymous-only retrieval: only index what anonymous users may view.
    if (!$node->access('view', new AnonymousUserSession(), FALSE)) {
      return FALSE;
    }
    if (!$this->isUnconditionallyPublic($node)) {
      return FALSE;
    }
    // Optional collection allow-list.
    $allowed = array_filter((array) $config->get('indexed_collections'));
    if ($allowed !== [] && $node->hasField('field_member_of')) {
      $members = array_map(
        static fn($item) => (int) $item->target_id,
        iterator_to_array($node->get('field_member_of')),
      );
      if (array_intersect($allowed, $members) === []) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Only index content that is public without request-dependent conditions.
   */
  private function isUnconditionallyPublic(NodeInterface $node): bool {
    // Embargoed records may expose metadata while hiding files/passages.
    // The RAG index contains OCR text, so exclude anything embargoed.
    if ($node->hasField('field_edtf_date_embargo') && !$node->get('field_edtf_date_embargo')->isEmpty()) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Fail fast if Drupal config drifts from the Solr vector field dimension.
   */
  private function assertVectorDimension(): void {
    $dimension = (int) $this->configFactory->get('islandora_rag.settings')->get('embedding_dimension');
    if ($dimension !== self::VECTOR_DIMENSION) {
      throw new \RuntimeException(sprintf(
        'islandora_rag.settings embedding_dimension (%d) must match the Solr DenseVectorField dimension (%d).',
        $dimension,
        self::VECTOR_DIMENSION,
      ));
    }
  }

}
