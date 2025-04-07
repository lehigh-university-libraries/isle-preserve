<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\EventSubscriber;

use Drupal\node\NodeInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\node\Entity\Node;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Cache node canonical on disk.
 */
final class CacheNodeCanonical implements EventSubscriberInterface {

  /**
   * Listen for node canonical requests.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The event to process.
   */
  public function getCachedNodeView(RequestEvent $event) : void {
    // See if we're dealing with a /node/\d+ request.
    $request = $event->getRequest();
    if (!$this->applies($request, TRUE)) {
      return;
    }

    // Now we know we're dealing with a node canonical route, so
    // see if we have a cached response on disk.
    $path = $request->getPathInfo();
    $file_path = self::getCachedFilePath($request, $path);
    if (file_exists($file_path)) {
      $file_contents = file_get_contents($file_path);
      $response = new Response($file_contents, Response::HTTP_OK);
      $response->headers->set('Link', '<https://preserve.lehigh.edu/' . $path . '>; rel="canonical"');
      $response->headers->set('Content-Type', 'text/html');
      $response->headers->set('X-Drupal-Cache', 'HIT');
      $age = (string) (time() - filemtime($file_path));
      $response->headers->set('Age', $age);
      $event->setResponse($response);
    }
  }

  /**
   * Listen for node canonical responses.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The event to process.
   */
  public function setCachedNodeView(ResponseEvent $event) : void {
    $request = $event->getRequest();
    if (!$this->applies($request)) {
      return;
    }

    // don't save non-200 responses.
    $response = $event->getResponse();
    if ($response->getStatusCode() !== 200) {
      return;
    }

    $path = $request->getPathInfo();
    $file_path = self::getCachedFilePath($request, $path);
    $response->headers->set('Link', '<https://preserve.lehigh.edu/' . $path . '>; rel="canonical"');

    // @todo gate this on an internal IP too
    $invalidating = $request->query->get('cache-warmer', FALSE);
    // If we're invalidating the cache
    // OR the cached file doesn't exist create it from the response.
    if ($invalidating || !file_exists($file_path)) {
      $body = $response->getContent();
      $dir = dirname($file_path);
      $filesystem = \Drupal::service('file_system');
      if ($filesystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY)) {
        // If the cache is being invalidating on this node
        // remove all its cached responses.
        if ($invalidating) {
          $node = FALSE;
          if ($request->attributes->has('node')) {
            $nid = $request->attributes->get('node');
            $node = is_object($nid) ? $nid : Node::load($nid);
          }

          // If we're invalidating a node, clear its disk cache.
          if ($node) {
            self::clearDiskCache($node);
          }
          // If this is not a node response that's cached
          // base it on the path.
          else {
            $base_dir = $filesystem->realpath('private://canonical');
            if ($base_dir) {
              $pattern = "$base_dir/*/$path/*.html";
              array_map('unlink', glob($pattern));
            }
          }
        }

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
      // Make this a lower priority so other event subscribers can fire first.
      KernelEvents::REQUEST => [
        ['getCachedNodeView', -100],
      ],
      KernelEvents::RESPONSE => [
        ['setCachedNodeView'],
      ],
    ];
  }

  /**
   * Helper function to see if the response should be cached.
   */
  protected function applies(Request $request, $get = FALSE): bool {
    // Bail if we want to force a regeneration and we're fetching cache.
    if ($get && $request->query->get('cache-warmer', FALSE)) {
      return FALSE;
    }

    // Only apply on the node canonical view or our collections/browse views.
    $route_name = $request->attributes->get('_route');
    if (!in_array($route_name, ["view.homepage.page_1", "view.browse.main", "entity.node.canonical"])) {
      return FALSE;
    }
    if ($request->attributes->has('node')) {
      $node = $request->attributes->get('node');
      if ($node instanceof NodeInterface && $node->bundle() !== 'islandora_object') {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Helper function to get the path to cache the node canonical HTML to.
   */
  protected static function getCachedFilePath(Request $request, string $path): string {
    $filesystem = \Drupal::service('file_system');
    $base_dir = 'private://canonical';
    $base_dir .= '/' . $request->getHost();

    // Make a subdirectory based on the current user ID.
    $base_dir .= '/' . \Drupal::currentUser()->id();

    // Another dir based on the current path
    // unless it's the homepage node - keep that at the root dir.
    if ($path !== 'home') {
      $base_dir .= '/' . $path;
    }
    $filesystem->prepareDirectory($base_dir, FileSystemInterface::CREATE_DIRECTORY);
    $base_dir = $filesystem->realpath($base_dir);

    // Make the filename based on any URL parameters.
    $queryParams = $request->query->all();
    // But don't include our special cache param in the calculation.
    unset($queryParams['cache-warmer']);
    if (count($queryParams)) {
      $queryParams = json_encode($queryParams);
      $file_path = $base_dir . '/' . md5($queryParams) . '.html';
    }
    else {
      $file_path = $base_dir . '/index.html';
    }

    return $file_path;
  }

  /**
   * Remove cached node canonical pages from disk.
   */
  public static function clearDiskCache($node) {
    $filesystem = \Drupal::service('file_system');
    $base_dir = $filesystem->realpath('private://canonical');

    $nid = $node->id();
    // $base_dir/* will wipe all the caches for all users
    // since we're storing responses by uid, *.html
    // will get the canonical view along with any ?foo=bar query parameters
    $pattern = "$base_dir/*/node/$nid/*.html";
    array_map('unlink', glob($pattern));

    // Wipe any aliases for this node.
    foreach (['node', 'browse-items'] as $arg0) {
      $alias = \Drupal::service('path_alias.manager')->getAliasByPath("/$arg0/$nid");
      $pattern = "$base_dir/*/$alias/*.html";
      array_map('unlink', glob($pattern));
    }
  }

}
