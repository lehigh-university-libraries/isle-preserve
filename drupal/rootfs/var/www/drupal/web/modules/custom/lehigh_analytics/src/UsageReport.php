<?php

namespace Drupal\lehigh_analytics;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AnonymousUserSession;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Read-only aggregation of Entity Metrics against current Islandora metadata.
 */
final class UsageReport {

  /**
   * Reports available to the page, blocks, and CSV routes.
   */
  public const TITLES = [
    'summary' => 'Recorded usage summary',
    'types' => 'Usage by content type',
    'views' => 'Top 100 documents by page views',
    'downloads' => 'Top 100 documents by downloads',
    'countries' => 'Countries & Territories',
    'top_types_views' => 'Top 100 documents per content type by page views',
    'top_types_downloads' => 'Top 100 documents per content type by downloads',
    'collections' => 'Usage by collection',
    'counter' => 'COUNTER mapping worksheet',
    'acrl' => 'ACRL preparation worksheet',
    'anomalies' => 'Page-view spikes for review',
  ];

  /**
   * Reports loaded by the public explorer.
   */
  public const PUBLIC_REPORTS = ['summary', 'types', 'views', 'downloads', 'collections', 'countries'];

  /**
   * Islandora model URIs with reporting meaning.
   */
  public const COLLECTION_URI = 'http://purl.org/dc/dcmitype/Collection';
  public const PAGE_URI = 'http://id.loc.gov/ontologies/bibframe/part';

  public function __construct(
    private readonly Connection $database,
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly CacheBackendInterface $cache,
    private readonly UsageRollup $rollup,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Finds the supported metadata references, without hard-coded term IDs.
   */
  public function metadataFields(): array {
    $fields = [];
    foreach ($this->fieldManager->getFieldDefinitions('node', 'islandora_object') as $name => $field) {
      if (in_array($name, [
        'field_genre', 'field_keywords', 'field_language', 'field_member_of',
        'field_model', 'field_physical_form', 'field_physical_location',
        'field_subject', 'field_subject_general', 'field_subject_lcsh',
        'field_subjects_name', 'field_geographic_subject', 'field_temporal_subject',
        'field_lcsh_topic',
      ], TRUE)
        && !$field->getFieldStorageDefinition()->isBaseField()
        && !$field->getFieldStorageDefinition()->hasCustomStorage()
        && $field->getType() === 'entity_reference'
        && in_array($field->getSetting('target_type'), ['node', 'taxonomy_term'], TRUE)) {
        $fields[$name] = $field;
      }
    }
    return $fields;
  }

  /**
   * Reporting periods shared by the page and exports.
   */
  public function periods(): array {
    $config = $this->configFactory->get('lehigh_analytics.settings');
    return ReportingPeriod::options($this->time->getRequestTime(), $config->get('timezone'), (int) $config->get('fiscal_start_month'));
  }

  /**
   * Validates every URL parameter before it reaches a query.
   */
  public function criteria(array $input): array {
    $fields = $this->metadataFields();
    $period = $input['period'] ?? 'all';
    $group = $input['group'] ?? 'field_model';
    if (!is_string($period) || !isset($this->periods()[$period])
      || !is_string($group) || !isset($fields[$group])
      || $fields[$group]->getSetting('target_type') !== 'taxonomy_term') {
      throw new BadRequestHttpException('Invalid reporting period or content type field.');
    }
    $filters = $input['filters'] ?? [];
    if (!is_array($filters)) {
      throw new BadRequestHttpException('Invalid metadata filters.');
    }
    foreach ($filters as $field => &$ids) {
      if (!isset($fields[$field]) || !is_array($ids) || count($ids) > 50) {
        throw new BadRequestHttpException('Invalid metadata filter.');
      }
      foreach ($ids as $id) {
        if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id < 1 || (int) $id > 2147483647) {
          throw new BadRequestHttpException('Metadata filters require positive entity IDs.');
        }
      }
      $ids = array_values(array_unique(array_map('intval', $ids)));
    }
    $options = [];
    foreach ([
      'baseline_days' => [28, 7, 90],
      'spike_multiplier' => [5, 2, 100],
      'minimum_views' => [50, 1, 1000000],
      'session_share' => [50, 1, 100],
    ] as $name => [$default, $minimum, $maximum]) {
      $value = $input[$name] ?? $default;
      if (!is_scalar($value) || !ctype_digit((string) $value) || $value < $minimum || $value > $maximum) {
        throw new BadRequestHttpException('Invalid anomaly threshold.');
      }
      $options[$name] = (int) $value;
    }
    $scope = $input['anomaly_scope'] ?? 'work';
    if (!in_array($scope, ['work', 'collection'], TRUE)) {
      throw new BadRequestHttpException('Invalid anomaly scope.');
    }
    return ['period' => $period, 'group' => $group, 'filters' => array_filter($filters), 'anomaly_scope' => $scope] + $options;
  }

