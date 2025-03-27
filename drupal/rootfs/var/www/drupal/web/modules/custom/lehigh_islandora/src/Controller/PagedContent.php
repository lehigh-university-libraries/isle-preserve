<?php

namespace Drupal\lehigh_islandora\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Get items that have children shown in Mirador.
 */
class PagedContent extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * {@inheritdoc}
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
          $container->get('database')
      );
  }

  /**
   * Redirect a pid to a nid.
   */
  public function get() {
    $nids = $this->database->query(
          "SELECT nid FROM node__field_model m
      INNER JOIN node_field_data n ON n.nid = m.entity_id
      INNER JOIN taxonomy_term_field_data t ON t.tid = field_model_target_id
      WHERE n.status = 1 AND t.name IN ('Paged Content', 'Compound Object', 'Publication Issue')
      GROUP BY nid"
      )->fetchCol();

    return new JsonResponse($nids);
  }

}
