<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\JournalBrowser;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds a normalized source-browser render model.
 */
final class JournalBrowserBuilder {

  /**
   * Constructs the builder.
   */
  public function __construct(
    protected JournalBrowserRepository $repository,
    protected RequestStack $requestStack,
  ) {}

  /**
   * Builds the full browser model for a source node.
   */
  public function build(NodeInterface $source): array {
    $request = $this->requestStack->getCurrentRequest();
    $active_issue_id = $request ? (int) $request->query->get('issue') : 0;

    $issues = $this->repository->getIssues($source);
    $volumes = [];
    $flat_issues = [];

    foreach ($issues as $issue) {
      $issue_id = (int) $issue->id();
      $volume_key = $this->volumeKey($issue);
      if (!isset($volumes[$volume_key])) {
        $volumes[$volume_key] = [
          'key' => $volume_key,
          'label' => $this->volumeLabel($issue),
          'issues' => [],
        ];
      }

      $issue_summary = [
        'nid' => $issue_id,
        'title' => $issue->label(),
        'label' => $this->issueLabel($issue),
        'heading' => $this->issueHeading($issue),
        'manifest_url' => Url::fromUserInput('/node/' . $issue_id . '/book-manifest', [
          'absolute' => TRUE,
        ])->toString(),
        'url' => $this->browserUrl($source, [
          'query' => [
            'source' => (int) $source->id(),
            'issue' => $issue_id,
          ],
        ]),
        'active' => FALSE,
      ];
      $volumes[$volume_key]['issues'][] = $issue_summary;
      $flat_issues[$issue_id] = $issue_summary + ['node' => $issue];
    }

    if (!$active_issue_id || !isset($flat_issues[$active_issue_id])) {
      $active_issue_id = $flat_issues ? (int) array_key_first($flat_issues) : 0;
    }

    foreach ($volumes as &$volume) {
      foreach ($volume['issues'] as &$issue) {
        $issue['active'] = $issue['nid'] === $active_issue_id;
      }
    }

    $selected_issue = NULL;
    if ($active_issue_id && isset($flat_issues[$active_issue_id])) {
      $selected_issue = $flat_issues[$active_issue_id];
      $selected_issue['active'] = TRUE;
      $selected_issue['description'] = $this->repository->getDescription($selected_issue['node']);
      $selected_issue['downloads'] = $this->repository->getDownloads($selected_issue['node']);
      unset($selected_issue['node']);
    }

    $sources = [];
    foreach ($this->repository->getSources($source) as $source_node) {
      $sources[] = [
        'nid' => (int) $source_node->id(),
        'label' => $source_node->label(),
        'url' => $this->browserUrl($source_node, [
          'query' => [
            'source' => (int) $source_node->id(),
          ],
        ]),
        'active' => (int) $source_node->id() === (int) $source->id(),
      ];
    }

    $cacheability = new CacheableMetadata();
    $cacheability->addCacheableDependency($source);
    foreach ($issues as $node) {
      $cacheability->addCacheableDependency($node);
    }
    $cacheability->setCacheMaxAge(300);
    $cacheability->addCacheContexts(['url.query_args', 'user.permissions']);

    return [
      'source' => [
        'nid' => (int) $source->id(),
        'title' => $source->label(),
        'url' => $source->toUrl()->toString(),
        'browse_url' => $this->browserUrl($source, [
          'query' => [
            'source' => (int) $source->id(),
          ],
        ]),
      ],
      'sources' => $sources,
      'volumes' => array_values($volumes),
      'issues' => array_map(static function (array $issue): array {
        unset($issue['node']);
        return $issue;
      }, array_values($flat_issues)),
      'selected_issue' => $selected_issue,
      '#cacheability' => $cacheability,
    ];
  }

  /**
   * Builds a searchable index from source descendants.
   */
  public function buildIndex(NodeInterface $source): array {
    return $this->repository->buildAlphabeticalIndex($this->repository->getDescendants($source));
  }

