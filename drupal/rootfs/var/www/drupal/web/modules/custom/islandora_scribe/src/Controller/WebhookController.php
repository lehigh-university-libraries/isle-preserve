<?php

namespace Drupal\islandora_scribe\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\islandora_scribe\Integration;
use Drupal\islandora_scribe\RuntimeSettings;
use Drupal\islandora_scribe\SignedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives signed Scribe publication and correlation callbacks.
 */
final class WebhookController extends ControllerBase {

  public function __construct(private Integration $integration, private RuntimeSettings $settings, private LoggerInterface $callbackLogger) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('islandora_scribe.integration'), $container->get('islandora_scribe.settings'), $container->get('logger.channel.islandora_scribe'));
  }

  /**
   * Receives a signed publication event.
   */
  public function publication(Request $request): Response {
    return $this->receive($request, FALSE);
  }

  /**
   * Receives a signed resource correlation.
   */
  public function correlation(Request $request): Response {
    return $this->receive($request, TRUE);
  }

  /**
   * Verifies and dispatches a signed callback.
   */
  private function receive(Request $request, bool $correlation): Response {
    $endpoint = $correlation ? 'correlation' : 'webhook';
    if (!$request->isSecure()) {
      $this->callbackLogger->warning('Scribe @endpoint callback rejected: insecure_request.', ['@endpoint' => $endpoint]);
      return new Response('', 400);
    }
    $stage = 'settings';
    try {
      $settings = $this->settings->get();
      $stage = 'body';
      // Read at most limit + 1 bytes, including requests without Content-Length.
      $stream = $request->getContent(TRUE);
      $body = stream_get_contents($stream, SignedEvent::MAX_BODY + 1);
      $stage = 'signed_delivery';
      $data = SignedEvent::decode($body, $request->headers->get('X-Scribe-Timestamp', ''), $request->headers->get('X-Scribe-Signature', ''), $settings[$correlation ? 'correlation_secret' : 'webhook_secret'], time());
      $stage = $correlation ? 'correlation' : 'publication';
      if ($correlation) {
        $this->integration->correlate($data);
      }
      else {
        $this->integration->accept($data);
      }
      return new Response('', 204);
    }
    catch (\InvalidArgumentException | \JsonException $e) {
      // Log fixed categories only, never payloads or arbitrary exception text.
      $reason = $e instanceof \JsonException ? 'invalid_json' : match ($e->getMessage()) {
        'Delivery body exceeds limit.' => 'body_too_large',
        'Invalid delivery timestamp.' => 'invalid_timestamp',
        'Delivery timestamp outside allowed window.' => 'expired_timestamp',
        'Invalid delivery signature format.' => 'invalid_signature_format',
        'Delivery signature mismatch.' => 'signature_mismatch',
        'Invalid delivery.' => 'invalid_payload',
        'Invalid operation.' => 'invalid_operation',
        'Invalid correlation tuple.' => 'tuple_mismatch',
        'Operation already associated.' => 'operation_conflict',
        'Resource already associated.' => 'resource_conflict',
        'Unknown derivative.' => 'mapping_missing',
        'Invalid workspace or image.' => 'invalid_resource',
        'Invalid publication event.' => 'invalid_publication_event',
        'Publication resource mismatch.' => 'publication_resource_mismatch',
        'Invalid publication revision.' => 'invalid_publication_revision',
        default => 'invalid_callback_data',
      };
      $this->callbackLogger->warning('Scribe @endpoint callback rejected at @stage: @reason.', [
        '@endpoint' => $endpoint,
        '@stage' => $stage,
        '@reason' => $reason,
      ]);
      return new Response('', 400);
    }
    catch (\Throwable $e) {
      $this->callbackLogger->error('Scribe @endpoint callback failed at @stage: @exception_type.', [
        '@endpoint' => $endpoint,
        '@stage' => $stage,
        '@exception_type' => get_class($e),
      ]);
      return new Response('', 503);
    }
  }

}