  /**
   * Saved per-work totals for one period, limited to public, matching works.
   *
   * Only this reads the work projection, so every aggregate report shares the
   * same publication, grant, and metadata rules. Publication is a saved flag,
   * so unfiltered reports read one index range without joining nodes; node
   * grants, when a module provides them, still join and filter every work.
   */
  public function works(array $criteria, bool $geography = FALSE): SelectInterface {
    $period = $this->periods()[$criteria['period']];
    $query = $this->database->select($geography ? 'lehigh_analytics_work_countries' : 'lehigh_analytics_work_totals', 'e');
    $query->condition('e.bucket', $this->rollup->bucket($criteria['period'], $period['start']));
    $query->condition('e.published', 1);
    if ($criteria['filters']) {
      $query->condition('e.nid', $this->matchingNodes($criteria), 'IN');
    }
    if ($this->moduleHandler->hasImplementations('node_grants')) {
      $this->joinNodes($query);
    }
    if (!(new AnonymousUserSession())->hasPermission('access content')) {
      $query->where('1 = 0');
    }
    return $query;
  }

  /**
   * Joins each work's default-language node as 'n', under anonymous grants.
   */
  private function joinNodes(SelectInterface $query): SelectInterface {
    if (!isset($query->getTables()['n'])) {
      $query->innerJoin('node_field_data', 'n', 'n.nid = e.nid AND n.default_langcode = 1');
      $this->anonymousAccess($query);
    }
    return $query;
  }

  /**
   * Reads raw events for staff anomaly analysis; never used by public reports.
   */
  public function events(array $criteria, ?array $period = NULL): SelectInterface {
    $period ??= $this->periods()[$criteria['period']];
    $branches = [];
    foreach (['node', 'media'] as $type) {
      $branch = $this->database->select('entity_metrics_data', 'd');
      $branch->fields('d', ['id', 'entity_type', 'entity_id', 'region_id', 'timestamp', 'session_id']);
      $branch->condition('d.entity_type', $type)->condition('d.cookie_set', 0)
        ->condition('d.timestamp', $period['start'], '>=')
        ->condition('d.timestamp', $period['end'], '<');
      if ($type === 'media') {
        $branch->innerJoin('media_field_data', 'm', 'm.mid = d.entity_id AND m.default_langcode = 1');
        $branch->innerJoin('media__field_media_of', 'mo', 'mo.entity_id = m.mid AND mo.langcode = m.langcode AND mo.deleted = 0');
        $branch->addField('mo', 'field_media_of_target_id', 'nid');
        $branch->distinct();
      }
      else {
        $branch->addField('d', 'entity_id', 'nid');
      }
      if ($criteria['filters']) {
        $branch->condition($type === 'node' ? 'd.entity_id' : 'mo.field_media_of_target_id', $this->matchingNodes($criteria), 'IN');
      }
      $branches[] = $branch;
    }
    $branches[0]->union($branches[1], 'ALL');
    $query = $this->database->select($branches[0], 'e');
    $query->innerJoin('node_field_data', 'n', 'n.nid = e.nid AND n.default_langcode = 1');
    $query->condition('n.type', 'islandora_object')->condition('n.status', 1);
    return $this->anonymousAccess($query);
  }

