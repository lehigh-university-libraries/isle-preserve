<?php

declare(strict_types=1);

namespace Drupal\islandora_rag;

/**
 * A single retrievable chunk of a node's content.
 */
final class Chunk {

  public function __construct(
    public readonly string $type,
    public readonly int $index,
    public readonly string $text,
    public readonly string $embedText,
    public readonly ?int $mediaId = NULL,
    public readonly ?int $pageNumber = NULL,
  ) {}

}
