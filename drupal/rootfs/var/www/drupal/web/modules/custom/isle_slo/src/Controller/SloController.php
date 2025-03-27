<?php

declare(strict_types=1);

namespace Drupal\isle_slo\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns responses for Isle slo routes.
 */
final class SloController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly Connection $connection,
  ) {
    $this->db = $connection;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(): array {
    $data = $this->fetchDataFromDatabase();
    $build['totals'] = [
      '#type' => 'inline_template',
      '#template' => $this->generateChartHtml($data, "totals"),
    ];

    $terms = $this->db->query("SELECT tid, name FROM taxonomy_term_field_data
      WHERE vid = 'islandora_models'
      ORDER BY name")->fetchAllKeyed();
    foreach ($terms as $tid => $name) {
      $chart_id = "model_$tid";
      $data = $this->fetchDataFromDatabase($tid);
      if (count($data) < 2) {
        continue;
      }
      $build[$chart_id] = [
        '#type' => 'details',
        '#title' => $name,
      ];

      $build[$chart_id]['chart'] = [
        '#type' => 'inline_template',
        '#template' => $this->generateChartHtml($data, $chart_id),
      ];
    }
    return $build;
  }

  /**
   * Get time to creation for original file derivatives.
   */
  private function fetchDataFromDatabase($tid = 0, $group = '%Y-%m-%d'): array {
    // Example data (replace with your query)
    $sql = "SELECT DATE_FORMAT(FROM_UNIXTIME(m.created), '$group') AS month,
        AVG(CASE WHEN t.name = 'FITS File' THEN child.created - m.created ELSE 0 END) / 60 AS fits,
        AVG(CASE WHEN t.name = 'Extracted Text' THEN child.created - m.created ELSE 0 END) / 60 AS ocr,
        AVG(CASE WHEN t.name = 'Thumbnail Image' THEN child.created - m.created ELSE 0 END) / 60 AS thumbnail,
        AVG(CASE WHEN t.name = 'Service File' THEN child.created - m.created ELSE 0 END) / 60 AS service_file,
        AVG(CASE WHEN t.name = 'hOCR' THEN child.created - m.created ELSE 0 END) / 60 AS hocr,
        AVG(child.created - m.created) / 60 AS total
      FROM media_field_data m
      INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
      INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
      INNER JOIN media__field_media_of cmo ON cmo.field_media_of_target_id = mo.field_media_of_target_id AND mo.entity_id <> cmo.entity_id
      INNER JOIN media_field_data child ON child.mid = cmo.entity_id
      INNER JOIN media__field_media_use cmu ON child.mid = cmu.entity_id
      INNER JOIN taxonomy_term_field_data t ON t.tid = cmu.field_media_use_target_id";
    if (!empty($tid)) {
      $sql .= " INNER JOIN node__field_model pm ON mo.field_media_of_target_id = pm.entity_id AND field_model_target_id = " . $tid;
    }
    $oldest = 1705603784;
    if ($group == '%Y-%m-%d') {
      $oldest = time() - (86400 * 30);
    }
    $sql .= " WHERE m.created > $oldest
        AND mo.field_media_of_target_id > 386050
        AND m.created < child.created
        AND mu.field_media_use_target_id = 16
      GROUP BY DATE_FORMAT(FROM_UNIXTIME(m.created), '$group')
      ORDER BY m.created";
    if ($group == '%Y-%m-%d') {
      $sql .= ' LIMIT 30';
    }
    $result = $this->db->query($sql);
    $data = [];
    foreach ($result as $row) {
      if (!isset($data[0])) {
        $header = [];
        foreach ($row as $key => $v) {
          $header[] = $key;
        }
        $data[] = $header;
      }
      $r = [];
      foreach ($row as $key => $v) {
        $r[] = $key == "month" ? $v : (int) $v;
      }

      $data[] = $r;
    }

    return $data;
  }

  /**
   * Generates the HTML for the Google Chart.
   */
  private function generateChartHtml(array $data, $chart_id): string {
    $json = json_encode($data);
    $html = <<< HTML
    <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
    <script type="text/javascript">

      google.charts.load('current', {'packages':['corechart']});
      google.charts.setOnLoadCallback(drawChart);

      function drawChart() {
        var data = google.visualization.arrayToDataTable($json);

        var options = {
          title: 'Minutes to create derivative',
          height: 500,
          width: 1000,
        };

        var chart = new google.visualization.AreaChart(document.getElementById('$chart_id'));
        chart.draw(data, options);
      }
    </script>
    <div id="$chart_id" style="width: 100%; height: 500px;"></div>
HTML;

    return $html;
  }

}
