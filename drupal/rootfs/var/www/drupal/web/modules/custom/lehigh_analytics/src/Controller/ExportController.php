<?php

namespace Drupal\lehigh_analytics\Controller;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\lehigh_analytics\UsageReport;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports exactly the same filtered aggregates shown on the reporting page.
 */
final class ExportController extends ControllerBase {

  public function __construct(private readonly UsageReport $reports) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('lehigh_analytics.reports'));
  }

  /**
   * Delivers aggregate CSV without identifiers for individual visitors.
   */
  public function export(string $report, Request $request): StreamedResponse {
    if ($report === 'anomalies' && !$this->currentUser()->hasPermission('view lehigh analytics')) {
      throw new AccessDeniedHttpException();
    }
    $criteria = $this->reports->criteria($request->query->all());
    $data = $this->reports->report($report, $criteria);
    $period = $this->reports->periods()[$criteria['period']];
    $timezone = $this->config('lehigh_analytics.settings')->get('timezone');
    $response = new StreamedResponse(static function () use ($data, $period, $timezone, $criteria, $report): void {
      $stream = fopen('php://output', 'w');
      $headers = array_merge(['Rank'], $data['headers'], [
        'Source', 'Period', 'Timezone', 'Grouping field', 'Metadata filters', 'Anomaly settings', 'Generated (UTC)',
      ]);
      fputcsv($stream, $headers, ',', '"', '');
      foreach ($data['rows'] as $index => $row) {
        $row = array_merge([$index + 1], $row, [
          'entity_metrics', $period['label'], $timezone, $criteria['group'], json_encode($criteria['filters']),
          $report === 'anomalies' ? json_encode(array_diff_key($criteria, array_flip(['period', 'group', 'filters']))) : '',
          gmdate('Y-m-d H:i:s', $data['generated_at']),
        ]);
        // Neutralize spreadsheet formula injection from titles and term labels.
        $row = array_map(static fn($value) => is_string($value) && preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'" . $value : $value, $row);
        fputcsv($stream, $row, ',', '"', '');
      }
      fclose($stream);
    });
    $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="lehigh-analytics-' . $report . '-' . $criteria['period'] . '.csv"');
    $response->headers->set('Cache-Control', 'private, no-store');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    return $response;
  }

}
