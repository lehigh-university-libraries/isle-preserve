<?php

namespace Drupal\islandora_scribe;

/** Verifies exact wire bytes before decoding untrusted JSON. */
final class SignedEvent {
  public const MAX_BODY = 65536;

  public static function decode(string $body, string $timestamp, string $signature, string $secret, int $now): array {
    if (strlen($body) > self::MAX_BODY || !preg_match('/^[0-9]{1,12}$/D', $timestamp) || abs($now - (int) $timestamp) > 300 || !preg_match('/^v1=[a-f0-9]{64}$/D', $signature) || !hash_equals('v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret), $signature)) {
      throw new \InvalidArgumentException('Invalid signed delivery.');
    }
    $event = json_decode($body, TRUE, 16, JSON_THROW_ON_ERROR);
    if (!is_array($event)) {
      throw new \InvalidArgumentException('Invalid delivery.');
    }
    return $event;
  }

  public static function publication(array $event, array $mapping): int {
    $data = $event['data'] ?? [];
    if (($event['specversion'] ?? '') !== '1.0' || ($event['type'] ?? '') !== 'dev.scribe.annotations.published' || !is_string($event['id'] ?? NULL) || !$event['id'] || strlen($event['id']) > 255) {
      throw new \InvalidArgumentException('Invalid publication event.');
    }
    foreach (['workspaceId' => 'workspace_id', 'itemId' => 'item_id', 'itemImageId' => 'item_image_id', 'externalReferenceId' => 'external_reference_id'] as $wire => $stored) {
      if (!isset($data[$wire]) || (string) $data[$wire] !== (string) $mapping[$stored]) {
        throw new \InvalidArgumentException('Publication resource mismatch.');
      }
    }
    if (!is_int($data['publishedRevision'] ?? NULL) || $data['publishedRevision'] < 1) {
      throw new \InvalidArgumentException('Invalid publication revision.');
    }
    return $data['publishedRevision'];
  }
}
