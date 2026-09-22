<?php

namespace Drupal\lehigh_analytics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Public exploration of anonymous-accessible aggregate usage.
 */
final class ExplorerController extends ControllerBase {

  /**
   * Renders the shell without running usage queries.
   */
  public function page(): array {
    $reports = \Drupal::service('lehigh_analytics.reports');
    $fields = [];
    foreach ($reports->metadataFields() as $name => $definition) {
      $fields[$name] = match ($name) {
        'field_model' => $this->t('Islandora Model'),
        'field_member_of' => $this->t('Parent collection'),
        default => $definition->getLabel(),
      };
    }
    return [
      '#theme' => 'lehigh_usage_explorer',
      '#periods' => $reports->periods(),
      '#fields' => $fields,
      '#attached' => [
        'library' => ['lehigh_analytics/explorer'],
        'drupalSettings' => [
    'lehighAnalytics' => [
      'dataUrl' => Url::fromRoute('lehigh_analytics.data')->toString(),
      'optionsUrl' => Url::fromRoute('lehigh_analytics.options', ['field' => 'field_model'])->toString(),
      'exportUrl' => Url::fromRoute('lehigh_analytics.public_export', ['report' => 'views'])->toString(),
      'nodeUrl' => Url::fromUri('base:node/NODE')->toString(),
    ]
],
      ],
      '#cache' => ['max-age' => 300, 'tags' => ['config:lehigh_analytics.settings']],
    ];
  }

  /**
   * Supplies six compact reports from saved totals, never raw event history.
   */
  public function data(Request $request): JsonResponse {
    $status = \Drupal::service('lehigh_analytics.rollup')->status();
    if (!$status['ready']) {
      return new JsonResponse([
        'message' => 'Usage data is being prepared. Please try again later.',
      ], 503, ['Retry-After' => '300', 'Cache-Control' => 'no-store']);
    }
    $reports = \Drupal::service('lehigh_analytics.reports');
    $criteria = $reports->criteria($request->query->all());
    // Content type is always the Islandora Model on the public explorer.
    $criteria['group'] = 'field_model';
    $data = [
      'updated' => gmdate('c', (int) $status['updated']),
      'period' => $reports->periods()[$criteria['period']]['label'],
    ];
    foreach (['summary', 'types', 'views', 'downloads', 'collections', 'countries'] as $report) {
      $data[$report] = $reports->report($report, $criteria);
    }
    return new JsonResponse($data, 200, ['Cache-Control' => 'no-store']);
  }

  /**
   * Bounded metadata suggestions; node labels require anonymous access.
   */
  public function options(string $field, Request $request): JsonResponse {
    $fields = \Drupal::service('lehigh_analytics.reports')->metadataFields();
    $search = $request->query->get('q', '');
    if (!isset($fields[$field]) || !is_string($search) || mb_strlen($search) > 100) {
      throw new BadRequestHttpException('Invalid metadata search.');
    }
    $db = \Drupal::database();
    $query = $db->select('node__' . $field, 'f')->distinct();
    $query->innerJoin('node_field_data', 'n', 'n.nid = f.entity_id AND n.langcode = f.langcode AND n.default_langcode = 1');
    $query->condition('n.status', 1)->condition('n.type', 'islandora_object')->condition('f.deleted', 0);
    if ($fields[$field]->getSetting('target_type') === 'node') {
      $query->innerJoin('node_field_data', 'target', 'target.nid = f.' . $field . '_target_id AND target.default_langcode = 1 AND target.status = 1');
      $id = 'nid';
      $label = 'title';
    }
    else {
      $query->innerJoin('taxonomy_term_field_data', 'target', 'target.tid = f.' . $field . '_target_id AND target.default_langcode = 1');
      $id = 'tid';
      $label = 'name';
      $query->condition('target.status', 1);
    }
    $query->addTag('node_access')->addMetaData('account', new AnonymousUserSession())->addMetaData('base_table', 'node_field_data');
    if (!(new AnonymousUserSession())->hasPermission('access content')) {
      $query->where('1 = 0');
    }
    $query->addField('target', $id, 'id');
    $query->addField('target', $label, 'label');
    if ($search !== '') {
      $query->condition('target.' . $label, '%' . $db->escapeLike($search) . '%', 'LIKE');
    }
    $rows = $query->orderBy('target.' . $label)->orderBy('target.' . $id)->range(0, 50)->execute()->fetchAll();
    return new JsonResponse($rows, 200, ['Cache-Control' => 'no-store']);
  }

}
