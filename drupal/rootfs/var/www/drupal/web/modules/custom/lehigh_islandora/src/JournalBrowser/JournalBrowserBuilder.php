<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\JournalBrowser;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds one complete, shareable source-browser state from Drupal metadata.
 */
final class JournalBrowserBuilder {

  /**
   * Constructs the builder.
   */
  public function __construct(
    protected JournalBrowserRepository $repository,
    protected RequestStack $requestStack,
    protected BrowserAccess $access,
    protected BrowserTargetResolver $targets,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Builds server-rendered and API state using the same access decisions.
   */
  public function build(NodeInterface $source): array {
    $this->access->reset();
    $this->access->canView($source);
    $config = $this->configFactory->get('lehigh_islandora.journal_browser');
    $this->access->track($config);
    $request = $this->requestStack->getCurrentRequest();
    $query = trim((string) ($request?->query->get('q', '') ?? ''));
    $tab = (string) ($request?->query->get('tab', $config->get('default_tab') ?? 'pages') ?? 'pages');
    $tab = in_array($tab, ['pages', 'entries', 'index', 'transcription', 'about'], TRUE) ? $tab : 'pages';
    $scope = $request?->query->get('scope') === 'issue' ? 'issue' : 'source';
    $state = ['q' => $query, 'tab' => $tab, 'scope' => $scope];
    $catalog = $this->repository->getSubjectCatalog($source);
    $issues = $this->repository->getIssues($source);
    $issue_id = (int) ($request?->query->get('issue') ?? 0);
    if (!isset($issues[$issue_id])) {
      $issue_id = (int) (array_key_first($issues) ?? 0);
    }
    $volumes = [];
    $summaries = [];
    foreach ($issues as $id => $issue) {
      $date = $this->repository->getDate($issue);
      $volume = $this->repository->getPartDetail($issue, 'volume');
      $detail = $this->repository->getPartDetail($issue, 'issue');
      $label = implode(', ', array_filter([
        $volume['number'] !== '' ? 'Vol. ' . $volume['number'] : '',
        $detail['number'] !== '' ? 'Issue ' . $detail['number'] : $detail['title'],
      ]));
      $label = ($date !== '' ? $date . ' — ' : '') . ($label ?: $issue->label());
      // Include the year: some legacy newspapers reused volume numbers.
      $key = substr($date, 0, 4) . ':volume-' . $volume['number'];
      $volume_label = trim(substr($date, 0, 4) . ($volume['number'] !== '' ? ' (Vol. ' . $volume['number'] . ')' : '')) ?: 'Undated documents';
      $volumes[$key] ??= ['key' => $key, 'label' => $volume_label, 'active' => FALSE, 'issues' => []];
      $summary = [
        'nid' => (int) $id,
        'title' => $issue->label(),
        'date' => $date,
        'label' => $label,
        'heading' => $issue->label(),
        'active' => (int) $id === $issue_id,
        'url' => $this->browserUrl($source, $state + ['issue' => (int) $id]),
      ];
      $summaries[$id] = $summary;
      $volumes[$key]['issues'][] = $summary;
      $volumes[$key]['active'] = $volumes[$key]['active'] || $summary['active'];
    }
    $selected = NULL;
    $available_tabs = $catalog ? ['index'] : [];
    if (isset($issues[$issue_id])) {
      $issue = $issues[$issue_id];
      $items = $this->repository->getIssueItems($issue);
      $page_id = (int) ($request?->query->get('page') ?? 0);
      if (!isset($items['pages'][$page_id])) {
        $page_id = (int) (array_key_first($items['pages']) ?? 0);
      }
      $state['issue'] = $issue_id;
      $state['page'] = $page_id;
      $available_tabs = [];
      $page_node = $items['pages'][$page_id] ?? NULL;
      if ($this->repository->getDate($issue) || $this->repository->getDescription($issue) || $this->metadata($issue)
        || ($page_node && ($this->repository->getDate($page_node) || $this->repository->getDescription($page_node) || $this->metadata($page_node)))) {
        $available_tabs[] = 'about';
      }
      if ($items['pages']) {
        array_unshift($available_tabs, 'pages');
      }
      if ($items['entries']) {
        $available_tabs[] = 'entries';
      }
      if ($catalog) {
        $available_tabs[] = 'index';
      }
      $transcript_nodes = array_merge([$issue], isset($items['pages'][$page_id]) ? [$items['pages'][$page_id]] : []);
      if ($this->repository->hasTranscriptions($transcript_nodes)) {
        $available_tabs[] = 'transcription';
      }
      if (!in_array($tab, $available_tabs, TRUE)) {
        $tab = $available_tabs[0] ?? 'pages';
        $state['tab'] = $tab;
      }
      $canvases = $tab === 'pages' ? $this->targets->getCanvases($issue) : [];
      $pages = [];
      foreach ($items['pages'] as $id => $page) {
        $pages[$id] = $this->item($source, $page, $state, $canvases[$id] ?? []);
        $pages[$id]['active'] = (int) $id === $page_id;
      }
      $entries = [];
      foreach ($items['entries'] as $entry) {
        $entry_page = $this->entryPage($entry, $items['pages']);
        $entries[] = $this->item($source, $entry, array_replace($state, ['page' => $entry_page]), $canvases[$entry_page] ?? []);
      }
      $selected = $summaries[$issue_id] + [
        'description' => $this->repository->getDescription($issue),
        'date' => $this->repository->getDate($issue),
        'metadata' => $this->metadata($issue),
        'url' => $issue->toUrl()->toString(),
      ];
      $selected['url'] = $issue->toUrl()->toString();
      $selected['downloads'] = $this->repository->getDownloads($issue);
      $selected['pages'] = array_values($pages);
      $selected['entries'] = $entries;
      $selected['selected_page'] = $pages[$page_id] ?? NULL;
      $selected['previous_page'] = $this->neighbor($pages, $page_id, -1);
      $selected['next_page'] = $this->neighbor($pages, $page_id, 1);
      $selected['previous_issue'] = $this->neighbor($summaries, $issue_id, -1);
      $selected['next_issue'] = $this->neighbor($summaries, $issue_id, 1);
      $selected['manifest_url'] = Url::fromUserInput('/node/' . $issue_id . '/book-manifest', ['absolute' => TRUE])->toString();
      $selected['can_view'] = $this->repository->canReadFiles($issue) && (bool) $canvases;
      $selected['transcriptions'] = $tab === 'transcription' ? $this->repository->getTranscriptions(
        array_merge([$issue], isset($items['pages'][$page_id]) ? [$items['pages'][$page_id]] : [])
      ) : [];
    }
    $page_size = max(1, min(100, (int) ($config->get('page_size') ?? 25)));
    $offset = max(0, min(100000, (int) ($request?->query->get('offset') ?? 0)));
    $results = $query === '' ? [] : $this->repository->searchWithinSource($source, $query, $page_size + 1, $offset,
      $scope === 'issue' ? ($issues[$issue_id] ?? NULL) : NULL);
    $has_next = count($results) > $page_size;
    $results = array_slice($results, 0, $page_size);
    foreach ($results as &$result) {
      $target_issue = $this->targets->getIssue($result['node'], $issues);
      $result['browser_url'] = $target_issue ? $this->browserUrl($source, [
        'issue' => (int) $target_issue->id(),
        'page' => $this->repository->getModel($result['node']) === 'Page' ? $result['nid'] : 0,
        'tab' => 'pages',
      ]) : $result['url'];
      unset($result['node']);
    }
    unset($result);
    $sources = [];
    foreach ($this->repository->getSources() as $node) {
      $sources[] = [
        'nid' => (int) $node->id(),
        'label' => $node->label(),
        'active' => (int) $node->id() === (int) $source->id(),
        'url' => $this->browserUrl($node),
      ];
    }
    $letters = array_keys($catalog);
    $letter = (string) ($request?->query->get('letter', '') ?? '');
    $term = max(0, (int) ($request?->query->get('term') ?? 0));
    if (!isset($catalog[$letter])) {
      $letter = $letters[0] ?? '';
    }
    $index = [];
    if ($tab === 'index') {
      $index = $term ? $this->buildIndex($source, $issues, $term) : ($letter ? [$letter => $catalog[$letter]] : []);
      foreach ($index as &$terms) {
        foreach ($terms as &$subject) {
          $subject['hits_url'] = $this->browserUrl($source, array_replace($state, [
            'tab' => 'index',
            'letter' => $letter,
            'term' => $subject['tid'],
          ]));
          $subject['expanded'] = $term === $subject['tid'];
        }
        unset($subject);
      }
      unset($terms);
    }
    return [
      'source' => [
        'nid' => (int) $source->id(),
        'title' => $source->label(),
        'url' => $source->toUrl()->toString(),
        'browse_url' => $this->browserUrl($source),
      ],
      'sources' => $sources,
      'volumes' => array_values($volumes),
      'issues' => array_values($summaries),
      'selected_issue' => $selected,
      'query' => $query,
      'scope' => $scope,
      'offset' => $offset,
      'search_results' => $results,
      'previous_results_url' => $offset ? $this->browserUrl($source, $state + ['offset' => max(0, $offset - $page_size)]) : '',
      'next_results_url' => $has_next ? $this->browserUrl($source, $state + ['offset' => $offset + $page_size]) : '',
      'tab' => $tab,
      'tabs' => array_map(fn(string $key): string => $this->browserUrl($source, array_replace($state, ['tab' => $key])),
        array_combine(
          $available_tabs,
          $available_tabs,
        )),
      'index' => $index,
      'index_letters' => array_combine($letters, array_map(
        fn(string $key): string => $this->browserUrl($source, array_replace($state, ['letter' => $key, 'tab' => 'index'])),
        $letters,
      )),
      'index_all_url' => $this->browserUrl($source, array_replace($state, ['tab' => 'index'])),
      'letter' => $letter,
      'subcollections' => array_map(fn(NodeInterface $node): array => [
        'title' => $node->label(),
        'url' => $this->repository->isBrowserSource($node) ? $this->browserUrl($node) : $node->toUrl()->toString(),
      ], array_values($this->repository->getSubcollections($source))),
      '#cacheability' => $this->access->getCacheability(),
    ];
  }

  /**
   * Builds source-local subject hits with browser page targets.
   */
  public function buildIndex(NodeInterface $source, ?array $issues = NULL, ?int $tid = NULL): array {
    if ($tid === NULL) {
      return $this->repository->getSubjectCatalog($source);
    }
    $nodes = $this->repository->getSubjectNodes($source, $tid);
    $issues ??= $this->repository->getIssues($source);
    $index = $this->repository->buildAlphabeticalIndex($nodes);
    foreach ($this->repository->getCuratedIndex($source, $tid) as $curated) {
      $letter = mb_strtoupper(mb_substr($curated['title'], 0, 1));
      $letter = preg_match('/^[A-Z]$/', $letter) ? $letter : '#';
      $found = FALSE;
      foreach ($index[$letter] ?? [] as $position => $term) {
        if ($term['tid'] === $curated['tid']) {
          $index[$letter][$position]['hits'] = $curated['hits'];
          $found = TRUE;
          break;
        }
      }
      if (!$found) {
        $index[$letter][] = $curated;
      }
    }
    ksort($index);
    foreach ($index as &$terms) {
      usort($terms, static fn(array $a, array $b): int => strnatcasecmp($a['title'], $b['title']));
    }
    unset($terms);
    foreach ($index as &$terms) {
      $terms = array_values(array_filter($terms, static fn(array $item): bool => $item['tid'] === $tid));
      foreach ($terms as &$term) {
        foreach ($term['hits'] as &$hit) {
          $node = $nodes[$hit['nid']] ?? NULL;
          if (!empty($hit['issue'])) {
            $hit['browser_url'] = $this->browserUrl($source, [
              'issue' => $hit['issue'],
              'page' => $hit['nid'],
              'tab' => 'pages',
            ]);
            continue;
          }
          if (!$node) {
            continue;
          }
          $issue = $this->targets->getIssue($node, $issues);
          $hit['browser_url'] = $issue ? $this->browserUrl($source, [
            'issue' => (int) $issue->id(),
            'page' => $this->repository->getModel($node) === 'Page' ? (int) $node->id() : 0,
            'tab' => 'pages',
          ]) : $hit['url'];
        }
        unset($hit);
      }
      unset($term);
    }
    unset($terms);
    return $index;
  }

  /**
   * Finds an entry's first child page or its page-number metadata target.
   */
  protected function entryPage(NodeInterface $entry, array $pages): int {
    $number = $this->repository->getPartDetail($entry, 'page')['number'];
    foreach ($pages as $id => $page) {
      if ($this->repository->isDescendantOf($page, $entry) || ($number !== '' && $this->repository->getPartDetail($page, 'page')['number'] === $number)) {
        return (int) $id;
      }
    }
    return 0;
  }

  /**
   * Normalizes a page/entry and keeps page state in every link.
   */
  protected function item(NodeInterface $source, NodeInterface $node, array $state, array $canvas): array {
    $id = (int) $node->id();
    $is_page = $this->repository->getModel($node) === 'Page';
    $state['page'] = $is_page ? $id : $state['page'];
    $detail = $this->repository->getPartDetail($node, 'page');
    return [
      'nid' => $id,
      'title' => $node->label(),
      'page_label' => $detail['number'] ?: $detail['title'],
      'date' => $this->repository->getDate($node),
      'description' => $this->repository->getDescription($node),
      'metadata' => $this->metadata($node),
      'thumbnail' => $state['tab'] === 'pages' ? $this->repository->getThumbnail($node) : '',
      'url' => $node->toUrl()->toString(),
      'browser_url' => $this->browserUrl($source, array_replace($state, ['tab' => 'pages'])),
      'navigation_url' => $this->browserUrl($source, $state),
      'transcription_url' => $this->browserUrl($source, array_replace($state, ['tab' => 'transcription'])),
      'downloads' => in_array($state['tab'], ['pages', 'entries'], TRUE) ? $this->repository->getDownloads($node) : [],
    ] + $canvas;
  }

  /**
   * Supplies metadata for page and volume descriptions without raw HTML.
   */
  protected function metadata(NodeInterface $node): array {
    $rows = [];
    foreach ([
      'field_publisher', 'field_note', 'field_rights', 'field_source',
      'field_subject', 'field_subject_general', 'field_subjects_name',
      'field_geographic_subject',
    ] as $name) {
      if (!$node->hasField($name) || $node->get($name)->isEmpty()) {
        continue;
      }
      $field = $node->get($name);
      $values = [];
      foreach ($field as $item) {
        if (isset($item->target_id) && $item->entity) {
          if ($this->access->canView($item->entity)) {
            $values[] = $item->entity->label();
          }
        }
        elseif (isset($item->value)) {
          $values[] = trim(strip_tags((string) $item->value));
        }
      }
      if ($values) {
        $rows[] = ['label' => $field->getFieldDefinition()->getLabel(), 'value' => implode('; ', $values)];
      }
    }
    return $rows;
  }

  /**
   * Gets a neighbor from an ordered list without wrapping at either end.
   */
  protected function neighbor(array $items, int $id, int $direction): ?array {
    $keys = array_keys($items);
    $position = array_search($id, $keys, TRUE);
    return $position !== FALSE && isset($keys[$position + $direction]) ? $items[$keys[$position + $direction]] : NULL;
  }

  /**
   * Makes stable URLs from validated state; routing adds any site base path.
   */
  public function browserUrl(NodeInterface $source, array $query = []): string {
    return Url::fromRoute('lehigh_islandora.source_browser_landing', [], [
      'query' => ['source' => (int) $source->id()] + array_filter($query, static fn(mixed $value): bool => $value !== '' && $value !== 0),
    ])->toString();
  }

}
