<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\JournalBrowser;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

/**
 * Reads Islandora source-browser hierarchy data from Drupal entities.
 */
final class JournalBrowserRepository {

  /**
   * Loaded descendants keyed by source node ID.
   *
   * @var array<int, array<int, \Drupal\node\NodeInterface>>
   */
  protected array $descendants = [];

  /**
   * Constructs the repository.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
  ) {}

  /**
   * Returns source collections flagged for the browser.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Source nodes keyed by node ID.
   */
  public function getSources(NodeInterface $current): array {
    $sources = [(int) $current->id() => $current];
    $root = $this->entityTypeManager->getStorage('node')->load(1);
    if ($root instanceof NodeInterface) {
      $root_children = $this->loadNodesByIds($this->getDirectChildIds((int) $root->id()));
      if ($root_children) {
        $sources = $root_children;
        $sources[(int) $current->id()] = $current;
      }
    }

    uasort($sources, static fn(NodeInterface $a, NodeInterface $b): int => strcasecmp($a->label(), $b->label()));
    return $sources;
  }

  /**
   * Returns issue/document candidates for a source without loading all pages.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Issue/document nodes keyed by node ID.
   */
  public function getIssues(NodeInterface $source): array {
    $issue_models = [
      'Publication Issue',
      'Paged Content',
      'Compound Object',
    ];
    if (in_array($this->getModel($source), $issue_models, TRUE)) {
      return [(int) $source->id() => $source];
    }

    $candidate_ids = $this->getDescendantIds((int) $source->id(), 4);
    if (!$candidate_ids) {
      $candidate_ids = $this->getDirectChildIds((int) $source->id());
    }
    $issue_ids = $this->filterNodeIdsByModel($candidate_ids, $issue_models);
    if (!$issue_ids) {
      $issue_ids = $this->getDirectChildIds((int) $source->id());
    }

    return $this->loadNodesByIds($issue_ids);
  }

  /**
   * Returns entry and page nodes for one selected issue/document.
   *
   * @return array{entries: array<int, \Drupal\node\NodeInterface>, pages: array<int, \Drupal\node\NodeInterface>}
   *   Entries and pages keyed by node ID.
   */
  public function getIssueItems(NodeInterface $issue): array {
    $children = $this->loadNodesByIds($this->getDirectChildIds((int) $issue->id()));
    $entries = [];
    $pages = [];

    foreach ($children as $child) {
      if ($this->getModel($child) === 'Page') {
        $pages[(int) $child->id()] = $child;
        continue;
      }
      $entries[(int) $child->id()] = $child;
      $grandchildren = $this->loadNodesByIds($this->getDirectChildIds((int) $child->id()));
      foreach ($grandchildren as $grandchild) {
        if ($this->getModel($grandchild) === 'Page') {
          $pages[(int) $grandchild->id()] = $grandchild;
        }
      }
    }

    return [
      'entries' => $entries,
      'pages' => $pages,
    ];
  }

  /**
   * Gets source descendants by walking field_member_of relationships.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Descendants keyed by node ID.
   */
  public function getDescendants(NodeInterface $source, int $max_depth = 8): array {
    $source_id = (int) $source->id();
    if (isset($this->descendants[$source_id])) {
      return $this->descendants[$source_id];
    }

    $seen = [$source_id => TRUE];
    $frontier = [$source_id];
    $descendant_ids = [];
    $depth = 0;

    while ($frontier && $depth < $max_depth) {
      $children = $this->database->select('node__field_member_of', 'm')
        ->fields('m', ['entity_id'])
        ->condition('m.field_member_of_target_id', $frontier, 'IN')
        ->execute()
        ->fetchCol();

      $frontier = [];
      foreach ($children as $child_id) {
        $child_id = (int) $child_id;
        if (isset($seen[$child_id])) {
          continue;
        }
        $seen[$child_id] = TRUE;
        $descendant_ids[] = $child_id;
        $frontier[] = $child_id;
      }
      ++$depth;
    }

    if (!$descendant_ids) {
      return $this->descendants[$source_id] = [];
    }

    /** @var \Drupal\node\NodeInterface[] $nodes */
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($descendant_ids);
    $nodes = array_filter($nodes, static function (NodeInterface $node): bool {
      return $node->isPublished() && $node->access('view');
    });

    uasort($nodes, fn(NodeInterface $a, NodeInterface $b): int => $this->compareNodes($a, $b));
    return $this->descendants[$source_id] = $nodes;
  }

