<?php

declare(strict_types=1);

namespace Drupal\Tests\lehigh_islandora\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\lehigh_islandora\EventSubscriber\CacheBookManifests;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Verifies unavailable cache paths leave manifest generation working.
 *
 * @group lehigh_islandora
 */
final class CacheBookManifestsTest extends UnitTestCase {

  /**
   * Failed realpath resolution skips cache reads and writes.
   */
  public function testUnavailableCachePath(): void {
    $filesystem = $this->createMock(FileSystemInterface::class);
    $filesystem->method('realpath')->willReturn(FALSE);
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('getRoles')->willReturn(['anonymous']);
    $container = new ContainerBuilder();
    $container->set('file_system', $filesystem);
    $container->set('current_user', $account);
    \Drupal::setContainer($container);
    $request = Request::create('https://preserve.lehigh.edu/node/37349/book-manifest');
    $request->attributes->set('_route', 'view.iiif_manifest.rest_export_1');
    $subscriber = new CacheBookManifests();
    $method = new \ReflectionMethod(CacheBookManifests::class, 'getCachedFilePath');
    $this->assertSame('', $method->invoke(NULL, $request, $request->getPathInfo()));
    $kernel = $this->createMock(HttpKernelInterface::class);
    $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    $subscriber->getCachedManifest($event);
    $this->assertFalse($event->hasResponse());
    $response = new Response('{"label":"Gunn"}');
    $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
    $subscriber->setCachedManifest($event);
    $this->assertSame($response, $event->getResponse());
  }

}
