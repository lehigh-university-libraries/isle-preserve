<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for Lehigh Islandora routes.
 */
final class PfaffsBepressRedirects extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Drupal\Core\Entity\EntityTypeManagerInterface definition.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, Connection $database) {
    $this->entityTypeManager = $entityTypeManager;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('database')
    );
  }

  /**
   * Redirect a /pfaffs/gunn/\d+ to a nid.
   */
  public function perform(Request $request, int $id) {
    $entity_type = 'node';
    $nid = $this->database->query('SELECT entity_id
      FROM {node__field_pid}
      WHERE field_pid_value = :pid', [
        ':pid' => "digitalcollections:gunn_$id",
      ]
    )->fetchField();
    if (!$node = $this->entityTypeManager->getStorage($entity_type)->load($nid)) {
      return $this->redirect("<front>", [], [], 301);
    }

    $url = $node->toUrl('canonical');
    $route_name = $url->getRouteName();
    $route_parameters = $url->getRouteParameters();

    return $this->redirect($route_name, $route_parameters, [], 301);
  }

}
