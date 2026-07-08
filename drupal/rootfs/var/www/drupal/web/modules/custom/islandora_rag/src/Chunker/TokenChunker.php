<?php

declare(strict_types=1);

namespace Drupal\islandora_rag\Chunker;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Whitespace/word-approximated token chunker with overlap.
 *
 * Tokens are approximated as whitespace-delimited words. Good enough for
 * chunk sizing; swap for a real tokenizer later if needed.
 */
final class TokenChunker implements ChunkerInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function chunk(string $text): array {
    $text = trim($text);
    if ($text === '') {
      return [];
    }

    $chunk = $this->configFactory->get('islandora_rag.settings')->get('chunk') ?? [];
    $target = max(1, (int) ($chunk['target_tokens'] ?? 500));
    $overlap = max(0, (int) ($chunk['overlap_tokens'] ?? 100));
    $min = max(0, (int) ($chunk['min_tokens'] ?? 80));
    $max = max(1, (int) ($chunk['max_tokens'] ?? $target));
    $target = min($target, $max);
    if ($overlap >= $target) {
      $overlap = (int) floor($target / 5);
    }

    $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $total = count($words);
    if ($total === 0) {
      return [];
    }

    // Short enough to be a single chunk.
    if ($total <= $target) {
      return [implode(' ', $words)];
    }

    $chunks = [];
    $step = $target - $overlap;
    for ($start = 0; $start < $total; $start += $step) {
      $slice = array_slice($words, $start, $target);
      // Drop a trailing sliver that is mostly overlap.
      if ($start > 0 && count($slice) < $min) {
        break;
      }
      $chunks[] = implode(' ', $slice);
      if ($start + $target >= $total) {
        break;
      }
    }
    return $chunks;
  }

}
