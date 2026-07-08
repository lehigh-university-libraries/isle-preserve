<?php

declare(strict_types=1);

namespace Drupal\islandora_rag\Chunker;

/**
 * Splits a block of text into overlapping chunks suitable for embedding.
 */
interface ChunkerInterface {

  /**
   * Split text into chunks.
   *
   * @param string $text
   *   Normalized plain text.
   *
   * @return string[]
   *   Ordered chunk strings. Empty if the text has no usable content.
   */
  public function chunk(string $text): array;

}
