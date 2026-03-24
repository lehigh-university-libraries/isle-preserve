<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\EventSubscriber;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirect collections to their View.
 */
final class RedirectCollections implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   */
  public function __construct(
    protected RouteProviderInterface $routeProvider,
  ) {}

  /**
   * Redirect collections to their View.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The event to process.
   */
  public function rc(RequestEvent $event) : void {
    $request = $event->getRequest();
    $route_name = $request->attributes->get(RouteObjectInterface::ROUTE_NAME);

    if ($route_name === 'view.browse.main') {
      $node = $request->attributes->get('node');
      if (is_numeric($node)) {
        $node = Node::load((int) $node);
      }

      if ($node instanceof Node
        && lehigh_site_support_identify_collection($node, TRUE)
        && lehigh_site_support_has_display_hint($node, 'Journal Browser')) {
        $target_route_name = 'view.journal_browser.main';
        $route = $this->routeProvider->getRouteByName($target_route_name);
        $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $target_route_name);
        $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
        foreach ($route->getDefaults() as $key => $value) {
          $request->attributes->set($key, $value);
        }
        $request->attributes->set('node', $node->id());
      }
      return;
    }

    // See if we're dealing with a canonical node request.
    $path = $request->getPathInfo();
    $regex = '/^\/node\/\d+$/';
    if (preg_match($regex, $path)) {
      // Don't redirect REST CUD requests.
      if (!$request->isMethod('GET')) {
        return;
      }

      // Don't redirect ?_format= requests.
      $format = $request->query->get('_format');
      if ($format) {
        return;
      }

      // Get the node ID from the path.
      $path = ltrim($path, '/');
      $path_parts = explode('/', $path);
      if (isset($path_parts[1]) && is_numeric($path_parts[1])) {
        $nid = (int) $path_parts[1];
      }
      else {
        return;
      }

      // If this is a collection, redirect it to the view.
      $node = Node::load($nid);
      if (lehigh_site_support_identify_collection($node, TRUE)) {
        $url = Url::fromRoute('view.browse.main', ['node' => $node->id()]);
        $redirect = new RedirectResponse($url->toString());
        $event->setResponse($redirect);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => [
        ['rc'],
      ],
    ];
  }

}
