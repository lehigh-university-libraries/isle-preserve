<?php

declare(strict_types=1);

namespace Drupal\islandora_rag;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Gathers the text sources of a node for chunking.
 *
 * A "source" is ['type' => string, 'text' => string, 'media_id' => ?int].
 * Phase 1: metadata + OCR (Extracted Text). Transcripts/captions later.
 */
final class ContentGatherer {

  /**
   * PCDM use URI for extracted OCR text.
   */
  private const OCR_USE_URI = 'http://pcdm.org/use#ExtractedText';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Gather text sources for a node.
   *
   * @return array<int, array{type: string, text: string, media_id: ?int}>
   *   Ordered text sources.
   */
  public function gather(NodeInterface $node): array {
    $sources = [];

    $metadata = $this->metadataText($node);
    if ($metadata !== '') {
      $sources[] = ['type' => 'metadata', 'text' => $metadata, 'media_id' => NULL];
    }

    foreach ($this->ocrSources($node) as $source) {
      $sources[] = $source;
    }

    return $sources;
  }

  /**
   * Build a short metadata context header used to prefix embed text.
   */
  public function metadataHeader(NodeInterface $node): string {
    $lines = ['Title: ' . $node->label()];
    if ($node->hasField('field_edtf_date_issued') && !$node->get('field_edtf_date_issued')->isEmpty()) {
      $lines[] = 'Date: ' . $node->get('field_edtf_date_issued')->value;
    }
    return implode("\n", $lines);
  }

  /**
   * Assemble the metadata source text (title + description).
   */
  private function metadataText(NodeInterface $node): string {
    $parts = [$node->label()];
    foreach (['field_description', 'field_abstract'] as $field) {
      if ($node->hasField($field) && !$node->get($field)->isEmpty()) {
        $parts[] = strip_tags((string) $node->get($field)->value);
      }
    }
    return trim(implode("\n\n", array_filter($parts)));
  }

  /**
   * Find OCR (Extracted Text) sources attached to the node.
   *
   * @return array<int, array{type: string, text: string, media_id: ?int}>
   *   OCR sources, one per readable media.
   */
  private function ocrSources(NodeInterface $node): array {
    $sources = [];
    $anonymous = new AnonymousUserSession();
    $mediaStorage = $this->entityTypeManager->getStorage('media');
    $fileStorage = $this->entityTypeManager->getStorage('file');

    $ids = $mediaStorage->getQuery()
      ->condition('field_media_of', $node->id())
      ->condition('field_media_use.entity:taxonomy_term.field_external_uri.uri', self::OCR_USE_URI)
      ->accessCheck(FALSE)
      ->execute();

    foreach ($mediaStorage->loadMultiple($ids) as $media) {
      if (!$media instanceof MediaInterface || !$media->access('view', $anonymous, FALSE)) {
        continue;
      }
      $fid = $media->getSource()->getSourceFieldValue($media);
      $file = $fid ? $fileStorage->load($fid) : NULL;
      if (!$file instanceof FileInterface || !$file->access('view', $anonymous, FALSE)) {
        continue;
      }
      $text = @file_get_contents($file->getFileUri());
      if ($text === FALSE || trim($text) === '') {
        continue;
      }
      $sources[] = [
        'type' => 'ocr',
        'text' => $this->normalize($text),
        'media_id' => (int) $media->id(),
      ];
    }

    return $sources;
  }

  /**
   * Normalize whitespace in extracted text.
   */
  private function normalize(string $text): string {
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
  }

}
