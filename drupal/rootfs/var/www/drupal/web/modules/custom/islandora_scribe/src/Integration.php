<?php

namespace Drupal\islandora_scribe;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\islandora\MediaSource\MediaSourceService;
use Drupal\media\MediaInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

final class Integration {
  public function __construct(
    private Connection $database,
    private QueueFactory $queues,
    private LockBackendInterface $lock,
    private EntityTypeManagerInterface $entities,
    private MediaSourceService $mediaSource,
    private ClientInterface $http,
    private RuntimeSettings $settings,
    private \Drupal\file\FileRepositoryInterface $files,
    private \Drupal\Core\File\FileSystemInterface $fileSystem,
    private LoggerInterface $logger,
  ) {}

  public function mapping(string $operation): array|false {
    return $this->database->select('islandora_scribe_mapping', 'm')->fields('m')->condition('operation_id', $operation)->execute()->fetchAssoc();
  }

  public function forMedia(int $id): array|false {
    return $this->database->select('islandora_scribe_mapping', 'm')->fields('m')->condition('derivative_media_id', $id)->execute()->fetchAssoc();
  }

  /** Reserve the destination before emitting a derivative request. */
  public function reserve(MediaInterface $source, string $uri, int $nodeId, string $bundle, int $termId, int $contextId = 0): array {
    if ($contextId < 0) {
      throw new \InvalidArgumentException('Invalid Scribe processing context ID.');
    }
    $settings = $this->settings->get();
    $file = $this->mediaSource->getSourceFile($source);
    $transaction = $this->database->startTransaction();
    try {
      $existing = $this->database->select('islandora_scribe_mapping', 'm')->fields('m')
        ->condition('node_id', $nodeId)->condition('term_id', $termId)->forUpdate()->execute()->fetchAssoc();
      if ($existing && !$existing['ingest_complete']) {
        if ((int) $existing['source_media_id'] !== (int) $source->id() || (int) $existing['source_file_id'] !== (int) $file->id() || (int) $existing['workspace_id'] !== $settings['workspace_id'] || $existing['media_bundle'] !== $bundle || (int) $existing['context_id'] !== $contextId || $existing['file_uri'] !== $uri) {
          throw new \RuntimeException('A different Scribe ingest is pending for this derivative.');
        }
        return $existing;
      }
      $record = [
        'operation_id' => bin2hex(random_bytes(16)),
        'source_media_id' => (int) $source->id(),
        'node_id' => $nodeId,
        'media_bundle' => $bundle,
        'term_id' => $termId,
        'context_id' => $contextId,
        'source_file_id' => (int) $file->id(),
        'workspace_id' => $settings['workspace_id'],
        'external_reference_id' => $source->uuid(),
        'file_uri' => $uri,
        'uri_hash' => hash('sha256', $uri),
        'item_id' => '',
        'item_image_id' => NULL,
        'last_revision' => 0,
        'ingest_complete' => 0,
      ];
      if ($existing) {
        // Supersede the resource tuple; late events retain the old operation ID.
        $this->database->update('islandora_scribe_mapping')->fields($record)
          ->condition('operation_id', $existing['operation_id'])->execute();
      }
      else {
        $this->database->insert('islandora_scribe_mapping')->fields($record)->execute();
      }
      return $record;
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    finally {
      unset($transaction);
    }
  }

  /** Called only by the separately signed Scyllaridae callback. */
  public function correlate(array $data): void {
    $operation = $data['operationId'] ?? '';
    if (!is_string($operation) || !preg_match('/^[a-f0-9]{32}$/D', $operation)) {
      throw new \InvalidArgumentException('Invalid operation.');
    }
    $key = 'islandora_scribe:' . $operation;
    if (!$this->lock->acquire($key, 120)) {
      throw new \RuntimeException('Operation busy.');
    }
    $transaction = NULL;
    try {
      $transaction = $this->database->startTransaction();
      $mapping = $this->database->select('islandora_scribe_mapping', 'm')->fields('m')->condition('operation_id', $operation)->forUpdate()->execute()->fetchAssoc();
      if (!$mapping || (string) ($data['workspaceId'] ?? '') !== (string) $mapping['workspace_id'] || ($data['externalReferenceId'] ?? '') !== $mapping['external_reference_id'] || (string) ($data['contextId'] ?? 0) !== (string) $mapping['context_id'] || !is_string($data['itemId'] ?? NULL) || !$data['itemId'] || strlen($data['itemId']) > 255 || !is_int($data['itemImageId'] ?? NULL) || $data['itemImageId'] < 1) {
        throw new \InvalidArgumentException('Invalid correlation tuple.');
      }
      if ($mapping['item_id'] && ($mapping['item_id'] !== $data['itemId'] || (int) $mapping['item_image_id'] !== $data['itemImageId'])) {
        throw new \InvalidArgumentException('Operation already associated.');
      }
      $other = $this->database->select('islandora_scribe_mapping', 'm')->fields('m', ['operation_id'])->condition('workspace_id', $mapping['workspace_id'])->condition('item_image_id', $data['itemImageId'])->condition('operation_id', $operation, '<>')->execute()->fetchField();
      if ($other) {
        throw new \InvalidArgumentException('Resource already associated.');
      }
      $this->database->update('islandora_scribe_mapping')->fields(['item_id' => $data['itemId'], 'item_image_id' => $data['itemImageId']])->condition('operation_id', $operation)->execute();
    }
    catch (\Throwable $e) {
      $transaction?->rollBack();
      throw $e;
    }
    finally {
      unset($transaction);
      $this->lock->release($key);
    }
  }

  /** Bind the reserved file destination on media creation/update. */
  public function bind(MediaInterface $media): void {
    $file = $this->mediaSource->getSourceFile($media);
    if (!$file) {
      return;
    }
    $mapping = $this->database->select('islandora_scribe_mapping', 'm')->fields('m')->condition('file_uri', $file->getFileUri())->execute()->fetchAssoc();
    if (!$mapping || !$mapping['item_id']) {
      return;
    }
    if ($mapping['derivative_media_id'] && (int) $mapping['derivative_media_id'] !== (int) $media->id()) {
      throw new \RuntimeException('Scribe destination belongs to another derivative.');
    }
    if ($media->bundle() !== $mapping['media_bundle'] || !$media->hasField(\Drupal\islandora\IslandoraUtils::MEDIA_OF_FIELD) || (int) $media->get(\Drupal\islandora\IslandoraUtils::MEDIA_OF_FIELD)->target_id !== (int) $mapping['node_id'] || !$media->hasField(\Drupal\islandora\IslandoraUtils::MEDIA_USAGE_FIELD)) {
      throw new \RuntimeException('Scribe derivative entity association mismatch.');
    }
    $terms = array_column($media->get(\Drupal\islandora\IslandoraUtils::MEDIA_USAGE_FIELD)->getValue(), 'target_id');
    if (!in_array((string) $mapping['term_id'], array_map('strval', $terms), TRUE)) {
      throw new \RuntimeException('Scribe derivative media-use mismatch.');
    }
    if ((int) $mapping['source_media_id'] === (int) $media->id()) {
      throw new \RuntimeException('Scribe source cannot be its derivative.');
    }
    $this->database->update('islandora_scribe_mapping')->fields(['derivative_media_id' => (int) $media->id(), 'derivative_file_id' => (int) $file->id()])->condition('operation_id', $mapping['operation_id'])->execute();
  }

  /** Serialize initial delivery with later publication writes. */
  public function initialDerivative(string $operation, $node, $mediaType, $term, $stream, string $uri): void {
    $key = 'islandora_scribe:' . $operation;
    if (!$this->lock->acquire($key, 180)) {
      throw new \RuntimeException('Derivative busy.');
    }
    $transaction = NULL;
    try {
      $transaction = $this->database->startTransaction();
      $mapping = $this->database->select('islandora_scribe_mapping', 'm')->fields('m')
        ->condition('operation_id', $operation)->forUpdate()->execute()->fetchAssoc();
      if (!$mapping || !$mapping['item_id'] || (int) $mapping['node_id'] !== (int) $node->id() || $mapping['media_bundle'] !== $mediaType->id() || (int) $mapping['term_id'] !== (int) $term->id() || $mapping['file_uri'] !== $uri) {
        throw new \InvalidArgumentException('Derivative operation mismatch.');
      }
      if ($mapping['ingest_complete']) {
        return;
      }
      $storage = $this->entities->getStorage('media');
      $ids = $storage->getQuery()->accessCheck(FALSE)
        ->condition(\Drupal\islandora\IslandoraUtils::MEDIA_OF_FIELD, $node->id())
        ->condition(\Drupal\islandora\IslandoraUtils::MEDIA_USAGE_FIELD, $term->id())
        ->range(0, 2)->execute();
      if (count($ids) > 1) {
        throw new \RuntimeException('Multiple hOCR media exist for this node; resolve before ingest.');
      }
      if ($ids) {
        $storage->resetCache(array_values($ids));
        $media = $storage->load(reset($ids));
        if ($media->bundle() !== $mediaType->id() || (int) $media->id() === (int) $mapping['source_media_id']) {
          throw new \RuntimeException('Invalid hOCR derivative target.');
        }
        $bytes = stream_get_contents($stream, 33554433);
        if ($bytes === FALSE || $bytes === '' || strlen($bytes) > 33554432) {
          throw new \InvalidArgumentException('Invalid hOCR upload size.');
        }
        // Stage replacement bytes so failed ingest leaves the current hOCR intact.
        $file = $this->stage($bytes, $uri . '.ingest-' . $operation . '.hocr');
        $media->set($this->mediaSource->getSourceFieldName($media->bundle()), ['target_id' => $file->id()]);
        $media->save();
      }
      else {
        $media = $this->mediaSource->putToNode($node, $mediaType, $term, $stream, 'text/vnd.hocr+html', $uri);
        if (!$media) {
          throw new \RuntimeException('hOCR target changed during ingest; retry.');
        }
        $file = $this->mediaSource->getSourceFile($media);
      }
      $this->database->update('islandora_scribe_mapping')->fields([
        'derivative_media_id' => (int) $media->id(), 'derivative_file_id' => (int) $file->id(),
        'ingest_complete' => 1,
      ])->condition('operation_id', $operation)->execute();
    }
    catch (\Throwable $e) {
      $transaction?->rollBack();
      throw $e;
    }
    finally {
      unset($transaction);
      $this->lock->release($key);
    }
  }

  public function accept(array $event): void {
    $data = $event['data'] ?? [];
    if (!is_array($data) || !is_int($data['workspaceId'] ?? NULL) || $data['workspaceId'] !== $this->settings->get()['workspace_id'] || !is_int($data['itemImageId'] ?? NULL)) {
      throw new \InvalidArgumentException('Invalid workspace or image.');
    }
    $mapping = $this->database->select('islandora_scribe_mapping', 'm')->fields('m')->condition('workspace_id', $data['workspaceId'])->condition('item_image_id', $data['itemImageId'])->execute()->fetchAssoc();
    if (!$mapping || !$mapping['derivative_media_id'] || !$mapping['ingest_complete']) {
      throw new \InvalidArgumentException('Unknown derivative.');
    }
    $revision = SignedEvent::publication($event, $mapping);
    $key = 'islandora_scribe:event:' . hash('sha256', $event['id']);
    if (!$this->lock->acquire($key, 30)) {
      throw new \RuntimeException('Delivery busy.');
    }
    try {
      $transaction = $this->database->startTransaction();
      try {
        $existing = $this->database->select('islandora_scribe_event', 'e')->fields('e')->condition('event_id', $event['id'])->execute()->fetchAssoc();
        if ($existing) {
          if ($existing['operation_id'] !== $mapping['operation_id'] || (int) $existing['revision'] !== $revision) {
            throw new \InvalidArgumentException('Event ID conflict.');
          }
          return;
        }
        $this->database->insert('islandora_scribe_event')->fields(['event_id' => $event['id'], 'operation_id' => $mapping['operation_id'], 'revision' => $revision])->execute();
        // Explicitly use the database queue: event and queue item commit together.
        $queue = $this->queues->get('islandora_scribe_publication', TRUE);
        if (!$queue instanceof \Drupal\Core\Queue\DatabaseQueue || !$queue->createItem(['event_id' => $event['id']])) {
          throw new \RuntimeException('Scribe requires the durable database queue.');
        }
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
      finally {
        unset($transaction);
      }
    }
    finally {
      $this->lock->release($key);
    }
  }

  public function apply(string $eventId): void {
    $event = $this->database->select('islandora_scribe_event', 'e')->fields('e')->condition('event_id', $eventId)->execute()->fetchAssoc();
    if (!$event || $event['applied']) {
      return;
    }
    $key = 'islandora_scribe:' . $event['operation_id'];
    if (!$this->lock->acquire($key, 180)) {
      throw new \RuntimeException('Derivative busy.');
    }
    $transaction = NULL;
    try {
      $transaction = $this->database->startTransaction();
      // Fence expired lock leases with a database row lock as well.
      $mapping = $this->database->select('islandora_scribe_mapping', 'm')->fields('m')
        ->condition('operation_id', $event['operation_id'])->forUpdate()->execute()->fetchAssoc();
      if (!$mapping) {
        // This event belongs to a superseded run and must not write any file.
        $this->database->update('islandora_scribe_event')->fields(['applied' => 1])->condition('event_id', $eventId)->execute();
        return;
      }
      if (!$mapping['ingest_complete'] || (int) $mapping['workspace_id'] !== $this->settings->get()['workspace_id']) {
        throw new \RuntimeException('Scribe mapping unavailable.');
      }
      if ((int) $event['revision'] > (int) $mapping['last_revision']) {
        $bytes = $this->export($mapping, (int) $event['revision']);
        $storage = $this->entities->getStorage('media');
        $storage->resetCache([$mapping['derivative_media_id']]);
        $media = $storage->load($mapping['derivative_media_id']);
        $file = $media ? $this->mediaSource->getSourceFile($media) : NULL;
        if (!$file || (int) $file->id() !== (int) $mapping['derivative_file_id']) {
          throw new \RuntimeException('Derivative file association changed.');
        }
        // Stage a new managed file; failed storage cannot truncate the live hOCR.
        $destination = $mapping['file_uri'] . '.' . $mapping['operation_id'] . '.revision-' . $event['revision'] . '.hocr';
        $replacement = $this->stage($bytes, $destination);
        $field = $this->mediaSource->getSourceFieldName($media->bundle());
        $media->set($field, ['target_id' => $replacement->id()]);
        $media->save();
        $this->database->update('islandora_scribe_mapping')->fields([
          'last_revision' => (int) $event['revision'],
          'derivative_file_id' => (int) $replacement->id(),
        ])->condition('operation_id', $mapping['operation_id'])->execute();
      }
      $this->database->update('islandora_scribe_event')->fields(['applied' => 1])->condition('event_id', $eventId)->execute();
    }
    catch (\Throwable $e) {
      $transaction?->rollBack();
      $this->logger->warning('Scribe publication failed for operation @operation; queued for retry.', ['@operation' => $event['operation_id']]);
      throw new \RuntimeException('Scribe publication requires retry.');
    }
    finally {
      unset($transaction);
      $this->lock->release($key);
    }
  }

  private function stage(string $bytes, string $destination): \Drupal\file\FileInterface {
    $directory = $this->fileSystem->dirname($destination);
    if (!$this->fileSystem->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new \RuntimeException('hOCR destination directory is unavailable.');
    }
    $file = $this->files->writeData($bytes, $destination, \Drupal\Core\File\FileExists::Replace);
    $file->setMimeType('text/vnd.hocr+html');
    $file->setPermanent();
    $file->save();
    return $file;
  }

  private function export(array $mapping, int $revision): string {
    $settings = $this->settings->get();
    $deadline = microtime(TRUE) + 100;
    $response = $this->http->request('POST', $settings['base_url'] . '/scribe.v1.AnnotationService/ExportAnnotationPage', [
      'headers' => ['X-Scribe-API-Key' => $settings['api_key'], 'X-Scribe-Workspace-ID' => (string) $settings['workspace_id'], 'Connect-Protocol-Version' => '1'],
      'json' => ['itemImageId' => (string) $mapping['item_image_id'], 'expectedRevision' => (string) $revision, 'format' => 'ANNOTATION_EXPORT_FORMAT_HOCR'],
      'connect_timeout' => 10, 'timeout' => 100, 'read_timeout' => 10, 'allow_redirects' => FALSE, 'stream' => TRUE,
    ]);
    if ($response->getStatusCode() !== 200) {
      throw new \RuntimeException('Export failed.');
    }
    $body = $response->getBody();
    $json = '';
    while (!$body->eof() && strlen($json) <= 46000000) {
      if (microtime(TRUE) >= $deadline) {
        $body->close();
        throw new \RuntimeException('Export timed out.');
      }
      $chunk = $body->read(65536);
      if ($chunk === '' && !$body->eof()) {
        $body->close();
        throw new \RuntimeException('Export stream stalled.');
      }
      $json .= $chunk;
    }
    $body->close();
    if (strlen($json) > 46000000) {
      throw new \RuntimeException('Export exceeds limit.');
    }
    $data = json_decode($json, TRUE, 16, JSON_THROW_ON_ERROR);
    $bytes = base64_decode($data['content'] ?? '', TRUE);
    if ((string) ($data['revision'] ?? '') !== (string) $revision || (string) ($data['itemImageId'] ?? '') !== (string) $mapping['item_image_id'] || strtolower(trim(explode(';', $data['mediaType'] ?? '')[0])) !== 'text/vnd.hocr+html' || !$bytes || strlen($bytes) > 33554432) {
      throw new \RuntimeException('Export response mismatch.');
    }
    return $bytes;
  }
}
