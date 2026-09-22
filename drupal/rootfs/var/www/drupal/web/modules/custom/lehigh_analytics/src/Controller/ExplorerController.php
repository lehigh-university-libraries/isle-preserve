<?php

namespace Drupal\lehigh_analytics\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Url;
use Drupal\lehigh_analytics\UsageReport;
use Drupal\lehigh_analytics\UsageRollup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Public exploration of anonymous-accessible aggregate usage.
 */
final class ExplorerController extends ControllerBase {

  public function __construct(
    private readonly UsageReport $reports,
    private readonly UsageRollup $rollup,
    private readonly Connection $database,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('lehigh_analytics.reports'),
      $container->get('lehigh_analytics.rollup'),
      $container->get('database'),
      $container->get('cache.default'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Renders the shell without running usage queries.
   */
  public function page(): array {
    $fields = [];
    foreach ($this->reports->metadataFields() as $name => $definition) {
      $fields[$name] = match ($name) {
        'field_model' => $this->t('Islandora Model'),
        'field_member_of' => $this->t('Parent collection'),
        default => $definition->getLabel(),
      };
    }
    return [
      '#theme' => 'lehigh_usage_explorer',
      '#periods' => $this->reports->periods(),
      '#fields' => $fields,
      '#attached' => [
        'library' => ['lehigh_analytics/explorer'],
        'drupalSettings' => [
          'lehighAnalytics' => [
            'dataUrl' => Url::fromRoute('lehigh_analytics.data')->toString(),
            'optionsUrl' => Url::fromRoute('lehigh_analytics.options', ['field' => 'field_model'])->toString(),
            'fieldsUrl' => Url::fromRoute('lehigh_analytics.fields')->toString(),
            'exportUrl' => Url::fromRoute('lehigh_analytics.public_export', ['report' => 'views'])->toString(),
            'nodeUrl' => Url::fromUri('base:node/NODE')->toString(),
          ],
        ],
      ],
      '#cache' => ['max-age' => 300, 'tags' => ['config:lehigh_analytics.settings']],
    ];
  }

  /**
   * Supplies one report so cold queries do not share a single request timeout.
   */
  public function data(Request $request): JsonResponse {
    $report = $request->query->all()['report'] ?? 'summary';
    if (!is_string($report) || !in_array($report, UsageReport::PUBLIC_REPORTS, TRUE)) {
      throw new BadRequestHttpException('Invalid public usage report.');
    }
    $status = $this->rollup->status();
    if (!$status['ready']) {
      return new JsonResponse([
        'message' => 'Usage data is being prepared. Please try again later.',
      ], 503, ['Retry-After' => '300', 'Cache-Control' => 'no-store']);
    }
    $criteria = $this->reports->criteria($request->query->all());
    // Content type is always the Islandora Model on the public explorer.
    $criteria['group'] = 'field_model';
    $data = [
      'updated' => gmdate('c', (int) $status['updated']),
      'period' => $this->reports->periods()[$criteria['period']]['label'],
    ];
    $data[$report] = $this->reports->report($report, $criteria);
    return new JsonResponse($data, 200, ['Cache-Control' => 'no-store']);
  }

  /**
   * Metadata fields with at least one matching value under the other filters.
   */
  public function fields(Request $request): JsonResponse {
    $criteria = $this->reports->criteria($request->query->all());
    $available = [];
    foreach (array_keys($this->reports->metadataFields()) as $field) {
      if ($this->suggestions($field, $criteria, '', 0, 1)) {
        $available[] = $field;
      }
    }
    return new JsonResponse($available, 200, ['Cache-Control' => 'no-store']);
  }

  /**
   * Searchable, paged suggestions from works with matching recorded usage.
   */
  public function options(string $field, Request $request): JsonResponse {
    $fields = $this->reports->metadataFields();
    $search = $request->query->get('q', '');
    $offset = $request->query->get('offset', '0');
    if (!isset($fields[$field]) || !is_string($search) || mb_strlen($search) > 100
      || !is_scalar($offset) || !ctype_digit((string) $offset) || (float) $offset > 2147483647) {
      throw new BadRequestHttpException('Invalid metadata search.');
    }
    $criteria = $this->reports->criteria($request->query->all());
    $rows = $this->suggestions($field, $criteria, trim($search), (int) $offset, 51);
    return new JsonResponse([
      'rows' => array_slice($rows, 0, 50),
      'more' => count($rows) > 50,
    ], 200, ['Cache-Control' => 'no-store']);
  }

  /**
   * Candidates must contribute usage; values within the same field remain OR.
   */
  private function suggestions(string $field, array $criteria, string $search, int $offset, int $limit): array {
    unset($criteria['filters'][$field]);
    $period = $this->reports->periods()[$criteria['period']];
    $key = [$field, $criteria, $period['start'], $search, $offset, $limit];
    $cid = 'lehigh_analytics:options:v2:' . hash('sha256', serialize($key));
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }
    $used = $this->reports->works($criteria);
    $used->addExpression('1');
    $used->innerJoin('node__' . $field, 'f', 'f.entity_id = e.nid AND f.langcode = e.langcode AND f.deleted = 0');
    $used->condition($used->orConditionGroup()->condition('e.page_views', 0, '>')->condition('e.downloads', 0, '>'));
    $fields = $this->reports->metadataFields();
    if ($fields[$field]->getSetting('target_type') === 'node') {
      $query = $this->database->select('node_field_data', 'target')->condition('target.default_langcode', 1);
      $id = 'nid';
      $label = 'title';
    }
    else {
      $query = $this->database->select('taxonomy_term_field_data', 'target')->condition('target.default_langcode', 1);
      $id = 'tid';
      $label = 'name';
    }
    $query->condition('target.status', 1);
    $used->where('f.' . $field . '_target_id = target.' . $id);
    // Core alters only subqueries used as tables, so apply grants here.
    $used->preExecute();
    $query->exists($used);
    if ($id === 'nid') {
      $this->anonymous($query);
      // Parent collection choices exclude page-to-book memberships.
      $model = $this->database->select('node__field_model', 'm');
      $model->addExpression('1');
      $model->innerJoin('taxonomy_term__field_external_uri', 'u', 'u.entity_id = m.field_model_target_id AND u.deleted = 0');
      $model->where('m.entity_id = target.nid AND m.langcode = target.langcode');
      $model->condition('m.deleted', 0)->condition('u.field_external_uri_uri', UsageReport::COLLECTION_URI);
      $query->exists($model);
    }
    $query->addField('target', $id, 'id');
    $query->addField('target', $label, 'label');
    if (trim($search) !== '') {
      $query->condition('target.' . $label, '%' . $this->database->escapeLike(trim($search)) . '%', 'LIKE');
    }
    $rows = $query->orderBy('target.' . $label)->orderBy('target.' . $id)->range($offset, $limit)->execute()->fetchAll();
    $this->cache->set($cid, $rows, $this->time->getRequestTime() + 86400, $this->reports->cacheTags());
    return $rows;
  }

  /**
   * Applies anonymous node grants to a query's node_field_data tables.
   */
  private function anonymous(SelectInterface $query): SelectInterface {
    $anonymous = new AnonymousUserSession();
    $query->addTag('node_access')->addMetaData('account', $anonymous)->addMetaData('base_table', 'node_field_data');
    if (!$anonymous->hasPermission('access content')) {
      $query->where('1 = 0');
    }
    return $query;
  }

}
