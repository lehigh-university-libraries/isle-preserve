<?php

namespace Drupal\lehigh_analytics;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Shared controls and accessible report tables for pages and placed blocks.
 */
final class ReportDisplay {

  use StringTranslationTrait;

  public function __construct(
    private readonly UsageReport $reports,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Builds the same period, grouping, metadata, and anomaly controls everywhere.
   */
  public function controls(array $input): array {
    $criteria = $this->reports->criteria($input);
    $fields = $this->reports->metadataFields();
    $form['period'] = [
      '#type' => 'select',
      '#title' => $this->t('Reporting period'),
      '#options' => array_map(static fn(array $period) => $period['label'], $this->reports->periods()),
      '#default_value' => $criteria['period'],
    ];
    $groups = [];
    foreach ($fields as $name => $field) {
      if ($field->getSetting('target_type') === 'taxonomy_term') {
        $groups[$name] = $field->getLabel();
      }
    }
    $form['group'] = [
      '#type' => 'select',
      '#title' => $this->t('Group content types by'),
      '#options' => $groups,
      '#default_value' => $criteria['group'],
    ];
    $form['filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Filter by metadata'),
      '#description' => $this->t('Values within a field are OR; different fields are AND. Parent collection matches direct membership. Metadata reflects current default-language records.'),
      '#open' => (bool) $criteria['filters'],
      '#tree' => TRUE,
    ];
    foreach ($fields as $name => $field) {
      $target = $field->getSetting('target_type');
      $form['filters'][$name] = [
        '#type' => 'entity_autocomplete',
        '#title' => $field->getLabel(),
        '#target_type' => $target,
        '#tags' => TRUE,
        '#selection_settings' => array_filter([
          'target_bundles' => $field->getSetting('handler_settings')['target_bundles'] ?? NULL,
        ]),
        '#default_value' => $this->entityTypeManager->getStorage($target)->loadMultiple($criteria['filters'][$name] ?? []),
        '#weight' => match ($name) {
          'field_member_of' => -3,
          'field_model' => -2,
          'field_genre' => -1,
          default => 0,
        },
      ];
    }
    $form['anomaly'] = ['#type' => 'details', '#title' => $this->t('Anomaly detection settings'), '#tree' => TRUE];
    $form['anomaly']['anomaly_scope'] = [
      '#type' => 'select',
      '#title' => $this->t('Detect spikes for'),
      '#options' => ['work' => $this->t('Individual works'), 'collection' => $this->t('Collections (direct members)')],
      '#default_value' => $criteria['anomaly_scope'],
    ];
    foreach ([
      'baseline_days' => [$this->t('Prior days in baseline'), 7, 90],
      'spike_multiplier' => [$this->t('Minimum multiple of baseline'), 2, 100],
      'minimum_views' => [$this->t('Minimum daily page views'), 1, 1000000],
      'session_share' => [$this->t('Concentrated traffic threshold (% in one session)'), 1, 100],
    ] as $name => [$label, $min, $max]) {
      $form['anomaly'][$name] = [
        '#type' => 'number',
        '#title' => $label,
        '#min' => $min,
        '#max' => $max,
        '#step' => 1,
        '#required' => TRUE,
        '#default_value' => $criteria[$name],
      ];
    }
    return $form;
  }

  /**
   * Converts validated autocomplete values to portable report criteria.
   */
  public function submitted(array $values): array {
    $values = ($values['anomaly'] ?? []) + $values;
    $filters = [];
    foreach ($values['filters'] ?? [] as $field => $selected) {
      if ($selected) {
        $filters[$field] = array_column($selected, 'target_id');
      }
    }
    $values['filters'] = $filters;
    return $this->reports->criteria($values);
  }

  /**
   * Provides mapping download actions with the report's exact scope.
   */
  public function downloads(array $criteria): array {
    $build = ['#type' => 'container'];
    foreach (['counter', 'acrl'] as $report) {
      $build[$report] = Link::fromTextAndUrl($this->t('Download @name (CSV)', ['@name' => UsageReport::TITLES[$report]]), Url::fromRoute('lehigh_analytics.public_export', ['report' => $report], ['query' => $criteria]))->toRenderable();
      $build[$report]['#prefix'] = '<p>';
      $build[$report]['#suffix'] = '</p>';
    }
    $build['note'] = ['#plain_text' => $this->t('These are mapping/preparation worksheets, not certified COUNTER reports or an ACRL submission. Counts and missing requirements are labeled in each export.')];
    return $build;
  }

  /**
   * Renders a report without exposing visitor identifiers.
   */
  public function build(string $report, array $input): array {
    $criteria = $this->reports->criteria($input);
    $data = $this->reports->report($report, $criteria);
    $build = ['#cache' => ['max-age' => 0, 'contexts' => ['user.permissions']]];
    $build['period'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->reports->periods()[$criteria['period']]['label'],
    ];
    $build['export'] = Link::fromTextAndUrl($this->t('Download CSV'), Url::fromRoute(($report === 'anomalies' ? 'lehigh_analytics.export' : 'lehigh_analytics.public_export'), ['report' => $report], ['query' => $criteria]))->toRenderable();
    $build['generated'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Generated @time UTC. Results may be cached for up to five minutes.', ['@time' => gmdate('Y-m-d H:i:s', $data['generated_at'])]),
    ];
    $id_column = array_search('Node ID', $data['headers'], TRUE);
    $rows = [];
    foreach ($data['rows'] as $index => $row) {
      $cells = array_map(static fn($value) => ['data' => ['#plain_text' => (string) $value]], $row);
      if ($id_column !== FALSE) {
        $cells[$id_column + 1] = ['data' => Link::createFromRoute($row[$id_column + 1], 'entity.node.canonical', ['node' => $row[$id_column]])->toRenderable()];
      }
      array_unshift($cells, $index + 1);
      $rows[] = $cells;
    }
    $build['table'] = [
      '#type' => 'table',
      '#header' => array_merge([$this->t('Row')], $data['headers']),
      '#rows' => $rows,
      '#empty' => $this->t('No matching records for this period and these filters.'),
    ];
    if ($report === 'anomalies') {
      $build['table']['#empty'] = $this->t('No qualifying spikes. This can also mean there is insufficient recorded history for the baseline.');
    }
    $note = match ($report) {
      'summary' => $this->t('First and last matching events do not prove continuous tracking coverage. Totals are unduplicated; counts in multivalued content-type and collection reports may overlap.'),
      'collections' => $this->t('Counts cover direct members of published Collection-model nodes. A work belonging to several collections contributes to each. Do not sum these rows as a repository total. Nested descendants and collection landing-page views are not rolled up.'),
      'anomalies' => $this->t('Complete UTC days only. Flags require at least @minimum page views and @multiple times the mean of the preceding @days days (including zero-use days). History before the selected period is included in the baseline. A full baseline after the first recorded view and item creation is required; collection gaps cannot be inferred. A session share of @share% is flagged as concentrated. Missing sessions are reported separately. These are review signals, not confirmed bots; no traffic is removed.', [
        '@minimum' => $criteria['minimum_views'],
        '@multiple' => $criteria['spike_multiplier'],
        '@days' => $criteria['baseline_days'],
        '@share' => $criteria['session_share'],
      ]),
      default => $this->t('Source: Entity Metrics recorded events on currently published Islandora objects, excluding staff-flagged events. Downloads represent media file requests, not verified completed downloads. Counts are not verified human readership. Multivalued content types can overlap.'),
    };
    $build['note'] = ['#type' => 'html_tag', '#tag' => 'p', '#value' => $note];
    return $build;
  }

}