  /**
   * Applies anonymous grants to every node_field_data table in this query.
   *
   * Always anonymous, even when an administrator warms the cache. Core alters
   * joined subqueries but not those inside conditions such as IN or EXISTS.
   */
  private function anonymousAccess(SelectInterface $query): SelectInterface {
    $anonymous = new AnonymousUserSession();
    $query->addTag('node_access')->addMetaData('account', $anonymous)->addMetaData('base_table', 'node_field_data');
    if (!$anonymous->hasPermission('access content')) {
      $query->where('1 = 0');
    }
    return $query;
  }

  /**
   * Node IDs whose default-language metadata matches every filter.
   *
   * Driven from each field's target index, so a narrow filter reads only the
   * matching references rather than testing every node. Callers apply
   * publication separately.
   */
  private function matchingNodes(array $criteria): SelectInterface {
    $query = $this->database->select('node_field_data', 'd')->distinct();
    $query->addField('d', 'nid');
    $query->condition('d.default_langcode', 1);
    foreach ($criteria['filters'] as $field => $ids) {
      // Field names come only from validated field definitions.
      $alias = 'filter_' . $field;
      $query->innerJoin('node__' . $field, $alias, "$alias.entity_id = d.nid AND $alias.langcode = d.langcode AND $alias.deleted = 0");
      $query->condition("$alias.{$field}_target_id", $ids, 'IN');
    }
    return $query;
  }

  /**
   * Cache tags for live metadata, publication, and anonymous access.
   */
  public function metadataCacheTags(): array {
    return [
      'config:user.role.anonymous', 'node_access', 'node_list:islandora_object', 'taxonomy_term_list',
    ];
  }

  /**
   * Cache tags covering everything a report reads.
   *
   * Media attribution and counts change only through the background refresh,
   * which invalidates 'lehigh_analytics'; media saves need not clear results.
   */
  public function cacheTags(): array {
    return array_merge(['lehigh_analytics', 'config:lehigh_analytics.settings'], $this->metadataCacheTags());
  }

  /**
   * Render cache lifetime for a report.
   *
   * Period labels name today's date in the reporting timezone, and anomalies
   * read live events.
   */
  public function maxAge(string $report): int {
    if ($report === 'anomalies') {
      return 300;
    }
    $now = $this->time->getRequestTime();
    $timezone = new \DateTimeZone($this->configFactory->get('lehigh_analytics.settings')->get('timezone'));
    $midnight = (new \DateTimeImmutable('@' . $now))->setTimezone($timezone)->modify('tomorrow');
    return max(1, $midnight->getTimestamp() - $now);
  }

  /**
   * The criteria that can change a report, normalized for a stable cache key.
   */
  private function cacheKey(string $report, array $criteria): string {
    $filters = $criteria['filters'];
    ksort($filters);
    $filters = array_map(static function (array $ids): array {
      sort($ids);
      return $ids;
    }, $filters);
    $period = $this->periods()[$criteria['period']];
    $key = [$report, $filters];
    if ($report === 'anomalies') {
      // Anomalies read raw events up to the current day.
      $key[] = array_diff_key($criteria, ['filters' => TRUE]);
      $key[] = [$period['start'], gmdate('Y-m-d', $period['end'])];
    }
    else {
      // Saved totals are read by bucket, so the bucket fully identifies a period.
      $key[] = $this->rollup->bucket($criteria['period'], $period['start']);
      if ($report === 'types' || str_starts_with($report, 'top_types_')) {
        $key[] = $criteria['group'];
      }
    }
    return 'lehigh_analytics:v4:' . hash('sha256', serialize($key));
  }

