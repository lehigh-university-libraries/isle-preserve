<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\JournalBrowser;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Config\ConfigFactoryInterface;
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
    protected BrowserAccess $access,
    protected DownloadResolver $downloadResolver,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns source collections flagged for the browser.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Source nodes keyed by node ID.
   */
  public function getSources(?NodeInterface $current = NULL): array {
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->condition('field_display_hints.entity:taxonomy_term.name', 'Journal Browser')
      ->execute();
    $sources = array_filter($this->loadNodesByIds(array_values($ids)), fn(NodeInterface $node): bool => $this->isBrowserSource($node));

    uasort($sources, static fn(NodeInterface $a, NodeInterface $b): int => strcasecmp($a->label(), $b->label()));
    return $sources;
  }

  /**
   * The collection metadata switch that enables this view.
   */
  public function isBrowserSource(NodeInterface $node): bool {
    return $this->access->canView($node)
      && lehigh_site_support_identify_collection($node, TRUE)
      && lehigh_site_support_has_display_hint($node, 'Journal Browser');
  }

  /**
   * Returns the date metadata, including diary creation dates.
   */
  public function getDate(NodeInterface $node): string {
    foreach (['field_edtf_date_issued', 'field_edtf_date_created', 'field_edtf_date'] as $field) {
      if ($node->hasField($field) && !$node->get($field)->isEmpty()) {
        return (string) $node->get($field)->value;
      }
    }
    return '';
  }

  /**
   * Returns direct, accessible subcollections, including newly imported ones.
   */
  public function getSubcollections(NodeInterface $source): array {
    return array_filter($this->loadNodesByIds($this->getDirectChildIds((int) $source->id())),
      static fn(NodeInterface $node): bool => lehigh_site_support_identify_collection($node, TRUE));
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

    $candidate_ids = $this->getDescendantIds((int) $source->id());
    $issues = $this->loadNodesByIds($this->filterNodeIdsByModel($candidate_ids, $issue_models));
    // Containers with Publication Issues are volumes.
    foreach ($issues as $id => $issue) {
      if ($this->getModel($issue) !== 'Publication Issue') {
        foreach ($issues as $other) {
          if ($this->getModel($other) === 'Publication Issue' && $this->isDescendantOf($other, $issue)) {
            unset($issues[$id]);
            break;
          }
        }
      }
    }
    if (!$issues) {
      $issues = array_filter($this->getDescendants($source), fn(NodeInterface $node): bool =>
        !lehigh_site_support_identify_collection($node, TRUE) && $this->getModel($node) !== 'Page');
    }
    if (!$issues && $this->getDirectChildIds((int) $source->id())) {
      // Flat collections of pages use the source as their enclosing document.
      $issues = [(int) $source->id() => $source];
    }
    return $issues;
  }

  /**
   * Returns entry and page nodes for one selected issue/document.
   *
   * @return array{entries: array<int, \Drupal\node\NodeInterface>, pages: array<int, \Drupal\node\NodeInterface>}
   *   Entries and pages keyed by node ID.
   */
  public function getIssueItems(NodeInterface $issue): array {
    $nodes = $this->getDescendants($issue);
    $pages = array_filter($nodes, fn(NodeInterface $node): bool => $this->getModel($node) === 'Page');
    $entries = array_diff_key($nodes, $pages);
    uasort($pages, function (NodeInterface $a, NodeInterface $b): int {
      $weight_a = $a->hasField('field_weight') ? (float) $a->get('field_weight')->value : 0;
      $weight_b = $b->hasField('field_weight') ? (float) $b->get('field_weight')->value : 0;
      return ($weight_a <=> $weight_b) ?: strnatcasecmp($this->getPartDetail($a, 'page')['number'], $this->getPartDetail($b, 'page')['number']) ?: strnatcasecmp($a->label(), $b->label());
    });
    return ['entries' => $entries, 'pages' => $pages];
  }

  /**
   * Gets source descendants by walking field_member_of relationships.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Descendants keyed by node ID.
   */
  public function getDescendants(NodeInterface $source): array {
    $id = (int) $source->id();
    if (!isset($this->descendants[$id])) {
      $this->descendants[$id] = $this->loadNodesByIds($this->getDescendantIds($id));
    }
    foreach ($this->descendants[$id] as $node) {
      $this->access->canView($node);
    }
    return $this->descendants[$id];
  }

  /**
   * Returns direct published, viewable child IDs.
   */
  protected function getDirectChildIds(int $parent_id): array {
    return array_map('intval', array_values($this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)->condition('status', 1)->condition('field_member_of', $parent_id)->execute()));
  }

  /**
   * Walks accessible relationships, guarding against cycles and missing levels.
   */
  protected function getDescendantIds(int $source_id): array {
    $seen = [$source_id => TRUE];
    $frontier = [$source_id];
    while ($frontier) {
      $children = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(TRUE)->condition('status', 1)->condition('field_member_of', $frontier, 'IN')->execute();
      $frontier = [];
      foreach ($children as $id) {
        $id = (int) $id;
        if (!isset($seen[$id])) {
          $seen[$id] = TRUE;
          $frontier[] = $id;
        }
      }
    }
    unset($seen[$source_id]);
    return array_keys($seen);
  }

  /**
   * Checks membership through every parent, including multi-parent objects.
   */
  public function isDescendantOf(NodeInterface $node, NodeInterface $source, array $seen = []): bool {
    $id = (int) $node->id();
    if ($id === (int) $source->id()) {
      return TRUE;
    }
    if (isset($seen[$id]) || !$this->access->canView($node) || !$node->hasField('field_member_of')) {
      return FALSE;
    }
    $seen[$id] = TRUE;
    foreach ($node->get('field_member_of')->referencedEntities() as $parent) {
      if ($parent instanceof NodeInterface && $this->isDescendantOf($parent, $source, $seen)) {
        return TRUE;
      }
    }
    return FALSE;
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
    $nodes = array_filter($nodes, function (NodeInterface $node): bool {
      return $this->access->canView($node);
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
  public function searchWithinSource(NodeInterface $source, string $query, int $limit = 25, int $offset = 0, ?NodeInterface $issue = NULL): array {
    $query = trim($query);
    if ($query === '') {
      return [];
    }
    $scope = $issue ?? $source;
    if (!$this->isDescendantOf($scope, $source)) {
      return [];
    }
    $results = $this->searchApiWithinSource($scope, $query, $limit, $offset);
    return $results ?? $this->metadataSearchWithinSource($scope, $query, $limit, $offset);
  }

  /**
   * Lists subject terms without loading every page or every curated range.
   */
  public function getSubjectCatalog(NodeInterface $source): array {
    $ids = $this->getDescendantIds((int) $source->id());
    if (!$ids) {
      return [];
    }
    $tids = [];
    foreach ($this->subjectFields() as $field) {
      $table = 'node__' . $field;
      if ($this->database->schema()->tableExists($table)) {
        $found = $this->database->select($table, 's')->distinct()
          ->fields('s', [$field . '_target_id'])->condition('entity_id', $ids, 'IN')
          ->condition('deleted', 0)->execute()->fetchCol();
        $tids = array_merge($tids, $found);
      }
    }
    $this->access->track(new CacheableMetadata(['tags' => ['lehigh_source_index_entry_list']]));
    if ($this->database->schema()->tableExists('lehigh_source_index_entry')) {
      $found = $this->database->select('lehigh_source_index_entry', 'i')->distinct()
        ->fields('i', ['subject'])->condition('source', (int) $source->id())
        ->condition('page', $ids, 'IN')->condition('issue', $ids, 'IN')->execute()->fetchCol();
      $tids = array_merge($tids, $found);
    }
    $index = [];
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple(array_unique($tids));
    foreach ($terms as $term) {
      if (!$this->access->canView($term)) {
        continue;
      }
      $letter = mb_strtoupper(mb_substr($term->label(), 0, 1));
      $letter = preg_match('/^[A-Z]$/', $letter) ? $letter : '#';
      $index[$letter][] = [
        'tid' => (int) $term->id(),
        'title' => $term->label(),
        'url' => $term->toUrl()->toString(),
        'hits' => [],
      ];
    }
    ksort($index);
    foreach ($index as &$terms) {
      usort($terms, static fn(array $a, array $b): int => strnatcasecmp($a['title'], $b['title']));
    }
    return $index;
  }

  /**
   * Loads only pages that reference the requested subject.
   */
  public function getSubjectNodes(NodeInterface $source, int $tid): array {
    $ids = $this->getDescendantIds((int) $source->id());
    if (!$ids) {
      return [];
    }
    $query = $this->entityTypeManager->getStorage('node')->getQuery()->accessCheck(TRUE)
      ->condition('status', 1)->condition('nid', $ids, 'IN');
    $group = $query->orConditionGroup();
    foreach ($this->subjectFields() as $field) {
      if ($this->database->schema()->tableExists('node__' . $field)) {
        $group->condition($field, $tid);
      }
    }
    $query->condition($group);
    return $this->loadNodesByIds(array_values($query->execute()));
  }

  /**
   * Subject reference fields included in native and curated browsing.
   */
  protected function subjectFields(): array {
    return [
      'field_subject', 'field_subject_general', 'field_subject_lcsh',
      'field_subjects_name', 'field_geographic_subject', 'field_temporal_subject',
    ];
  }

  /**
   * Checks transcript availability without reading attached text files.
   */
  public function hasTranscriptions(array $nodes): bool {
    foreach ($nodes as $node) {
      if (!$this->canReadFiles($node)) {
        continue;
      }
      $query = $this->entityTypeManager->getStorage('media')->getQuery()->accessCheck(TRUE)
        ->condition('status', 1)->condition('bundle', 'extracted_text')
        ->condition('field_media_of', $node->id());
      foreach ($this->entityTypeManager->getStorage('media')->loadMultiple($query->execute()) as $media) {
        if ($this->access->canView($media) && $this->downloadResolver->getFile($media)) {
          return TRUE;
        }
      }
    }
    return FALSE;
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
    $terms = [];
    foreach ($nodes as $node) {
      foreach ([
        'field_subject',
        'field_subject_general',
        'field_subject_lcsh',
        'field_subjects_name',
        'field_geographic_subject',
        'field_temporal_subject',
      ] as $field) {
        if (!$node->hasField($field)) {
          continue;
        }
        foreach ($node->get($field)->referencedEntities() as $term) {
          if (!$this->access->canView($term)) {
            continue;
          }
          $tid = (int) $term->id();
          $terms[$tid]['title'] = $term->label();
          $terms[$tid]['tid'] = $tid;
          $terms[$tid]['url'] = $term->toUrl()->toString();
          $terms[$tid]['hits'][(int) $node->id()] = [
            'nid' => (int) $node->id(),
            'title' => $node->label(),
            'url' => $node->toUrl()->toString(),
          ];
        }
      }
    }
    $index = [];
    foreach ($terms as $term) {
      $letter = mb_strtoupper(mb_substr($term['title'], 0, 1));
      $letter = preg_match('/^[A-Z]$/', $letter) ? $letter : '#';
      $term['hits'] = array_values($term['hits']);
      $index[$letter][] = $term;
    }

    ksort($index);
    foreach ($index as &$entries) {
      usort($entries, static fn(array $a, array $b): int => strnatcasecmp($a['title'], $b['title']));
    }
    return $index;
  }

  /**
   * Reads migrated ranges, checking each referenced entity before output.
   */
  public function getCuratedIndex(NodeInterface $source, ?int $tid = NULL): array {
    $this->access->track(new CacheableMetadata(['tags' => ['lehigh_source_index_entry_list']]));
    if (!$this->database->schema()->tableExists('lehigh_source_index_entry')) {
      return [];
    }
    $query = $this->database->select('lehigh_source_index_entry', 'i')->fields('i')
      ->condition('source', (int) $source->id())->orderBy('volume')->orderBy('start_page');
    if ($tid !== NULL) {
      $query->condition('subject', $tid);
    }
    $rows = $query->execute();
    $index = [];
    foreach ($rows as $row) {
      $page = $this->entityTypeManager->getStorage('node')->load($row->page);
      $issue = $this->entityTypeManager->getStorage('node')->load($row->issue);
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($row->subject);
      if (!$page instanceof NodeInterface || !$issue instanceof NodeInterface || !$term
        || !$this->access->canView($page) || !$this->access->canView($issue) || !$this->access->canView($term)
        || !$this->isDescendantOf($page, $issue) || !$this->isDescendantOf($issue, $source)) {
        continue;
      }
      $tid = (int) $term->id();
      $index[$tid] ??= ['tid' => $tid, 'title' => $term->label(), 'url' => $term->toUrl()->toString(), 'hits' => []];
      $range = $row->start_page == $row->end_page ? (string) $row->start_page : $row->start_page . '–' . $row->end_page;
      $index[$tid]['hits'][] = [
        'nid' => (int) $page->id(),
        'issue' => (int) $issue->id(),
        'title' => 'Vol. ' . $row->volume . ', pp. ' . $range,
        'url' => $page->toUrl()->toString(),
      ];
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
    $mids = $media_storage->getQuery()->condition('field_media_of', $node_ids, 'IN')
      ->condition('bundle', 'extracted_text')->condition('status', 1)->accessCheck(TRUE)->sort('name')->execute();
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
      if (!$parent || !$this->access->canView($media) || !$this->canReadFiles($parent)) {
        continue;
      }
      $file = $this->downloadResolver->getFile($media);
      $transcriptions[] = [
        'mid' => (int) $media->id(),
        'title' => $media->label(),
        'node_title' => $parent ? $parent->label() : '',
        'node_url' => $parent ? $parent->toUrl()->toString() : '',
        'url' => $media->toUrl()->toString(),
        'file_url' => $file ? $file->createFileUrl(FALSE) : '',
        'text' => $file ? $this->readTextFile($file) : '',
        'truncated' => $file && $file->getSize() > 100000,
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
  protected function searchApiWithinSource(NodeInterface $source, string $query, int $limit, int $offset = 0): ?array {
    return $this->searchApi($query, $limit, [
      'field_descendant_of' => (int) $source->id(),
    ], $offset, $source);
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
  protected function searchApiWithinNode(NodeInterface $node, string $query, int $limit): ?array {
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
   * @param int $offset
   *   Result offset.
   * @param \Drupal\node\NodeInterface|null $scope
   *   Optional hierarchy scope, verified again on each returned entity.
   *
   * @return array<int, array<string, mixed>>
   *   Normalized search result rows.
   */
  protected function searchApi(string $query, int $limit, array $conditions, int $offset = 0, ?NodeInterface $scope = NULL): ?array {
    try {
      /** @var \Drupal\search_api\IndexInterface|null $index */
      $index = $this->entityTypeManager->getStorage('search_api_index')->load($this->configFactory->get('lehigh_islandora.journal_browser')->get('search_index') ?? 'default_solr_index');
      if (!$index || !$index->status()) {
        return NULL;
      }
      $this->access->track($index);
      $this->access->track($index->getServerInstance());
      $this->access->track($this->configFactory->get('lehigh_islandora.journal_browser'));

      $available_fields = array_keys($index->getFields());
      if (array_diff(array_keys($conditions), $available_fields)) {
        return NULL;
      }
      $fulltext_fields = $index->getFulltextFields();

      $search = $index->query([
        'search id' => 'lehigh_islandora_source_browser',
      ]);
      $search->keys($query);
      if ($fulltext_fields) {
        $search->setFulltextFields($fulltext_fields);
      }
      foreach ($conditions as $field => $value) {
        if ($field === 'field_descendant_of' && in_array('nid', $available_fields, TRUE)) {
          $group = $search->createConditionGroup('OR');
          $group->addCondition($field, $value);
          $group->addCondition('nid', $value);
          $search->addConditionGroup($group);
        }
        else {
          $search->addCondition($field, $value);
        }
      }
      $search->range($offset, $limit);
      $search->sort('search_api_relevance', 'DESC');

      $result_set = $search->execute();
      $this->access->track(new CacheableMetadata(['tags' => ['search_api_list']]));
      $result_set->preLoadResultItems();
      $results = [];
      foreach ($result_set->getResultItems() as $item) {
        $object = $item->getOriginalObject(TRUE);
        $node = $object ? $object->getValue() : NULL;
        if (!$node instanceof NodeInterface || !$this->access->canView($node) || !$this->canReadFiles($node) || ($scope && !$this->isDescendantOf($node, $scope))) {
          continue;
        }
        $results[] = $this->normalizeSearchResult($node, $item->getExcerpt(), 'full_text');
      }

      return $results;
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Performs a conservative source-scoped metadata fallback search.
   *
   * @return array<int, array<string, mixed>>
   *   Normalized search result rows.
   */
  protected function metadataSearchWithinSource(NodeInterface $source, string $query, int $limit, int $offset = 0): array {
    $matches = [];
    $nodes = [(int) $source->id() => $source] + $this->getDescendants($source);
    foreach ($nodes as $node) {
      $text = $node->label() . "\n" . $this->getDescription($node);
      foreach (['field_subject', 'field_subject_general', 'field_subjects_name'] as $field) {
        if ($node->hasField($field)) {
          foreach ($node->get($field)->referencedEntities() as $term) {
            if ($this->access->canView($term)) {
              $text .= "\n" . $term->label();
            }
          }
        }
      }
      if (mb_stripos($text, $query) !== FALSE) {
        $matches[] = $this->normalizeSearchResult($node, $this->buildSnippet($text, $query), 'metadata');
      }
    }
    return array_slice($matches, $offset, $limit);
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
    $position = mb_stripos($text, $query);
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
    return $this->downloadResolver->resolve($node);
  }

  /**
   * Uses the common file/viewer access policy.
   */
  public function canReadFiles(NodeInterface $node): bool {
    return $this->access->canReadFiles($node);
  }

  /**
   * Returns an accessible thumbnail URL without exposing restricted media.
   */
  public function getThumbnail(NodeInterface $node): string {
    if (!$this->canReadFiles($node) || !$node->hasField('field_thumbnail')) {
      return '';
    }
    $media = $node->get('field_thumbnail')->entity;
    if (!$media instanceof MediaInterface || !$this->access->canView($media)) {
      return '';
    }
    $file = $this->downloadResolver->getFile($media);
    return $file && str_starts_with($file->getMimeType(), 'image/') ? $file->createFileUrl(FALSE) : '';
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

    if (in_array($extension, ['txt', 'text'], TRUE)) {
      return trim($text);
    }
    return trim(strip_tags(preg_replace('/<\/(p|div|br|li)>/i', "\n", $text)));
  }

  /**
   * Sorts nodes chronologically, then by part detail, then title.
   */
  protected function compareNodes(NodeInterface $a, NodeInterface $b): int {
    $date_a = $this->getDate($a);
    $date_b = $this->getDate($b);
    if ($date_a !== $date_b) {
      return $date_a === '' ? 1 : ($date_b === '' ? -1 : strcmp($date_a, $date_b));
    }

    $page_a = $this->getPartDetail($a, 'page')['number'];
    $page_b = $this->getPartDetail($b, 'page')['number'];
    if ($page_a !== $page_b) {
      return strnatcasecmp($page_a, $page_b);
    }

    return strnatcasecmp($a->label(), $b->label());
  }

}