  /**
   * Builds an alphabetical index from already-normalized page rows.
   */
  protected function buildPageIndex(array $pages): array {
    $index = [];
    foreach ($pages as $page) {
      $letter = strtoupper(substr($page['title'], 0, 1));
      if (!preg_match('/[A-Z]/', $letter)) {
        $letter = '#';
      }
      $index[$letter][] = [
        'title' => $page['page_label'] ? 'Page ' . $page['page_label'] : $page['title'],
        'model' => $page['model'],
        'browser_url' => $page['browser_url'],
      ];
    }
    ksort($index);
    return $index;
  }

  /**
   * Builds one item row.
   *
   * @param \Drupal\node\NodeInterface $source
   *   Source node.
   * @param \Drupal\node\NodeInterface $node
   *   Item node.
   * @param int $issue_id
   *   Active issue ID.
   */
  protected function item(NodeInterface $source, NodeInterface $node, int $issue_id): array {
    $page = $this->repository->getPartDetail($node, 'page');
    $downloads = $this->repository->getDownloads($node);
    return [
      'nid' => (int) $node->id(),
      'title' => $node->label(),
      'model' => $this->repository->getModel($node),
      'description' => $this->repository->getDescription($node),
      'page_label' => $page['number'] ?: $page['title'],
      'url' => $node->toUrl()->toString(),
      'browser_url' => $this->browserUrl($source, [
        'query' => array_filter([
          'source' => (int) $source->id(),
          'issue' => $issue_id,
        ]),
      ]),
      'downloads' => $downloads,
    ];
  }

  /**
   * Builds a canonical source-browser URL.
   */
  protected function browserUrl(NodeInterface $source, array $options = []): string {
    $options['query']['source'] = (int) ($options['query']['source'] ?? $source->id());
    return Url::fromRoute('lehigh_islandora.source_browser_landing', [], $options)->toString();
  }

  /**
   * Builds volume grouping key.
   */
  protected function volumeKey(NodeInterface $issue): string {
    $volume = $this->repository->getPartDetail($issue, 'volume');
    if ($volume['number'] !== '') {
      return 'volume-' . $volume['number'];
    }
    if ($issue->hasField('field_edtf_date_issued') && !$issue->get('field_edtf_date_issued')->isEmpty()) {
      return substr((string) $issue->get('field_edtf_date_issued')->value, 0, 4);
    }
    return 'issues';
  }

  /**
   * Builds volume display label.
   */
  protected function volumeLabel(NodeInterface $issue): string {
    $volume = $this->repository->getPartDetail($issue, 'volume');
    $date = $issue->hasField('field_edtf_date_issued') && !$issue->get('field_edtf_date_issued')->isEmpty() ? substr((string) $issue->get('field_edtf_date_issued')->value, 0, 4) : '';
    $parts = [];
    if ($date !== '') {
      $parts[] = $date;
    }
    if ($volume['number'] !== '') {
      $parts[] = '(Vol. ' . $volume['number'] . ')';
    }
    return $parts ? implode(' ', $parts) : 'Issues';
  }

  /**
   * Builds issue label.
   */
  protected function issueLabel(NodeInterface $issue): string {
    $volume = $this->repository->getPartDetail($issue, 'volume');
    $issue_detail = $this->repository->getPartDetail($issue, 'issue');
    $parts = [];
    if ($volume['number'] !== '') {
      $parts[] = 'Vol. ' . $volume['number'];
    }
    if ($issue_detail['number'] !== '') {
      $parts[] = 'Issue ' . $issue_detail['number'];
    }
    return $parts ? implode(', ', $parts) : $issue->label();
  }

  /**
   * Builds selected issue heading.
   */
  protected function issueHeading(NodeInterface $issue): string {
    $label = $this->issueLabel($issue);
    return $label !== $issue->label() ? $label . ': ' . $issue->label() : $issue->label();
  }

}
