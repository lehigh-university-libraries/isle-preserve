<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\EventSubscriber;

use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Cache book manifests on disk.
 */
final class CacheBookManifests implements EventSubscriberInterface {

  /**
   * Listen for book manifest requests.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The event to process.
   */
  public function getCachedManifest(RequestEvent $event) : void {
    // See if we're dealing with a book manifest request.
    $request = $event->getRequest();
    if (!$this->applies($request, TRUE)) {
      return;
    }

    // Now we know we're dealing with a IIIF manifest route, so
    // see if we have a cached response on disk.
    $path = $request->getPathInfo();
    $file_path = self::getCachedFilePath($request, $path);
    if (file_exists($file_path)) {
      $file_contents = file_get_contents($file_path);
      $response = new Response($file_contents, Response::HTTP_OK);
      $response->headers->set('Cache-Control', 'max-age=3600, public');
      $response->headers->set('Content-Type', 'application/json');
      $response->headers->set('X-Drupal-Cache', 'HIT');
      $age = (string) (time() - filemtime($file_path));
      $response->headers->set('X-Age', $age);

      $event->setResponse($response);
    }
  }

  /**
   * Listen for book manifest responses.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The event to process.
   */
  public function setCachedManifest(ResponseEvent $event) : void {
    // See if the response is for a book manifest.
    $request = $event->getRequest();
    if (!$this->applies($request)) {
      return;
    }

    $path = $request->getPathInfo();
    $file_path = self::getCachedFilePath($request, $path);

    // don't save non-200 responses.
    $response = $event->getResponse();
    if ($response->getStatusCode() !== 200) {
      return;
    }

    // If we're invalidating the cache
    // OR the cached file doesn't exist create it from the response.
    if ($request->query->get('cache-warmer', FALSE) || !file_exists($file_path)) {
      $body = $event->getResponse()->getContent();
      $dir = dirname($file_path);
      $filesystem = \Drupal::service('file_system');
      if ($filesystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY)) {
        $f = fopen($file_path, 'w');
        if ($f) {
          fwrite($f, $body);
          fclose($f);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => [
      ['getCachedManifest'],
      ],
      KernelEvents::RESPONSE => [
      ['setCachedManifest'],
      ],
    ];
  }

  /**
   * See if this is a IIIF manifest URL.
   */
  protected function applies(Request $request, $get = FALSE): bool {
    // Bail if we want to force a regeneration.
    if ($get && $request->query->get('cache-warmer', FALSE)) {
      return FALSE;
    }

    $route_name = $request->attributes->get('_route');
    if ($route_name === "view.iiif_manifest.rest_export_1") {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Helper function to get the path to cache the book manifests to.
   */
  protected static function getCachedFilePath(Request $request, string $path): string {
    $filesystem = \Drupal::service('file_system');
    $filepath = 'private://iiif';
    $filepath .= '/' . $request->getHost();

    // Make a subdirectory based on the current user's role IDs.
    $role_ids = \Drupal::currentUser()->getRoles();
    $role_ids = implode('', $role_ids);
    $filepath .= '/' . md5($role_ids);
    $filepath .= $path . '.json';
    $base_dir = dirname($filepath);
    $filesystem->prepareDirectory($base_dir, FileSystemInterface::CREATE_DIRECTORY);

    return $filesystem->realpath($filepath);
  }

}
