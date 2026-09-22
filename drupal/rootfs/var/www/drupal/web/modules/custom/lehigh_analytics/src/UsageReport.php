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
    'countries' => 'Usage by country',
    'top_types_views' => 'Top 100 documents per content type by page views',
    'top_types_downloads' => 'Top 100 documents per content type by downloads',
    'collections' => 'Usage by collection',
    'counter' => 'COUNTER mapping worksheet',
    'acrl' => 'ACRL preparation worksheet',
    'anomalies' => 'Page-view spikes for review',
  ];

  public function __construct(
    private readonly Connection $database,
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly CacheBackendInterface $cache,
    private readonly UsageRollup $rollup,
  ) {}

  /**
   * Finds filterable Islandora references, without hard-coded term IDs.
   */
  public function metadataFields(): array {
    $fields = [];
    foreach ($this->fieldManager->getFieldDefinitions('node', 'islandora_object') as $name => $field) {
      if (!$field->getFieldStorageDefinition()->isBaseField()
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
   * Builds one row per event/work, resolving media before aggregating.
   */
  public function events(array $criteria, ?array $period = NULL, bool $aggregate = FALSE, bool $geography = FALSE): SelectInterface {
    $period ??= $this->periods()[$criteria['period']];
    $branches = [];
    foreach (['node', 'media'] as $type) {
      $branch = $this->database->select($aggregate ? 'lehigh_analytics_totals' : 'entity_metrics_data', 'd');
      if ($aggregate) {
        $branch->fields('d', ['entity_type', 'entity_id']);
        $branch->addExpression('SUM(d.event_count)', 'event_count');
        $branch->addExpression('MIN(d.first_event)', 'first_event');
        $branch->addExpression('MAX(d.last_event)', 'last_event');
        $branch->groupBy('d.entity_type')->groupBy('d.entity_id');
        if ($geography) {
          $branch->addField('d', 'region_id');
          $branch->groupBy('d.region_id');
        }
      }
      else {
        $branch->fields('d', ['id', 'entity_type', 'entity_id', 'region_id', 'timestamp', 'session_id']);
      }
      $branch->condition('d.entity_type', $type);
      if ($aggregate) {
        $branch->condition('d.bucket', $this->rollup->bucket($criteria['period'], $period['start']));
      }
      else {
        $branch->condition('d.cookie_set', 0)
          ->condition('d.timestamp', $period['start'], '>=')
          ->condition('d.timestamp', $period['end'], '<');
      }
      if ($type === 'media') {
        // DISTINCT prevents translations/repeated references multiplying events.
        $parents = $this->database->select('media__field_media_of', 'mo')->distinct();
        $parents->fields('mo', ['entity_id', 'field_media_of_target_id']);
        $parents->condition('mo.deleted', 0);
        $parents->innerJoin('media_field_data', 'm', 'm.mid = mo.entity_id AND m.langcode = mo.langcode AND m.default_langcode = 1');
        $branch->innerJoin($parents, 'parents', 'parents.entity_id = d.entity_id');
        $branch->addField('parents', 'field_media_of_target_id', 'nid');
        if ($aggregate) {
          $branch->groupBy('parents.field_media_of_target_id');
        }
      }
      else {
        $branch->addField('d', 'entity_id', 'nid');
      }
      if ($criteria['filters']) {
        // Apply selective metadata filters before counting the event history.
        $branch->condition($type === 'node' ? 'd.entity_id' : 'parents.field_media_of_target_id', $this->matchingNodes($criteria), 'IN');
      }
      $branches[] = $branch;
    }
    $branches[0]->union($branches[1], 'ALL');
    $query = $this->database->select($branches[0], 'e');
    $query->innerJoin('node_field_data', 'n', 'n.nid = e.nid AND n.default_langcode = 1');
    $query->condition('n.type', 'islandora_object')->condition('n.status', 1);
    // Always use anonymous grants, even when an administrator warms the cache.
    $query->addTag('node_access')->addMetaData('account', new AnonymousUserSession())->addMetaData('base_table', 'node_field_data');
    if (!(new AnonymousUserSession())->hasPermission('access content')) {
      $query->where('1 = 0');
    }
    return $query;
  }

  /**
   * Identifies filtered works before their events are aggregated.
   */
  private function matchingNodes(array $criteria): SelectInterface {
    $query = $this->database->select('node_field_data', 'n');
    $query->addField('n', 'nid');
    $query->condition('n.default_langcode', 1)->condition('n.type', 'islandora_object')->condition('n.status', 1);
    foreach ($criteria['filters'] as $field => $ids) {
      $match = $this->database->select('node__' . $field, 'f');
      $match->addExpression('1');
      $match->where('f.entity_id = n.nid AND f.langcode = n.langcode');
      $match->condition('f.deleted', 0)->condition('f.' . $field . '_target_id', $ids, 'IN');
      $query->exists($match);
    }
    return $query;
  }

  /**
   * Runs a report; field names come only from validated field definitions.
   */
  public function report(string $report, array $input): array {
    $criteria = $this->criteria($input);
    if ($report !== 'anomalies' && !$this->rollup->status()['ready']) {
      throw new ServiceUnavailableHttpException(300, 'Usage data is being prepared. Please try again later.');
    }
    if (!isset(self::TITLES[$report])) {
      throw new BadRequestHttpException('Unknown usage report.');
    }
    $period = $this->periods()[$criteria['period']];
    // Include calendar boundaries, but not a changing YTD end on every request.
    $boundary = [
      $period['start'],
      in_array($criteria['period'], ['fiscal', 'calendar'], TRUE) ? $period['end'] : gmdate('Y-m-d', $period['end']),
    ];
    $cid = 'lehigh_analytics:v2:' . hash('sha256', serialize([$report, $criteria, $boundary]));
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }
    $result = $this->calculate($report, $criteria);
    $result['generated_at'] = $report === 'anomalies' ? $this->time->getRequestTime() : (int) $this->rollup->status()['updated'];
    $this->cache->set($cid, $result, $this->time->getRequestTime() + 300, [
      'config:user.role.anonymous', 'node_access', 'lehigh_analytics', 'node_list', 'media_list', 'taxonomy_term_list', 'config:lehigh_analytics.settings',
    ]);
    return $result;
  }

  /**
   * Calculates a single report, aggregating events before joining metadata.
   */
  private function calculate(string $report, array $criteria): array {
    if ($report === 'summary') {
      $coverage = $this->coverage($criteria);
      return [
        'headers' => ['Page views', 'Downloads', 'First matching event (UTC)', 'Last matching event (UTC)'],
        'rows' => [[
          (int) $coverage['page_views'], (int) $coverage['downloads'],
          $coverage['first'] === NULL ? 'No data' : gmdate('Y-m-d H:i:s', (int) $coverage['first']),
          $coverage['last'] === NULL ? 'No data' : gmdate('Y-m-d H:i:s', (int) $coverage['last']),
        ]
],
      ];
    }
    if (in_array($report, ['counter', 'acrl', 'anomalies'], TRUE)) {
      $analysis = new UsageAnalysis($this, $this->database, $this->time);
      return $report === 'anomalies' ? $analysis->anomalies($criteria) : $analysis->standards($report, $criteria);
    }
    $query = $this->events($criteria, NULL, TRUE, $report === 'countries');
    $query->addExpression("SUM(CASE WHEN e.entity_type = 'node' THEN e.event_count ELSE 0 END)", 'page_views');
    $query->addExpression("SUM(CASE WHEN e.entity_type = 'media' THEN e.event_count ELSE 0 END)", 'downloads');
    switch ($report) {
      case 'types':
        $field = $criteria['group'];
        $query->leftJoin($this->references($field), 'f', 'f.entity_id = n.nid AND f.langcode = n.langcode');
        $query->leftJoin('taxonomy_term_field_data', 't', 't.tid = f.' . $field . '_target_id AND t.default_langcode = 1 AND t.status = 1');
        $query->addExpression("COALESCE(t.name, 'Unspecified')", 'content_type');
        $query->groupBy('t.tid')->groupBy('t.name');
        $query->addField('t', 'tid', 'type_id');
        $headers = ['Content type', 'Page views', 'Downloads'];
        $columns = ['content_type', 'page_views', 'downloads'];
        $query->orderBy('page_views', 'DESC')->orderBy('downloads', 'DESC')->orderBy('content_type');
        break;

      case 'views':
      case 'downloads':
      case 'top_types_views':
      case 'top_types_downloads':
        $this->documentsOnly($query);
        $query->fields('n', ['nid', 'title']);
        $query->groupBy('n.nid')->groupBy('n.title');
        $is_views = str_ends_with($report, 'views');
        $metric = $is_views ? 'page_views' : 'downloads';
        $query->having('SUM(CASE WHEN e.entity_type = :rank_type THEN e.event_count ELSE 0 END) > 0', [':rank_type' => $is_views ? 'node' : 'media']);
        $query->orderBy($metric, 'DESC')->orderBy('n.nid')->range(0, 100);
        $headers = ['Node ID', 'Document', 'Page views', 'Downloads'];
        $columns = ['nid', 'title', 'page_views', 'downloads'];
        if (str_starts_with($report, 'top_types_')) {
          $query->range();
          $field = $criteria['group'];
          $query->leftJoin($this->references($field), 'f', 'f.entity_id = n.nid AND f.langcode = n.langcode');
          $query->leftJoin('taxonomy_term_field_data', 't', 't.tid = f.' . $field . '_target_id AND t.default_langcode = 1 AND t.status = 1');
          $query->addField('t', 'tid', 'type_id');
          $query->addExpression("COALESCE(t.name, 'Unspecified')", 'content_type');
          $query->groupBy('t.tid')->groupBy('t.name');
          $ranked = $this->database->select($query, 'totals');
          $ranked->fields('totals');
          $ranked->addExpression('ROW_NUMBER() OVER (PARTITION BY type_id ORDER BY ' . $metric . ' DESC, nid)', 'type_rank');
          $query = $this->database->select($ranked, 'ranked')->fields('ranked');
          $query->condition('type_rank', 100, '<=')->orderBy('content_type')->orderBy('type_id')->orderBy('type_rank');
          $headers = array_merge(['Content type', 'Rank within type'], $headers);
          $columns = array_merge(['content_type', 'type_rank'], $columns);
        }
        break;

      case 'collections':
        $this->joinCollections($query);
        if (!empty($criteria['filters']['field_member_of'])) {
          $query->condition('collection.nid', $criteria['filters']['field_member_of'], 'IN');
        }
        $query->addField('collection', 'nid', 'nid');
        $query->addField('collection', 'title', 'title');
        $query->addExpression('COUNT(DISTINCT n.nid)', 'used_works');
        $query->groupBy('collection.nid')->groupBy('collection.title');
        $query->orderBy('page_views', 'DESC')->orderBy('downloads', 'DESC')->orderBy('collection.nid');
        $headers = ['Node ID', 'Collection', 'Works used', 'Page views', 'Downloads'];
        $columns = ['nid', 'title', 'used_works', 'page_views', 'downloads'];
        break;

      case 'countries':
        $query->leftJoin('entity_metrics_regions', 'r', 'r.id = e.region_id');
        $country = "COALESCE(NULLIF(UPPER(TRIM(r.country)), ''), 'Unknown')";
        $query->addExpression($country, 'country_code');
        $query->groupBy('country_code');
        $query->orderBy('page_views', 'DESC')->orderBy('downloads', 'DESC')->orderBy('country_code');
        $headers = ['Country code', 'Page views', 'Downloads'];
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
   * Excludes collection landing pages and component pages from item reports.
   */
  public function documentsOnly(SelectInterface $query): void {
    $excluded = $this->database->select('node__field_model', 'model');
    $excluded->addExpression('1');
    $excluded->innerJoin('taxonomy_term__field_external_uri', 'uri', 'uri.entity_id = model.field_model_target_id AND uri.deleted = 0');
    $excluded->where('model.entity_id = n.nid AND model.langcode = n.langcode');
    $excluded->condition('model.deleted', 0)->condition('uri.field_external_uri_uri', [
      'http://purl.org/dc/dcmitype/Collection',
      'http://id.loc.gov/ontologies/bibframe/part',
    ], 'IN');
    $query->notExists($excluded);
  }

  /**
   * Joins direct, published parent collections without treating books as such.
   */
  public function joinCollections(SelectInterface $query): void {
    $query->innerJoin($this->references('field_member_of'), 'membership', 'membership.entity_id = n.nid AND membership.langcode = n.langcode');
    $query->innerJoin('node_field_data', 'collection', 'collection.nid = membership.field_member_of_target_id AND collection.default_langcode = 1 AND collection.status = 1');
    $model = $this->database->select('node__field_model', 'cm');
    $model->addExpression('1');
    $model->innerJoin('taxonomy_term__field_external_uri', 'cu', 'cu.entity_id = cm.field_model_target_id AND cu.deleted = 0');
    $model->where('cm.entity_id = collection.nid AND cm.langcode = collection.langcode');
    $model->condition('cm.deleted', 0)->condition('cu.field_external_uri_uri', 'http://purl.org/dc/dcmitype/Collection');
    $query->exists($model);
  }

  /**
   * Deduplicates field references before joining pre-aggregated event counts.
   */
  private function references(string $field): SelectInterface {
    return $this->database->select('node__' . $field, 'reference')->distinct()
      ->fields('reference', ['entity_id', 'langcode', $field . '_target_id'])
      ->condition('deleted', 0);
  }

  /**
   * Describes observed data coverage without implying continuous collection.
   */
  public function coverage(array $input): array {
    $query = $this->events($this->criteria($input), NULL, TRUE);
    $query->addExpression('MIN(e.first_event)', 'first');
    $query->addExpression('MAX(e.last_event)', 'last');
    $query->addExpression("COALESCE(SUM(CASE WHEN e.entity_type = 'node' THEN e.event_count ELSE 0 END), 0)", 'page_views');
    $query->addExpression("COALESCE(SUM(CASE WHEN e.entity_type = 'media' THEN e.event_count ELSE 0 END), 0)", 'downloads');
    return (array) $query->execute()->fetchObject();
  }

}
