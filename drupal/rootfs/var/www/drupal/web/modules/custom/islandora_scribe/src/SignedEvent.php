<?php

namespace Drupal\islandora_scribe;

/**
 * Verifies exact wire bytes before decoding untrusted JSON. */
final class SignedEvent {
  public const MAX_BODY = 65536;

  /**
   * Verifies the signature and decodes the event body.
   */
  public static function decode(string $body, string $timestamp, string $signature, string $secret, int $now, int $maxBody = self::MAX_BODY): array {
    if (strlen($body) > $maxBody) {
      throw new \InvalidArgumentException('Delivery body exceeds limit.');
    }
    if (!preg_match('/^[0-9]{1,12}$/D', $timestamp)) {
      throw new \InvalidArgumentException('Invalid delivery timestamp.');
    }
    if (abs($now - (int) $timestamp) > 300) {
      throw new \InvalidArgumentException('Delivery timestamp outside allowed window.');
    }
    if (!preg_match('/^v1=[a-f0-9]{64}$/D', $signature)) {
      throw new \InvalidArgumentException('Invalid delivery signature format.');
    }
    if (!hash_equals('v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret), $signature)) {
      throw new \InvalidArgumentException('Delivery signature mismatch.');
    }
    $event = json_decode($body, TRUE, 16, JSON_THROW_ON_ERROR);
    if (!is_array($event)) {
      throw new \InvalidArgumentException('Invalid delivery.');
    }
    return $event;
  }

  /**
   * Validates the publication tuple and returns its revision.
   */
  public static function publication(array $event, array $mapping): int {
    $data = $event['data'] ?? [];
    if (($event['specversion'] ?? '') !== '1.0' || !in_array($event['type'] ?? '', [
      'dev.scribe.annotations.published',
      'dev.scribe.transcription.completed',
    ], TRUE) || !is_string($event['id'] ?? NULL) || !$event['id'] || strlen($event['id']) > 255) {
      throw new \InvalidArgumentException('Invalid publication event.');
    }
    foreach ([
      'workspaceId' => 'workspace_id',
      'itemId' => 'item_id',
      'itemImageId' => 'item_image_id',
      'externalReferenceId' => 'external_reference_id',
    ] as $wire => $stored) {
      if (!isset($data[$wire]) || (string) $data[$wire] !== (string) $mapping[$stored]) {
        throw new \InvalidArgumentException('Publication resource mismatch.');
      }
    }
    $field = $event['type'] === 'dev.scribe.transcription.completed' ? 'revision' : 'publishedRevision';
    if ($field === 'revision' && empty($mapping['book_node_id'])) {
      throw new \InvalidArgumentException('Completion requires a book mapping.');
    }
    if (!is_int($data[$field] ?? NULL) || $data[$field] < 1) {
      throw new \InvalidArgumentException('Invalid publication revision.');
    }
    return $data[$field];
  }

}
