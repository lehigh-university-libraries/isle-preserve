<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\JournalBrowser;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\node\NodeInterface;

/**
 * Shares browser access decisions and their aggregate cache dependencies.
 */
final class BrowserAccess {

  /**
   * Aggregate access and entity dependencies for the current response.
   */
  protected CacheableMetadata $cacheability;

  /**
   * Initializes request-local cache metadata.
   */
  public function __construct() {
    $this->reset();
  }

  /**
   * Starts one browser response's dependency collection.
   */
  public function reset(): void {
    $this->cacheability = (new CacheableMetadata())
      ->setCacheMaxAge(300)
      ->addCacheTags(['node_list', 'media_list', 'file_list', 'taxonomy_term_list'])
      ->addCacheContexts([
        'url.query_args', 'url.site', 'user', 'user.permissions',
        'user.node_grants:view', 'on-campus',
      ]);
  }

  /**
   * Records entities and other cacheable dependencies.
   */
  public function track(mixed $dependency): void {
    $this->cacheability->addCacheableDependency($dependency);
  }

  /**
   * Checks entity access without discarding its cacheability.
   */
  public function canView(EntityInterface $entity): bool {
    $this->track($entity);
    $access = $entity->access('view', NULL, TRUE);
    $this->track($access);
    return $access->isAllowed() && (!$entity instanceof NodeInterface || $entity->isPublished());
  }

  /**
   * Restricts files, transcripts, thumbnails, and viewers through ancestry.
   */
  public function canReadFiles(NodeInterface $node, array $seen = []): bool {
    $nid = (int) $node->id();
    if (isset($seen[$nid])) {
      return TRUE;
    }
    $seen[$nid] = TRUE;
    if (!$this->canView($node)) {
      return FALSE;
    }
    if (function_exists('lehigh_embargo_node_is_embargoed') && ($until = lehigh_embargo_node_is_embargoed($node))) {
      $this->cacheability->setCacheMaxAge(min($this->cacheability->getCacheMaxAge(), max(0, $until->getTimestamp() - time())));
      return FALSE;
    }
    if ($node->hasField('field_local_restriction') && $node->get('field_local_restriction')->value && !lehigh_islandora_on_campus()) {
      return FALSE;
    }
    if ($node->hasField('field_member_of')) {
      foreach ($node->get('field_member_of')->referencedEntities() as $parent) {
        if ($parent instanceof NodeInterface && !$this->canReadFiles($parent, $seen)) {
          return FALSE;
        }
      }
    }
    return TRUE;
  }

  /**
   * Returns cache metadata for the assembled response.
   */
  public function getCacheability(): CacheableMetadata {
    return clone $this->cacheability;
  }

}
