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
    return $this->resolveMany([$node])[(int) $node->id()] ?? [];
  }

  /**
   * Resolves PDF downloads for multiple nodes with a single media query.
   *
   * @param \Drupal\node\NodeInterface[] $nodes
   *   Nodes whose downloads should be listed.
   *
   * @return array<int, array>
   *   Downloads keyed by node ID.
   */
  public function resolveMany(array $nodes): array {
    $uses = [
      'http://pcdm.org/use#OriginalFile' => 'Download original PDF',
      'http://pcdm.org/use#ServiceFile' => 'Download generated page PDF',
      'http://pcdm.org/use#PreservationMasterFile' => 'Download preservation PDF',
    ];
    $allowed = [];
    foreach ($nodes as $node) {
      if ($this->access->canReadFiles($node)) {
        $allowed[(int) $node->id()] = TRUE;
      }
    }
    if (!$allowed) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('media');
    $ids = $storage->getQuery()->accessCheck(TRUE)->condition('status', 1)
      ->condition('field_media_of', array_keys($allowed), 'IN')
      ->condition('field_media_use.entity:taxonomy_term.field_external_uri.uri', array_keys($uses), 'IN')
      ->sort('mid')->execute();
    $grouped = [];
    foreach ($storage->loadMultiple($ids) as $media) {
      if (!$media instanceof MediaInterface || !$this->access->canView($media)) {
        continue;
      }
      $file = $this->getFile($media);
      if (!$file || $file->getMimeType() !== 'application/pdf') {
        continue;
      }
      foreach ($media->get('field_media_use')->referencedEntities() as $use) {
        $this->access->track($use);
        $uri = $use->hasField('field_external_uri') ? $use->get('field_external_uri')->uri : '';
        if (!isset($uses[$uri])) {
          continue;
        }
        foreach ($media->get('field_media_of') as $parent) {
          $nid = (int) $parent->target_id;
          if (isset($allowed[$nid])) {
            $grouped[$nid][$uri][] = [
              'label' => $uses[$uri],
              'url' => $file->createFileUrl(FALSE),
              'mid' => (int) $media->id(),
              'fid' => (int) $file->id(),
            ];
          }
        }
      }
    }
    $downloads = [];
    foreach ($grouped as $nid => $by_use) {
      $seen = [];
      foreach ($uses as $uri => $_label) {
        foreach ($by_use[$uri] ?? [] as $download) {
          if (!isset($seen[$download['fid']])) {
            $downloads[$nid][] = $download;
            $seen[$download['fid']] = TRUE;
          }
        }
      }
    }
    return $downloads;
  }

}
