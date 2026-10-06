<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\JournalBrowser;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

/**
 * Resolves original and generated PDFs using media-use URIs, not term IDs.
 */
final class DownloadResolver {

  /**
   * Constructs the resolver.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected BrowserAccess $access,
  ) {}

  /**
   * Gets the managed file backing a media entity.
   */
  public function getFile(MediaInterface $media): ?FileInterface {
    $field = $media->getSource()->getConfiguration()['source_field'] ?? '';
    if (!$field || !$media->hasField($field) || $media->get($field)->isEmpty()) {
      return NULL;
    }
    $file = $media->get($field)->entity;
    if ($file instanceof FileInterface) {
      $this->access->track($file);
      return $file;
    }
    return NULL;
  }

  /**
   * Lists accessible media attached to the node, optionally by use URI.
   */
  public function getMedia(NodeInterface $node, ?string $use = NULL): array {
    if (!$this->access->canReadFiles($node)) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('media');
    $query = $storage->getQuery()->accessCheck(TRUE)
      ->condition('status', 1)
      ->condition('field_media_of', $node->id())
      ->sort('mid');
    if ($use !== NULL) {
      $query->condition('field_media_use.entity:taxonomy_term.field_external_uri.uri', $use);
    }
    return array_filter($storage->loadMultiple($query->execute()), fn(MediaInterface $media): bool => $this->access->canView($media));
  }

  /**
   * Returns all PDFs, originals first; never hides generated alternatives.
   */
  public function resolve(NodeInterface $node): array {
    $downloads = [];
    $seen = [];
    foreach ([
      'http://pcdm.org/use#OriginalFile' => 'Download original PDF',
      'http://pcdm.org/use#ServiceFile' => 'Download generated page PDF',
      'http://pcdm.org/use#PreservationMasterFile' => 'Download preservation PDF',
    ] as $use => $label) {
      foreach ($this->getMedia($node, $use) as $media) {
        $file = $this->getFile($media);
        if (!$file || $file->getMimeType() !== 'application/pdf' || isset($seen[$file->id()])) {
          continue;
        }
        $seen[$file->id()] = TRUE;
        $downloads[] = [
          'label' => $label,
          'url' => $file->createFileUrl(FALSE),
          'mid' => (int) $media->id(),
          'fid' => (int) $file->id(),
        ];
      }
    }
    return $downloads;
  }

}
