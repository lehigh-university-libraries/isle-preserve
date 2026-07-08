<?php

declare(strict_types=1);

namespace Drupal\islandora_rag\Embedding;

/**
 * Client for the external embedding service.
 */
interface EmbeddingClientInterface {

  /**
   * Embed document passages.
   *
   * @param string[] $texts
   *   Passages to embed (document-side instruction applied by the service).
   *
   * @return array<int, array<int, float>>
   *   One vector per input, in order.
   *
   * @throws \RuntimeException
   *   If the service is unreachable or returns an unexpected payload.
   */
  public function embedDocuments(array $texts): array;

}
