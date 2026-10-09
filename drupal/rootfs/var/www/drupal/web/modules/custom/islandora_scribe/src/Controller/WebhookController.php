<?php

namespace Drupal\islandora_scribe\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\islandora_scribe\Integration;
use Drupal\islandora_scribe\RuntimeSettings;
use Drupal\islandora_scribe\SignedEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class WebhookController extends ControllerBase {
  public function __construct(private Integration $integration, private RuntimeSettings $settings) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get('islandora_scribe.integration'), $container->get('islandora_scribe.settings'));
  }

  public function publication(Request $request): Response {
    return $this->receive($request, FALSE);
  }

  public function correlation(Request $request): Response {
    return $this->receive($request, TRUE);
  }

  private function receive(Request $request, bool $correlation): Response {
    if (!$request->isSecure()) {
      return new Response('', 400);
    }
    try {
      $settings = $this->settings->get();
      // Read at most limit + 1 bytes, including requests without Content-Length.
      $stream = $request->getContent(TRUE);
      $body = stream_get_contents($stream, SignedEvent::MAX_BODY + 1);
      $data = SignedEvent::decode($body, $request->headers->get('X-Scribe-Timestamp', ''), $request->headers->get('X-Scribe-Signature', ''), $settings[$correlation ? 'correlation_secret' : 'webhook_secret'], time());
      if ($correlation) {
        $this->integration->correlate($data);
      }
      else {
        $this->integration->accept($data);
      }
      return new Response('', 204);
    }
    catch (\InvalidArgumentException | \JsonException $e) {
      return new Response('', 400);
    }
    catch (\Throwable $e) {
      return new Response('', 503);
    }
  }
}
