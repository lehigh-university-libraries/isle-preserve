<?php

namespace Drupal\lehigh_analytics;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Reporting crosswalks and review signals, without changing source events.
 */
final class UsageAnalysis {

  public function __construct(
    private readonly UsageReport $reports,
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Produces explicit source mappings, never counterfeit compliance reports.
   */
  public function standards(string $standard, array $criteria): array {
    $query = $this->reports->events($criteria, NULL, TRUE);
    $this->reports->documentsOnly($query);
    $query->addExpression("COALESCE(SUM(CASE WHEN e.entity_type = 'node' THEN e.event_count ELSE 0 END), 0)", 'views');
    $query->addExpression("COALESCE(SUM(CASE WHEN e.entity_type = 'media' THEN e.event_count ELSE 0 END), 0)", 'downloads');
    $counts = $query->execute()->fetchObject();
    $views = (int) $counts->views;
    $downloads = (int) $counts->downloads;
    $status = 'Provisional mapping only: bot exclusion, double-click filtering, successful full-content delivery, and tracking coverage are unverified.';
    $rows = $standard === 'counter' ? [
      [
        'COUNTER', 'Total_Item_Investigations', $views + $downloads,
        'Recorded node visits plus media file requests for works', $status,
      ],
      [
        'COUNTER', 'Total_Item_Requests', $downloads, 'Recorded media file requests for works', $status,
      ],
      [
        'COUNTER', 'Unique_Item_Investigations', 'Unavailable',
        'Requires validated item/session deduplication and exclusions', 'Not calculated; do not substitute zero.',
      ],
      [
        'COUNTER', 'Unique_Item_Requests', 'Unavailable',
        'Requires validated item/session deduplication and exclusions', 'Not calculated; do not substitute zero.',
      ],
    ] : [
      [
        'ACRL 2025 instructions, Q51', 'Digital Item Usage from Institutional Repositories', $downloads,
        'Downloads: Entity Metrics media file requests for works',
        'Preparation only: confirm fiscal year and file scope; human-only usage is unverified.',
      ],
      [
        'Local context', 'Repository work page views', $views,
        'Recorded node visits; do not add to full-content requests',
        'Supporting context, not an ACRL submission field.',
      ],
    ];
    return [
      'headers' => ['Standard', 'Target metric', 'Candidate count', 'Source mapping', 'Readiness'],
      'rows' => $rows,
    ];
  }

  /**
   * Flags daily view spikes and shows session concentration for human review.
   */
  public function anomalies(array $criteria): array {
    $headers = [
      'Node ID', $criteria['anomaly_scope'] === 'collection' ? 'Collection' : 'Document',
      'Day (UTC)', 'Page views', 'Baseline daily mean', 'Times baseline',
      'Known sessions', 'Largest session share (%)', 'Views without session', 'Review signal',
    ];
    $period = $this->reports->periods()[$criteria['period']];
    $window = $criteria['baseline_days'];
    // Only complete UTC days are comparable. Exclude partial boundary days.
    $first_day = (int) ceil($period['start'] / 86400);
    $end_day = (int) floor(min($period['end'], $this->time->getRequestTime()) / 86400);
    $earliest = $this->database->select('entity_metrics_data', 'd');
    $earliest->condition('entity_type', 'node')->condition('cookie_set', 0);
    $earliest->addExpression('MIN(timestamp)');
    $first_event = $earliest->execute()->fetchField();
    if ($first_event === NULL || $first_event === FALSE) {
      return ['headers' => $headers, 'rows' => []];
    }
    $coverage_day = (int) ceil((int) $first_event / 86400);
    $query = $this->reports->events($criteria, [
      'start' => max(0, ($first_day - $window) * 86400),
      'end' => $end_day * 86400,
    ]);
    $query->condition('e.entity_type', 'node');
    $target = 'n';
    if ($criteria['anomaly_scope'] === 'collection') {
      $this->reports->joinCollections($query);
      if (!empty($criteria['filters']['field_member_of'])) {
        $query->condition('collection.nid', $criteria['filters']['field_member_of'], 'IN');
      }
      $target = 'collection';
    }
    $query->addField($target, 'nid', 'target_id');
    $query->addField($target, 'title', 'title');
    $query->addField($target, 'created', 'created');
    $query->addExpression('FLOOR(e.timestamp / 86400.0)', 'event_day');
    $query->addExpression("NULLIF(TRIM(e.session_id), '')", 'session_key');
    $query->addExpression('COUNT(DISTINCT e.id)', 'event_count');
    foreach ([$target . '.nid', $target . '.title', $target . '.created', 'event_day', 'session_key'] as $field) {
      $query->groupBy($field);
    }
    $daily = $this->database->select($query, 'sessions');
    $daily->fields('sessions', ['target_id', 'title', 'created', 'event_day']);
    foreach (['target_id', 'title', 'created', 'event_day'] as $field) {
      $daily->groupBy($field);
    }
    $daily->addExpression('SUM(event_count)', 'views');
    $daily->addExpression('COUNT(session_key)', 'known_sessions');
    $daily->addExpression('MAX(CASE WHEN session_key IS NOT NULL THEN event_count ELSE 0 END)', 'largest_session');
    $daily->addExpression('SUM(CASE WHEN session_key IS NULL THEN event_count ELSE 0 END)', 'unknown_views');
    $daily->orderBy('target_id')->orderBy('event_day');
    $rows = [];
    $history = [];
    $previous_id = NULL;
    foreach ($daily->execute() as $record) {
      $id = (int) $record->target_id;
      $day = (int) $record->event_day;
      if ($id !== $previous_id) {
        $history = [];
        $previous_id = $id;
      }
      foreach ($history as $past_day => $count) {
        if ($past_day < $day - $window) {
          unset($history[$past_day]);
        }
      }
      $mean = array_sum($history) / $window;
      $views = (int) $record->views;
      $history[$day] = $views;
      $baseline_start = max($coverage_day, (int) ceil((int) $record->created / 86400));
      if ($day < max($first_day, $baseline_start + $window)
        || $views < $criteria['minimum_views']
        || $views < $mean * $criteria['spike_multiplier']) {
        continue;
      }
      $share = 100 * (int) $record->largest_session / $views;
      $signal = $share >= $criteria['session_share'] ? 'Concentrated session traffic; review for automation' : 'Distributed session spike; cause unconfirmed';
      if ((int) $record->unknown_views > $views / 2) {
        $signal = 'Insufficient session evidence to assess concentration';
      }
      $rows[] = [
        $id, $record->title, gmdate('Y-m-d', $day * 86400), $views,
        round($mean, 2), $mean > 0 ? round($views / $mean, 2) : 'No prior usage',
        (int) $record->known_sessions, round($share, 2), (int) $record->unknown_views, $signal,
      ];
    }
    usort($rows, static fn(array $a, array $b) => ($b[3] <=> $a[3]) ?: ($a[0] <=> $b[0]) ?: strcmp($a[2], $b[2]));
    return ['headers' => $headers, 'rows' => $rows];
  }

}
