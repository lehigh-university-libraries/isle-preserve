<?php

namespace Drupal\lehigh_islandora\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Redirect i7 pids to i2 node.
 */
class LegacyRedirect extends ControllerBase {

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
   * Redirect a pid to a nid.
   */
  public function perform(Request $request, string $pid) {
    $entity_type = 'node';
    $nid = $this->database->query(
          'SELECT entity_id FROM {node__field_pid}
      WHERE field_pid_value = :pid', [
        ':pid' => $pid,
      ]
      )->fetchField();
    if ($nid && $node = $this->entityTypeManager->getStorage($entity_type)->load($nid)) {
      $url = $node->toUrl('canonical');
      $route_name = $url->getRouteName();
      $route_parameters = $url->getRouteParameters();

      $options = [];

      return $this->redirect($route_name, $route_parameters, $options, 301);
    }

    return $this->redirect("<front>", [], [], 301);
  }

}
