<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\JournalBrowser;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\views\ViewExecutableFactory;

/**
 * Locates browser items and current IIIF canvases from Drupal relationships.
 */
final class BrowserTargetResolver {

  /**
   * Constructs the resolver.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ViewExecutableFactory $viewFactory,
    protected BrowserAccess $access,
  ) {}

  /**
   * Reads canvas order from the same View that produces the current manifest.
   */
  public function getCanvases(NodeInterface $issue): array {
    if (!$this->access->canReadFiles($issue)) {
      return [];
    }
    $definition = $this->entityTypeManager->getStorage('view')->load('iiif_manifest');
    if (!$definition) {
      return [];
    }
    $this->access->track($definition);
    $view = $this->viewFactory->get($definition);
    $view->setDisplay('rest_export_1');
    $view->setArguments([(int) $issue->id()]);
    $view->execute();
    $canvases = [];
    $position = 0;
    foreach ($view->result as $row) {
      $media = $row->_entity ?? NULL;
      if (!$media instanceof MediaInterface || !$this->access->canView($media)) {
        continue;
      }
      $parents = $media->get('field_media_of')->referencedEntities();
      foreach ($parents as $page) {
        if (!$page instanceof NodeInterface || !$this->access->canReadFiles($page)) {
          continue;
        }
        ++$position;
        $canvases[(int) $page->id()] ??= [
          'canvas' => Url::fromUserInput('/node/' . $issue->id() . '/canvas/' . $media->id(), ['absolute' => TRUE])->toString(),
          'page_number' => $position,
        ];
        break;
      }
    }
    $view->destroy();
    return $canvases;
  }

  /**
   * Finds the nearest ancestor among the source's selectable issues.
   */
  public function getIssue(NodeInterface $node, array $issues, array $seen = []): ?NodeInterface {
    $id = (int) $node->id();
    if (isset($seen[$id]) || !$this->access->canView($node)) {
      return NULL;
    }
    if (isset($issues[$id])) {
      return $issues[$id];
    }
    $seen[$id] = TRUE;
    if ($node->hasField('field_member_of')) {
      foreach ($node->get('field_member_of')->referencedEntities() as $parent) {
        if ($parent instanceof NodeInterface && ($issue = $this->getIssue($parent, $issues, $seen))) {
          return $issue;
        }
      }
    }
    return NULL;
  }

}
