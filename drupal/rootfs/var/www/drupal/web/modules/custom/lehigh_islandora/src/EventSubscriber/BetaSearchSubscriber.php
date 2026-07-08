<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\EventSubscriber;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Replaces the browse View with the beta search shell when requested.
 */
final class BetaSearchSubscriber implements EventSubscriberInterface {

  private const ROUTE_NAME = 'lehigh_islandora.beta_search_shell';

  /**
   * Constructs the subscriber.
   */
  public function __construct(
    protected RouteProviderInterface $routeProvider,
  ) {}

  /**
   * Swaps beta browse traffic to a lightweight controller.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    if (!$request->isMethod('GET') || $request->query->get('v') !== 'beta') {
      return;
    }

    $route_name = $request->attributes->get(RouteObjectInterface::ROUTE_NAME);
    if ($route_name !== 'view.browse.main') {
      return;
    }

    $route = $this->routeProvider->getRouteByName(self::ROUTE_NAME);
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, self::ROUTE_NAME);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    foreach ($route->getDefaults() as $key => $value) {
      $request->attributes->set($key, $value);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => [
        ['onRequest', -10],
      ],
    ];
  }

}