  /**
   * Returns direct published child node IDs for a parent.
   *
   * @return int[]
   *   Child node IDs.
   */
  protected function getDirectChildIds(int $parent_id): array {
    $query = $this->database->select('node__field_member_of', 'm');
    $query->innerJoin('node_field_data', 'n', 'n.nid = m.entity_id');
    $query->fields('m', ['entity_id']);
    $query->condition('m.field_member_of_target_id', $parent_id);
    $query->condition('n.status', 1);
    $query->orderBy('n.title');
    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * Returns descendant IDs without loading entities.
   *
   * @return int[]
   *   Descendant node IDs.
   */
  protected function getDescendantIds(int $source_id, int $max_depth = 4): array {
    $seen = [$source_id => TRUE];
    $frontier = [$source_id];
    $descendant_ids = [];
    $depth = 0;

    while ($frontier && $depth < $max_depth) {
      $query = $this->database->select('node__field_member_of', 'm');
      $query->innerJoin('node_field_data', 'n', 'n.nid = m.entity_id');
      $query->fields('m', ['entity_id']);
      $query->condition('m.field_member_of_target_id', $frontier, 'IN');
      $query->condition('n.status', 1);
      $children = $query->execute()->fetchCol();

      $frontier = [];
      foreach ($children as $child_id) {
        $child_id = (int) $child_id;
        if (isset($seen[$child_id])) {
          continue;
        }
        $seen[$child_id] = TRUE;
        $descendant_ids[] = $child_id;
        $frontier[] = $child_id;
      }
      ++$depth;
    }

    return $descendant_ids;
  }

  /**
   * Filters node IDs by Islandora model label.
   *
   * @param int[] $node_ids
   *   Node IDs.
   * @param string[] $models
   *   Model labels.
   *
   * @return int[]
   *   Matching node IDs.
   */
  protected function filterNodeIdsByModel(array $node_ids, array $models): array {
    if (!$node_ids) {
      return [];
    }

    $query = $this->database->select('node__field_model', 'm');
    $query->innerJoin('taxonomy_term_field_data', 't', 't.tid = m.field_model_target_id');
    $query->fields('m', ['entity_id']);
    $query->condition('m.entity_id', $node_ids, 'IN');
    $query->condition('t.name', $models, 'IN');
    return array_map('intval', $query->execute()->fetchCol());
  }

  /**
   * Loads viewable nodes by ID.
   *
   * @param int[] $node_ids
   *   Node IDs.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Nodes keyed by node ID.
   */
  protected function loadNodesByIds(array $node_ids): array {
    if (!$node_ids) {
      return [];
    }

    /** @var \Drupal\node\NodeInterface[] $nodes */
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($node_ids);
    $nodes = array_filter($nodes, static function (NodeInterface $node): bool {
      return $node->isPublished() && $node->access('view');
    });
    uasort($nodes, fn(NodeInterface $a, NodeInterface $b): int => $this->compareNodes($a, $b));
    return $nodes;
  }

  /**
   * Gets direct children for a parent node from a loaded node set.
   *
   * @param \Drupal\node\NodeInterface $parent
   *   Parent node.
   * @param \Drupal\node\NodeInterface[] $nodes
   *   Candidate nodes.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Direct children.
   */
  public function getChildren(NodeInterface $parent, array $nodes): array {
    $children = [];
    foreach ($nodes as $node) {
      if (!$node->hasField('field_member_of') || $node->get('field_member_of')->isEmpty()) {
        continue;
      }
      foreach ($node->get('field_member_of') as $item) {
        if ((int) $item->target_id === (int) $parent->id()) {
          $children[(int) $node->id()] = $node;
          break;
        }
      }
    }
    uasort($children, fn(NodeInterface $a, NodeInterface $b): int => $this->compareNodes($a, $b));
    return $children;
  }

  /**
   * Performs source-scoped full-text search.
   *
   * @return array<int, array<string, mixed>>
   *   Normalized search result rows.
   */
  public function searchWithinSource(NodeInterface $source, string $query, int $limit = 25): array {
    $query = trim($query);
    if ($query === '') {
      return [];
    }

    $results = $this->searchApiWithinSource($source, $query, $limit);
    if ($results) {
      return $results;
    }

    return $this->metadataSearchWithinSource($source, $query, $limit);
  }

  /**
   * Builds an alphabetical index for nodes.
   *
   * @param \Drupal\node\NodeInterface[] $nodes
   *   Nodes to index.
   *
   * @return array<string, array<int, array<string, string>>>
   *   Index entries grouped by first letter.
   */
  public function buildAlphabeticalIndex(array $nodes): array {
    $index = [];
    foreach ($nodes as $node) {
      $letter = strtoupper(substr($node->label(), 0, 1));
      if (!preg_match('/[A-Z]/', $letter)) {
        $letter = '#';
      }
      $index[$letter][] = [
        'title' => $node->label(),
        'model' => $this->getModel($node),
        'url' => $node->toUrl()->toString(),
      ];
    }

    ksort($index);
    foreach ($index as &$entries) {
      usort($entries, static fn(array $a, array $b): int => strnatcasecmp($a['title'], $b['title']));
    }
    return $index;
  }

  /**
   * Returns attached extracted-text media for the given nodes.
   *
   * @param \Drupal\node\NodeInterface[] $nodes
   *   Nodes to inspect.
   *
   * @return array<int, array<string, mixed>>
   *   Transcription rows.
   */
  public function getTranscriptions(array $nodes): array {
    $node_ids = array_values(array_unique(array_map(static fn(NodeInterface $node): int => (int) $node->id(), $nodes)));
    if (!$node_ids) {
      return [];
    }

    $media_storage = $this->entityTypeManager->getStorage('media');
    $query = $media_storage->getQuery()
      ->condition('field_media_of', $node_ids, 'IN')
      ->accessCheck(TRUE)
      ->sort('name');
    $or = $query->orConditionGroup()
      ->condition('bundle', 'extracted_text')
      ->condition('field_media_use.entity:taxonomy_term.name', 'Extracted Text');
    $query->condition($or);

    $mids = $query->execute();
    if (!$mids) {
      return [];
    }

    $nodes_by_id = [];
    foreach ($nodes as $node) {
      $nodes_by_id[(int) $node->id()] = $node;
    }

    $transcriptions = [];
    /** @var \Drupal\media\MediaInterface[] $media_items */
    $media_items = $media_storage->loadMultiple($mids);
    foreach ($media_items as $media) {
      $parent = $this->getMediaParent($media, $nodes_by_id);
      $file = $this->getMediaFile($media);
      $transcriptions[] = [
        'mid' => (int) $media->id(),
        'title' => $media->label(),
        'node_title' => $parent ? $parent->label() : '',
        'node_url' => $parent ? $parent->toUrl()->toString() : '',
        'url' => $media->toUrl()->toString(),
        'file_url' => $file ? $file->createFileUrl(FALSE) : '',
        'text' => $file ? $this->readTextFile($file) : '',
      ];
    }

    return $transcriptions;
  }

  /**
   * Performs page-scoped full-text search.
   *
   * @return array<int, array<string, mixed>>
   *   Normalized search result rows.
   */
  public function searchWithinPage(NodeInterface $page, string $query, int $limit = 10): array {
    $query = trim($query);
    if ($query === '') {
      return [];
    }

    $results = $this->searchApiWithinNode($page, $query, $limit);
    if ($results) {
      return $results;
    }

    $haystacks = [
      $page->label(),
      $this->getDescription($page),
    ];
    foreach ($this->getTranscriptions([$page]) as $transcription) {
      $haystacks[] = $transcription['text'] ?? '';
    }

    foreach ($haystacks as $text) {
      if ($text !== '' && stripos($text, $query) !== FALSE) {
        return [$this->normalizeSearchResult($page, $this->buildSnippet($text, $query), 'page')];
      }
    }

    return [];
  }

  /**
   * Uses Search API/Solr for source-scoped full-text search.
   *
   * @return array<int, array<string, mixed>>
   *   Normalized search result rows.
   */
  protected function searchApiWithinSource(NodeInterface $source, string $query, int $limit): array {
    return $this->searchApi($query, $limit, [
      'field_descendant_of' => (int) $source->id(),
    ]);
  }

  /**
   * Uses Search API/Solr for node-scoped full-text search.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node to search.
   * @param string $query
   *   Search query.
   * @param int $limit
   *   Maximum result count.
   *
   * @return array<int, array<string, mixed>>
   *   Normalized search result rows.
   */
  protected function searchApiWithinNode(NodeInterface $node, string $query, int $limit): array {
    return $this->searchApi($query, $limit, [
      'nid' => (int) $node->id(),
    ]);
  }

  /**
   * Uses Search API/Solr for full-text search with exact filters.
   *
   * @param string $query
   *   Search query.
   * @param int $limit
   *   Maximum result count.
   * @param array<string, int> $conditions
   *   Search API field conditions.
   *
   * @return array<int, array<string, mixed>>
   *   Normalized search result rows.
   */
  protected function searchApi(string $query, int $limit, array $conditions): array {
    try {
      /** @var \Drupal\search_api\IndexInterface|null $index */
      $index = $this->entityTypeManager->getStorage('search_api_index')->load('default_solr_index');
      if (!$index || !$index->status()) {
        return [];
      }

      $available_fields = array_keys($index->getFields());
      $fulltext_fields = array_values(array_intersect($available_fields, [
        'ocr_text',
        'rendered_item',
        'field_description',
        'field_full_title',
        'title',
      ]));

      $search = $index->query([
        'search id' => 'lehigh_islandora_source_browser',
      ]);
      $search->keys($query);
      if ($fulltext_fields) {
        $search->setFulltextFields($fulltext_fields);
      }
      foreach ($conditions as $field => $value) {
        $search->addCondition($field, $value);
      }
      $search->range(0, $limit);
      $search->sort('search_api_relevance', 'DESC');

      $result_set = $search->execute();
      $result_set->preLoadResultItems();
      $results = [];
      foreach ($result_set->getResultItems() as $item) {
        $object = $item->getOriginalObject(TRUE);
        $node = $object ? $object->getValue() : NULL;
        if (!$node instanceof NodeInterface || !$node->access('view')) {
          continue;
        }
        $results[] = $this->normalizeSearchResult($node, $item->getExcerpt(), 'full_text');
      }

      return $results;
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Performs a conservative source-scoped metadata fallback search.
   *
   * @return array<int, array<string, mixed>>
   *   Normalized search result rows.
   */
  protected function metadataSearchWithinSource(NodeInterface $source, string $query, int $limit): array {
    $matches = [];
    foreach ($this->getDescendants($source) as $node) {
      if (stripos($node->label(), $query) !== FALSE || stripos($this->getDescription($node), $query) !== FALSE) {
        $matches[(int) $node->id()] = $this->normalizeSearchResult($node, $this->getDescription($node), 'metadata');
      }
      if (count($matches) >= $limit) {
        break;
      }
    }
    return array_values($matches);
  }

  /**
   * Normalizes one search result row.
   */
  protected function normalizeSearchResult(NodeInterface $node, ?string $snippet, string $match_source): array {
    $snippet = trim((string) $snippet);
    if ($snippet === '') {
      $snippet = $this->getDescription($node);
    }

    return [
      'node' => $node,
      'nid' => (int) $node->id(),
      'title' => $node->label(),
      'model' => $this->getModel($node),
      'description' => $this->getDescription($node),
      'snippet' => Xss::filter($snippet, ['strong', 'em']),
      'url' => $node->toUrl()->toString(),
      'match_source' => $match_source,
    ];
  }

  /**
   * Builds a short plain-text snippet around the first match.
   */
  protected function buildSnippet(string $text, string $query): string {
    $text = trim(strip_tags($text));
    $position = stripos($text, $query);
    if ($position === FALSE) {
      return mb_substr($text, 0, 300);
    }

    $start = max(0, $position - 120);
    $snippet = mb_substr($text, $start, 300);
    return ($start > 0 ? '...' : '') . $snippet;
  }

  /**
   * Returns a node model label.
   */
  public function getModel(NodeInterface $node): string {
    if ($node->hasField('field_model') && !$node->get('field_model')->isEmpty() && $node->get('field_model')->entity) {
      return $node->get('field_model')->entity->label();
    }
    return '';
  }

  /**
   * Returns a part-detail row by type.
   */
  public function getPartDetail(NodeInterface $node, string $type): array {
    if (!$node->hasField('field_part_detail') || $node->get('field_part_detail')->isEmpty()) {
      return ['caption' => '', 'number' => '', 'title' => ''];
    }

    foreach ($node->get('field_part_detail') as $part) {
      if ((string) $part->type === $type) {
        return [
          'caption' => (string) $part->caption,
          'number' => (string) $part->number,
          'title' => (string) $part->title,
        ];
      }
    }

    return ['caption' => '', 'number' => '', 'title' => ''];
  }

  /**
   * Builds available download links for a node.
   */
  public function getDownloads(NodeInterface $node): array {
    $downloads = [];
    if (function_exists('lehigh_site_support_get_node_file')) {
      $uses = [
        'http://pcdm.org/use#ServiceFile' => 'Service PDF',
        'http://pcdm.org/use#OriginalFile' => 'Original File',
        'http://pcdm.org/use#PreservationMasterFile' => 'Preservation Master',
      ];
      foreach ($uses as $uri => $label) {
        $file = lehigh_site_support_get_node_file($node, $uri);
        if ($file) {
          $downloads[] = [
            'label' => $label,
            'url' => $file->createFileUrl(FALSE),
          ];
        }
      }
    }

    return $downloads;
  }

  /**
   * Returns displayable description text when available.
   */
  public function getDescription(NodeInterface $node): string {
    foreach (['field_abstract', 'field_description', 'body'] as $field_name) {
      if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
        return trim(strip_tags((string) $node->get($field_name)->value));
      }
    }
    return '';
  }

  /**
   * Returns the parent node for a media item from a candidate map.
   *
   * @param \Drupal\media\MediaInterface $media
   *   Media item.
   * @param array<int, \Drupal\node\NodeInterface> $nodes_by_id
   *   Candidate parent nodes keyed by ID.
   */
  protected function getMediaParent(MediaInterface $media, array $nodes_by_id): ?NodeInterface {
    if (!$media->hasField('field_media_of') || $media->get('field_media_of')->isEmpty()) {
      return NULL;
    }

    $node_id = (int) $media->get('field_media_of')->target_id;
    return $nodes_by_id[$node_id] ?? NULL;
  }

  /**
   * Returns the file entity backing a media item.
   */
  protected function getMediaFile(MediaInterface $media): ?FileInterface {
    $source = $media->getSource();
    $configuration = $source->getConfiguration();
    $field_name = $configuration['source_field'] ?? NULL;
    if (!$field_name || !$media->hasField($field_name) || $media->get($field_name)->isEmpty()) {
      return NULL;
    }

    $file = $media->get($field_name)->entity;
    return $file instanceof FileInterface ? $file : NULL;
  }

  /**
   * Reads a bounded text preview from a file when it is plain text.
   */
  protected function readTextFile(FileInterface $file): string {
    $uri = $file->getFileUri();
    $extension = strtolower(pathinfo($uri, PATHINFO_EXTENSION));
    if (!in_array($extension, ['txt', 'text', 'hocr', 'html', 'htm'], TRUE)) {
      return '';
    }

    $text = @file_get_contents($uri, FALSE, NULL, 0, 100000);
    if ($text === FALSE) {
      return '';
    }

    return trim(strip_tags($text));
  }

  /**
   * Sorts nodes chronologically, then by part detail, then title.
   */
  protected function compareNodes(NodeInterface $a, NodeInterface $b): int {
    $date_a = $a->hasField('field_edtf_date_issued') && !$a->get('field_edtf_date_issued')->isEmpty() ? (string) $a->get('field_edtf_date_issued')->value : '';
    $date_b = $b->hasField('field_edtf_date_issued') && !$b->get('field_edtf_date_issued')->isEmpty() ? (string) $b->get('field_edtf_date_issued')->value : '';
    if ($date_a !== $date_b) {
      return strcmp($date_a, $date_b);
    }

    $page_a = $this->getPartDetail($a, 'page')['number'];
    $page_b = $this->getPartDetail($b, 'page')['number'];
    if ($page_a !== $page_b) {
      return strnatcasecmp($page_a, $page_b);
    }

    return strnatcasecmp($a->label(), $b->label());
  }

}
