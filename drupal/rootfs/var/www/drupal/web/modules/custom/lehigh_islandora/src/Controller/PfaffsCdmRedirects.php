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
final class PfaffsCdmRedirects extends ControllerBase {

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
  public function perform(Request $request, int $parent, int $child, int $zoom, int $x, int $y) {
    $entity_type = 'node';
    $nid = $this->database->query(
          'SELECT entity_id FROM {node__field_pid}
      WHERE field_pid_value = :pid', [
        ':pid' => "digitalcollections:pfaffs_$parent",
      ]
      )->fetchField();
    if ($nid && $node = $this->entityTypeManager->getStorage($entity_type)->load($nid)) {
      $options = [
        'fragment' => "cdmzoom:$zoom/$x/$y",
      ];
      $mid = $this->database->query('SELECT mo.entity_id FROM node__field_pid p
        INNER JOIN media__field_media_of mo ON field_media_of_target_id = p.entity_id
        INNER JOIN media__field_media_use mu ON mu.entity_id = mo.entity_id
        WHERE field_media_use_target_id = 18 AND field_pid_value = :pid', [
          ':pid' => "digitalcollections:pfaffs_$child",
        ])->fetchField();
      if ($mid) {
        $canvas_id = "https://preserve.lehigh.edu/node/$nid/canvas/$mid";
        $manifest = file_get_contents("https://preserve.lehigh.edu/node/$nid/book-manifest");
        $iiif = json_decode($manifest, TRUE);
        foreach ($iiif['sequences'][0]['canvases'] as $i => $canvas) {
          if ($canvas['@id'] == $canvas_id) {
            $options['query']['pageNumber'] = $i;
            break;
          }
        }
      }

      $url = $node->toUrl('canonical');
      $route_name = $url->getRouteName();
      $route_parameters = $url->getRouteParameters();

      return $this->redirect($route_name, $route_parameters, $options, 301);
    }

    return $this->redirect("<front>", [], [], 301);
  }

}
