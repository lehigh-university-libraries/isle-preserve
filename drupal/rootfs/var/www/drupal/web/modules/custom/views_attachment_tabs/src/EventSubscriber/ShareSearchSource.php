<?php

namespace Drupal\views_attachment_tabs\EventSubscriber;

use Drupal\search_api\Event\QueryPreExecuteEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ShareSearchSource implements EventSubscriberInterface {

/**
* @param \Drupal\search_api\Event\QueryPreExecuteEvent $event
*/
public function alterSearchId(QueryPreExecuteEvent $event) {
$query = $event->getQuery();

//dpm($query->getSearchId());
/**
 * "views_page:browse__main"
"views_attachment:browse__card_view"
"views_attachment:browse__list"
"views_attachment:browse__masonry"
 */

// Add some more logic here!
//$query->setSearchId('views_page:browse__card_view');
//$query->setSearchId('views_attachment:browse__card_view');
}

/**
* {@inheritdoc}
*/
public static function getSubscribedEvents(): array {
$events[SearchApiEvents::QUERY_PRE_EXECUTE][] = ['alterSearchId', 100];

return $events;
}

}
