<?php

namespace Drupal\entity_metrics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\SessionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for custom flood checks.
 */
class VisitController extends ControllerBase {
  const FLOOD_EVENT_LIMIT = 20;
  const FLOOD_EVENT_WINDOW_SECONDS = 60;

  /**
   * The session manager.
   *
   * @var Drupal\Core\Session\SessionManager
   */
  protected $session;

  /**
   * CustomFloodController constructor.
   *
   * @param \Drupal\Core\Session\SessionManager $session
   *   The Drupal session manager service.
   */
  public function __construct(SessionManager $session) {
    $this->session = $session;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('session_manager')
    );
  }

  /**
   * Record when a site visitor views an entity.
   */
  public function recordVisit(Request $request) {
    $ip = $request->getClientIp();
    if ($this->checkFlood($ip)) {
      // Return a 429 response.
      $response = new Response();
      $response->setStatusCode(Response::HTTP_TOO_MANY_REQUESTS);
      $response->setContent('Too Many Requests');

      return $response;
    }

    $currentPath = explode('/', $request->request->get('currentPath'));
    $entity_id = array_pop($currentPath);
    \Drupal::database()->insert('entity_metrics_data')
      ->fields([
        'entity_type' => 'node',
        'entity_id' => $entity_id,
        'session_id' => $this->session->getId(),
        'timestamp' => \Drupal::time()->getCurrentTime(),
        'ip_address' => $ip,
      ])
      ->execute();
    $response = new Response();
    $response->setStatusCode(Response::HTTP_OK);
    return $response;
  }

  /**
   * Get site visitor history views for a given entity.
   */
  public function getVisits($type, $id) {
    return new JsonResponse([
      'monthly' => \Drupal::database()->query('SELECT COUNT(id) FROM {entity_metrics_data}
        WHERE entity_type = :type
          AND entity_id = :id
          AND timestamp > :thirtyDays', [
            ':type' => $type,
            ':id' => $id,
            ':thirtyDays' => \Drupal::time()->getCurrentTime() - 2592000,
          ])->fetchField(),
      'total' => \Drupal::database()->query('SELECT COUNT(id) FROM {entity_metrics_data}
        WHERE entity_type = :type
          AND entity_id = :id', [
            ':type' => $type,
            ':id' => $id,
          ])->fetchField(),
    ]);
  }

  /**
   * Check the flood status.
   */
  private function checkFlood(string $ip) : bool {
    // Only allow recording 20 events every minute.
    return \Drupal::database()->query('SELECT COUNT(id) FROM {entity_metrics_data}
      WHERE ip_address = :ip
        AND timestamp > :timeout', [
          ':ip' => $ip,
          ':timeout' => \Drupal::time()->getCurrentTime() - self::FLOOD_EVENT_WINDOW_SECONDS,
        ])->fetchField() > self::FLOOD_EVENT_LIMIT;
  }

}
