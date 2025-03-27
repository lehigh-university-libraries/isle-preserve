<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Add a canonical link to PDFs pointing back to their HTML page.
 */
final class PdfCanonicalLink implements EventSubscriberInterface {

  /**
   * Listen for flysystem PDF responses.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The event to process.
   */
  public function addCanonicalLinkHeader(ResponseEvent $event) : void {
    $request = $event->getRequest();
    if (!$this->applies($request)) {
      return;
    }

    // don't save non-200 responses.
    $response = $event->getResponse();
    if ($response->getStatusCode() !== 200) {
      return;
    }

    $uri = str_replace('/_flysystem/fedora/', 'fedora://', $request->getPathInfo());
    $uri = str_replace('/system/files/', 'private://', $uri);
    if (substr($uri, -4) !== '.pdf') {
      return;
    }

    $path = \Drupal::database()->query("SELECT COALESCE(a.alias, CONCAT('/node/', field_media_of_target_id)) from file_managed f
      INNER JOIN media__field_media_document md ON field_media_document_target_id = f.fid
      INNER JOIN media__field_media_of mo ON mo.entity_id = md.entity_id
      LEFT JOIN path_alias a ON a.path = CONCAT('/node/', field_media_of_target_id)
      WHERE uri = :uri", [
        ':uri' => $uri,
      ])->fetchField();

    if ($path) {
      $response->headers->set('Link', '<https://preserve.lehigh.edu/' . $path . '>; rel="canonical"');
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::RESPONSE => [
        ['addCanonicalLinkHeader'],
      ],
    ];
  }

  /**
   * Helper function to see if the given response needs handled by this service.
   */
  protected function applies(Request $request, $get = FALSE): bool {
    // Only apply on the node canonical view or our collections/browse views.
    $route_name = $request->attributes->get('_route');
    if (in_array($route_name, ["flysystem.serve", "flysystem.files"])) {
      return TRUE;
    }

    return FALSE;
  }

}