  /**
   * Runs a report; field names come only from validated field definitions.
   */
  public function report(string $report, array $input): array {
    $criteria = $this->criteria($input);
    if (!isset(self::TITLES[$report])) {
      throw new BadRequestHttpException('Unknown usage report.');
    }
    if ($report !== 'anomalies' && !$this->rollup->status()['ready']) {
      throw new ServiceUnavailableHttpException(300, 'Usage data is being prepared. Please try again later.');
    }
    $cid = $this->cacheKey($report, $criteria);
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }
    $result = $this->calculate($report, $criteria);
    $now = $this->time->getRequestTime();
    $result['generated_at'] = $report === 'anomalies' ? $now : (int) $this->rollup->status()['updated'];
    // Tags carry freshness; the expiry is only a safety net.
    $this->cache->set($cid, $result, $now + ($report === 'anomalies' ? 300 : 86400), $this->cacheTags());
    return $result;
  }

  /**
   * Generates the explorer's default reports so first visits are cached.
   *
   * Cheap when nothing changed: each report is then a cache hit.
   */
  public function warm(): void {
    if (!$this->rollup->status()['ready']) {
      return;
    }
    foreach (self::PUBLIC_REPORTS as $report) {
      $this->report($report, ['period' => 'all']);
    }
  }

  /**
   * Calculates a single report from saved work totals.
   */
  private function calculate(string $report, array $criteria): array {
    if ($report === 'summary') {
      $coverage = $this->coverage($criteria);
      return [
        'headers' => ['Page views', 'Downloads', 'First matching event (UTC)', 'Last matching event (UTC)'],
        'rows' => [
          [
            (int) $coverage['page_views'],
            (int) $coverage['downloads'],
            $coverage['first'] === NULL ? 'No data' : gmdate('Y-m-d H:i:s', (int) $coverage['first']),
            $coverage['last'] === NULL ? 'No data' : gmdate('Y-m-d H:i:s', (int) $coverage['last']),
          ],
        ],
      ];
    }
    if (in_array($report, ['counter', 'acrl', 'anomalies'], TRUE)) {
      $analysis = new UsageAnalysis($this, $this->database, $this->time);
      return $report === 'anomalies' ? $analysis->anomalies($criteria) : $analysis->standards($report, $criteria);
    }
    $headers = ['Node ID', 'Document', 'Page views', 'Downloads'];
    $columns = ['nid', 'title', 'page_views', 'downloads'];
    switch ($report) {
      case 'types':
        $query = $this->joinTerms($this->database->select($this->workTypes($criteria), 'w'));
        $query->addExpression('SUM(w.page_views)', 'page_views');
        $query->addExpression('SUM(w.downloads)', 'downloads');
        $query->groupBy('t.tid')->groupBy('t.name');
        $query->orderBy('page_views', 'DESC')->orderBy('downloads', 'DESC')->orderBy('content_type');
        $headers = ['Content type', 'Page views', 'Downloads'];
        $columns = ['content_type', 'page_views', 'downloads'];
        break;

      case 'views':
      case 'downloads':
        $metric = $report === 'views' ? 'page_views' : 'downloads';
        $ranking = fn(): SelectInterface => $this->works($criteria)
          ->condition('e.document', 1)
          ->condition('e.' . $metric, 0, '>');
        // Find the 100th count by walking the rank index backwards; the
        // ranking then sorts only rows at or above it, breaking ties by node ID.
        $cutoff = $ranking()->range(99, 1)->orderBy('e.' . $metric, 'DESC');
        $cutoff->addField('e', $metric);
        $query = $ranking();
        if (($value = $cutoff->execute()->fetchField()) !== FALSE) {
          $query->condition('e.' . $metric, $value, '>=');
        }
        $this->joinNodes($query)->fields('n', ['nid', 'title']);
        $query->fields('e', ['page_views', 'downloads']);
        $query->orderBy('e.' . $metric, 'DESC')->orderBy('e.nid')->range(0, 100);
        break;

      case 'top_types_views':
      case 'top_types_downloads':
        $metric = $report === 'top_types_views' ? 'page_views' : 'downloads';
        $ranked = $this->joinTerms($this->database->select($this->workTypes($criteria, $metric), 'w'));
        $ranked->fields('w', ['nid', 'page_views', 'downloads']);
        $ranked->addExpression('ROW_NUMBER() OVER (PARTITION BY t.tid ORDER BY w.' . $metric . ' DESC, w.nid)', 'type_rank');
        $query = $this->database->select($ranked, 'ranked')->fields('ranked');
        // Titles only for ranked rows; the works already passed grants.
        $query->innerJoin('node_field_data', 'n', 'n.nid = ranked.nid AND n.default_langcode = 1');
        $query->addField('n', 'title');
        $query->condition('type_rank', 100, '<=')->orderBy('content_type')->orderBy('type_id')->orderBy('type_rank');
        $headers = array_merge(['Content type', 'Rank within type'], $headers);
        $columns = array_merge(['content_type', 'type_rank'], $columns);
        break;

      case 'collections':
        $query = $this->database->select($this->workCollections($criteria), 'w');
        $query->innerJoin('node_field_data', 'collection', 'collection.nid = w.collection_id AND collection.default_langcode = 1');
        $query->condition('collection.status', 1);
        // Collection labels obey anonymous grants too.
        $this->anonymousAccess($query);
        $query->addField('collection', 'nid', 'nid');
        $query->addField('collection', 'title', 'title');
        $query->addExpression('COUNT(*)', 'used_works');
        $query->addExpression('SUM(w.page_views)', 'page_views');
        $query->addExpression('SUM(w.downloads)', 'downloads');
        $query->groupBy('collection.nid')->groupBy('collection.title');
        $query->orderBy('page_views', 'DESC')->orderBy('downloads', 'DESC')->orderBy('collection.nid');
        $headers = ['Node ID', 'Collection', 'Works used', 'Page views', 'Downloads'];
        $columns = ['nid', 'title', 'used_works', 'page_views', 'downloads'];
        break;

      case 'countries':
        $query = $this->works($criteria, TRUE);
        $query->addField('e', 'country_code');
        $query->addExpression('SUM(e.page_views)', 'page_views');
        $query->addExpression('SUM(e.downloads)', 'downloads');
        $query->groupBy('e.country_code');
        $query->orderBy('page_views', 'DESC')->orderBy('downloads', 'DESC')->orderBy('country_code');
        $headers = ['Country / Territory', 'Page views', 'Downloads'];
        $columns = ['country_code', 'page_views', 'downloads'];
        break;

      default:
        throw new BadRequestHttpException('Unknown usage report.');
    }
    $rows = [];
    $ids = [];
    foreach ($query->execute() as $record) {
      $row = [];
      foreach ($columns as $column) {
        $row[] = in_array($column, ['nid', 'page_views', 'downloads', 'used_works', 'type_rank'], TRUE) ? (int) $record->$column : $record->$column;
      }
      $rows[] = $row;
      if ($report === 'types') {
        $ids[] = $record->type_id === NULL ? NULL : (int) $record->type_id;
      }
    }
    return ['headers' => $headers, 'rows' => $rows, 'ids' => $ids];
  }

  /**
   * One row per work and referenced term of the grouping field.
   *
   * Grouping on integer IDs deduplicates repeated references without
   * materializing the whole field table; term labels and publication are
   * joined afterwards. Works without a term get a NULL target.
   */
  private function workTypes(array $criteria, ?string $metric = NULL): SelectInterface {
    $field = $criteria['group'];
    $query = $this->works($criteria);
    $query->leftJoin('node__' . $field, 'f', 'f.entity_id = e.nid AND f.langcode = e.langcode AND f.deleted = 0');
    $query->addField('e', 'nid');
    $query->addField('f', $field . '_target_id', 'target_id');
    $query->addExpression('MAX(e.page_views)', 'page_views');
    $query->addExpression('MAX(e.downloads)', 'downloads');
    $query->groupBy('e.nid')->groupBy('f.' . $field . '_target_id');
    if ($metric) {
      $query->condition('e.document', 1)->condition('e.' . $metric, 0, '>');
    }
    return $query;
  }

  /**
   * Joins published term labels to workTypes() rows as 't'.
   *
   * Unpublished or missing terms leave 't' NULL and are reported as
   * Unspecified.
   */
  private function joinTerms(SelectInterface $query): SelectInterface {
    $query->leftJoin('taxonomy_term_field_data', 't', 't.tid = w.target_id AND t.default_langcode = 1 AND t.status = 1');
    $query->addField('t', 'tid', 'type_id');
    $query->addExpression("COALESCE(t.name, 'Unspecified')", 'content_type');
    return $query;
  }

  /**
   * One row per work and direct parent collection.
   *
   * Parents are limited to Collection-model nodes up front, so page-to-book
   * memberships are discarded before grouping. Parent publication and grants
   * are applied by the caller.
   */
  private function workCollections(array $criteria): SelectInterface {
    $collections = $this->database->select('node__field_model', 'cm');
    $collections->addField('cm', 'entity_id');
    $collections->innerJoin('taxonomy_term__field_external_uri', 'cu', 'cu.entity_id = cm.field_model_target_id AND cu.deleted = 0');
    $collections->condition('cm.deleted', 0)->condition('cu.field_external_uri_uri', self::COLLECTION_URI);
    $query = $this->works($criteria);
    $query->innerJoin('node__field_member_of', 'm', 'm.entity_id = e.nid AND m.langcode = e.langcode AND m.deleted = 0');
    $query->condition('m.field_member_of_target_id', $collections, 'IN');
    if (!empty($criteria['filters']['field_member_of'])) {
      $query->condition('m.field_member_of_target_id', $criteria['filters']['field_member_of'], 'IN');
    }
    $query->addField('e', 'nid');
    $query->addField('m', 'field_member_of_target_id', 'collection_id');
    $query->addExpression('MAX(e.page_views)', 'page_views');
    $query->addExpression('MAX(e.downloads)', 'downloads');
    $query->groupBy('e.nid')->groupBy('m.field_member_of_target_id');
    return $query;
  }

  /**
   * Joins direct, published parent collections without treating books as such.
   */
  public function joinCollections(SelectInterface $query): void {
    $query->innerJoin('node__field_member_of', 'membership', 'membership.entity_id = n.nid AND membership.langcode = n.langcode AND membership.deleted = 0');
    $query->innerJoin('node_field_data', 'collection', 'collection.nid = membership.field_member_of_target_id AND collection.default_langcode = 1 AND collection.status = 1');
    $model = $this->database->select('node__field_model', 'cm');
    $model->addExpression('1');
    $model->innerJoin('taxonomy_term__field_external_uri', 'cu', 'cu.entity_id = cm.field_model_target_id AND cu.deleted = 0');
    $model->where('cm.entity_id = collection.nid AND cm.langcode = collection.langcode');
    $model->condition('cm.deleted', 0)->condition('cu.field_external_uri_uri', self::COLLECTION_URI);
    $query->exists($model);
  }

  /**
   * Describes observed data coverage without implying continuous collection.
   */
  public function coverage(array $input): array {
    $query = $this->works($this->criteria($input));
    $query->addExpression('MIN(e.first_event)', 'first');
    $query->addExpression('MAX(e.last_event)', 'last');
    $query->addExpression("COALESCE(SUM(e.page_views), 0)", 'page_views');
    $query->addExpression("COALESCE(SUM(e.downloads), 0)", 'downloads');
    return (array) $query->execute()->fetchObject();
  }

}
