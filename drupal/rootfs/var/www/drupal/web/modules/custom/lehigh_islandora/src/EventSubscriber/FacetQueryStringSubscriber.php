<?php

namespace Drupal\lehigh_islandora\EventSubscriber;

use Drupal\facets\Event\QueryStringCreated;
use Drupal\facets\Event\FacetsEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Check for cache-warmer strings.
 */
class FacetQueryStringSubscriber implements EventSubscriberInterface {

  /**
   * Remove our custom query parameter if it exists.
   *
   * @param \Drupal\facets\Event\QueryStringCreated $event
   *   The event to process.
   */
  public function onQueryStringCreated(QueryStringCreated $event) {
    $params = $event->getQueryParameters();
    if (!$params->get('cache-warmer')) {
      return;
    }

    $params->remove('cache-warmer');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[FacetsEvents::QUERY_STRING_CREATED][] = ['onQueryStringCreated'];
    return $events;
  }

}
