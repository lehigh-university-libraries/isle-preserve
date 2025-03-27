<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Add uriports headers not handled by csp module.
 */
final class Uriports implements EventSubscriberInterface {

  /**
   * Add additional headers needed for uriports.com.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The event to process.
   */
  public function addHeaders(ResponseEvent $event) : void {
    if (!$event->isMainRequest()) {
      return;
    }

    $response = $event->getResponse();
    $response->headers->set('Report-To', '{"group":"default","max_age":10886400,"endpoints":[{"url":"https://ccssq3ur.uriports.com/reports"}],"include_subdomains":true}');
    $response->headers->set('Reporting-Endpoints', 'default="https://ccssq3ur.uriports.com/reports"');
    $response->headers->set('NEL', '{"report_to":"default","max_age":2592000,"include_subdomains":true,"failure_fraction":1.0}');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::RESPONSE => [
      ['addHeaders'],
      ],
    ];
  }

}
