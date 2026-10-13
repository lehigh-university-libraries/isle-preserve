<?php

namespace Drupal\islandora_scribe;

use Drupal\Core\File\FileExists;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\file\FileInterface;
use Drupal\Core\Queue\DatabaseQueue;
use Drupal\islandora\IslandoraUtils;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\islandora\MediaSource\MediaSourceService;
use Drupal\media\MediaInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Coordinates Scribe ingestion and publication updates.
 */
final class Integration {

  public function __construct(
    private Connection $database,
    private QueueFactory $queues,
    private LockBackendInterface $lock,
    private EntityTypeManagerInterface $entities,
    private MediaSourceService $mediaSource,
    private ClientInterface $http,
    private RuntimeSettings $settings,
    private FileRepositoryInterface $files,
    private FileSystemInterface $fileSystem,
    private LoggerInterface $logger,
    private IslandoraUtils $utils,
  ) {}

  /**
   * Loads the mapping for an operation.
   */
  public function mapping(string $operation): array|false {
    return $this->database->select('islandora_scribe_mapping', 'm')->fields('m')->condition('operation_id', $operation)->execute()->fetchAssoc();
  }

  /**
   * Loads the mapping for a derivative media entity.
   */
  public function forMedia(int $id): array|false {
    return $this->database->select('islandora_scribe_mapping', 'm')->fields('m')->condition('derivative_media_id', $id)->execute()->fetchAssoc();
  }

  /**
   * Reserve the destination before emitting a derivative request. */
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
        'book_node_id' => 0,
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

  /**
   * Called only by the separately signed Scyllaridae callback. */
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
      $this->database->update('islandora_scribe_mapping')->fields([
        'item_id' => $data['itemId'],
        'item_image_id' => $data['itemImageId'],
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

  /**
   * Bind the reserved file destination on media creation/update. */
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
    if ($media->bundle() !== $mapping['media_bundle'] || !$media->hasField(IslandoraUtils::MEDIA_OF_FIELD) || (int) $media->get(IslandoraUtils::MEDIA_OF_FIELD)->target_id !== (int) $mapping['node_id'] || !$media->hasField(IslandoraUtils::MEDIA_USAGE_FIELD)) {
      throw new \RuntimeException('Scribe derivative entity association mismatch.');
    }
    $terms = array_column($media->get(IslandoraUtils::MEDIA_USAGE_FIELD)->getValue(), 'target_id');
    if (!in_array((string) $mapping['term_id'], array_map('strval', $terms), TRUE)) {
      throw new \RuntimeException('Scribe derivative media-use mismatch.');
    }
    if ((int) $mapping['source_media_id'] === (int) $media->id()) {
      throw new \RuntimeException('Scribe source cannot be its derivative.');
    }
    $this->database->update('islandora_scribe_mapping')->fields([
      'derivative_media_id' => (int) $media->id(),
      'derivative_file_id' => (int) $file->id(),
    ])->condition('operation_id', $mapping['operation_id'])->execute();
  }

  /**
   * Serialize initial delivery with later publication writes. */
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
      [$media, $file] = $this->writeHocrTarget($mapping, $node, $mediaType, $term, $stream, $uri);
      $this->database->update('islandora_scribe_mapping')->fields([
        'derivative_media_id' => (int) $media->id(),
        'derivative_file_id' => (int) $file->id(),
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

  /**
   * Queues a verified publication event for its mapped derivative.
   */
  public function accept(array $event): void {
    // The controller verifies signatures first. Only publication events update
    // derivatives; acknowledge unrelated events so Scribe does not retry them.
    if (!in_array($event['type'] ?? '', ['dev.scribe.annotations.published', 'dev.scribe.transcription.completed'], TRUE)) {
      return;
    }
    $data = $event['data'] ?? [];
    if (!is_array($data) || !is_int($data['workspaceId'] ?? NULL) || $data['workspaceId'] !== $this->settings->get()['workspace_id'] || !is_int($data['itemImageId'] ?? NULL)) {
      throw new \InvalidArgumentException('Invalid workspace or image.');
    }
    $mapping = $this->database->select('islandora_scribe_mapping', 'm')->fields('m')->condition('workspace_id', $data['workspaceId'])->condition('item_image_id', $data['itemImageId'])->execute()->fetchAssoc();
    if (!$mapping) {
      return;
    }
    if (($event['type'] ?? '') === 'dev.scribe.transcription.completed' && empty($mapping['book_node_id'])) {
      return;
    }
    if ((!$mapping['derivative_media_id'] && empty($mapping['book_node_id'])) || !$mapping['ingest_complete']) {
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
        $this->database->insert('islandora_scribe_event')->fields([
          'event_id' => $event['id'],
          'operation_id' => $mapping['operation_id'],
          'revision' => $revision,
        ])->execute();
        // Explicitly use the database queue: event and queue item commit together.
        $queue = $this->queues->get('islandora_scribe_publication', TRUE);
        if (!$queue instanceof DatabaseQueue || !$queue->createItem(['event_id' => $event['id']])) {
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

  /**
   * Applies a queued revision to the mapped derivative.
   */
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
        if (!$mapping['derivative_media_id'] && !empty($mapping['book_node_id'])) {
          $node = $this->entities->getStorage('node')->load($mapping['node_id']);
          $type = $this->entities->getStorage('media_type')->load($mapping['media_bundle']);
          $term = $this->entities->getStorage('taxonomy_term')->load($mapping['term_id']);
          if (!$node || !$type || !$term) { throw new \RuntimeException('Page derivative target unavailable.'); }
          $stream = fopen('php://temp', 'w+'); fwrite($stream, $bytes); rewind($stream);
          try { [$media, $file] = $this->writeHocrTarget($mapping, $node, $type, $term, $stream, $mapping['file_uri']); } finally { fclose($stream); }
          $mapping['derivative_media_id'] = (int) $media->id();
          $mapping['derivative_file_id'] = (int) $file->id();
          $replacement = $file;
          $this->database->update('islandora_scribe_mapping')->fields(['derivative_media_id' => $mapping['derivative_media_id'], 'derivative_file_id' => $mapping['derivative_file_id']])->condition('operation_id', $mapping['operation_id'])->execute();
        }
        else {
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
        }
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

  /**
   * Writes a permanent hOCR file at the staging destination.
   */
  private function writeHocrTarget(array $mapping, $node, $mediaType, $term, $stream, string $uri): array {
      $storage = $this->entities->getStorage('media');
      $ids = $storage->getQuery()->accessCheck(FALSE)
        ->condition(IslandoraUtils::MEDIA_OF_FIELD, $node->id())
        ->condition(IslandoraUtils::MEDIA_USAGE_FIELD, $term->id())
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
        $file = $this->stage($bytes, $uri . '.ingest-' . $mapping['operation_id'] . '.hocr');
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

    return [$media, $file];
  }

  /** Queues an explicit book operation; retries retain the operation ID. */
  public function enqueueBook(NodeInterface $book, int $contextId): void {
    if (!$this->isPagedContent($book)) { throw new \InvalidArgumentException('Select a paged-content node.'); }
    if ($contextId < 1) { throw new \InvalidArgumentException('Select a Scribe context before transcribing a book.'); }
    $queue = $this->queues->get('islandora_scribe_book', TRUE);
    if (!$queue instanceof DatabaseQueue || !$queue->createItem(['node_id' => (int) $book->id(), 'context_id' => $contextId, 'operation_id' => bin2hex(random_bytes(16))])) {
      throw new \RuntimeException('Scribe requires the durable database queue.');
    }
  }

  /** Checks the Islandora model before either book action runs. */
  public function isPagedContent(NodeInterface $node): bool {
    $term = $this->utils->getTermForUri('https://schema.org/Book');
    if (!$term || !$node->hasField(IslandoraUtils::MODEL_FIELD)) { return FALSE; }
    $ids = array_column($node->get(IslandoraUtils::MODEL_FIELD)->getValue(), 'target_id');
    return in_array((string) $term->id(), array_map('strval', $ids), TRUE);
  }

  /** Queues the explicit PDF action without blocking the browser. */
  public function enqueuePdf(NodeInterface $book): void {
    if (!$this->isPagedContent($book)) { throw new \InvalidArgumentException('Select a paged-content node.'); }
    $queue = $this->queues->get('islandora_scribe_book', TRUE);
    if (!$queue instanceof DatabaseQueue || !$queue->createItem(['type' => 'pdf', 'node_id' => (int) $book->id(), 'operation_id' => bin2hex(random_bytes(16))])) { throw new \RuntimeException('Scribe requires the durable database queue.'); }
  }

  /** Applies queued book work, with at most three delayed transient attempts. */
  public function processBookOperation(array $data): void {
    $attempt = (int) ($data['attempt'] ?? 0) + 1;
    try {
      if (($data['type'] ?? '') === 'pdf') {
        $book = $this->entities->getStorage('node')->load($data['node_id'] ?? 0);
        if (!$book) { throw new \InvalidArgumentException('Book unavailable.'); }
        $this->attachPdf($book);
      }
      else { $this->importBook($data); }
    }
    catch (\Throwable $e) {
      if (!$e instanceof \InvalidArgumentException && $attempt < 3) {
        $queue = $this->queues->get('islandora_scribe_book', TRUE);
        if (!$queue instanceof DatabaseQueue) { throw new \RuntimeException('Scribe requires the durable database queue.'); }
        $data['attempt'] = $attempt;
        $id = $queue->createItem($data);
        if (!$id) { throw new \RuntimeException('Unable to queue book retry.'); }
        $queue->delayItem((object) ['item_id' => $id], 60 * $attempt);
      }
      $this->logger->warning('Scribe book operation failed for node @node on attempt @attempt. Check public access, context and credentials before running the action again.', ['@node' => (int) ($data['node_id'] ?? 0), '@attempt' => $attempt]);
    }
  }

  /** Imports the public book manifest and atomically maps its page images. */
  public function importBook(array $operation): void {
    if (!is_int($operation['node_id'] ?? NULL) || !is_int($operation['context_id'] ?? NULL) || $operation['context_id'] < 1 || !preg_match('/^[a-f0-9]{32}$/D', $operation['operation_id'] ?? '')) {
      throw new \InvalidArgumentException('Invalid book operation.');
    }
    $book = $this->entities->getStorage('node')->load($operation['node_id']);
    if (!$book || !$this->isPagedContent($book)) { throw new \InvalidArgumentException('Select a paged-content node.'); }
    $manifest = Url::fromUserInput('/node/' . $book->id() . '/book-manifest', ['absolute' => TRUE])->toString();
    $key = 'islandora_scribe:book:' . $book->id();
    if (!$this->lock->acquire($key, 180)) { throw new \RuntimeException('Book busy; retry.'); }
    $transaction = NULL;
    try {
      $result = $this->rpc('ItemService/ImportManifest', [
        'manifestUrl' => $manifest, 'name' => $book->label(), 'contextId' => (string) $operation['context_id'],
        'externalReferenceId' => $book->uuid(), 'idempotencyKey' => $operation['operation_id'], 'ignoreExistingOcr' => TRUE,
      ]);
      $item = $result['item'] ?? [];
      if (!is_string($item['id'] ?? NULL) || !$item['id'] || ($item['externalReferenceId'] ?? '') !== $book->uuid() || empty($item['images']) || count($item['images']) > 1000) { throw new \RuntimeException('Book import response mismatch.'); }
      $term = $this->termForUri('https://discoverygarden.ca/use#hocr');
      $pages = []; $seen = [];
      foreach ($item['images'] as $image) {
        $source = $this->sourceForCanvas($book, $manifest, $image['canvasUri'] ?? '');
        $pageId = (int) $source->get(IslandoraUtils::MEDIA_OF_FIELD)->target_id;
        $imageId = filter_var($image['id'] ?? NULL, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($imageId === FALSE || isset($seen[$pageId])) { throw new \RuntimeException('Duplicate or invalid page image.'); }
        $seen[$pageId] = TRUE; $pages[] = [$source, $pageId, $imageId];
      }
      $transaction = $this->database->startTransaction();
      foreach ($pages as [$source, $pageId, $imageId]) {
        $pageOperation = substr(hash('sha256', $operation['operation_id'] . ':' . $source->id()), 0, 32);
        $existing = $this->mapping($pageOperation);
        if ($existing) {
          if ($existing['item_id'] !== $item['id'] || (int) $existing['item_image_id'] !== $imageId) { throw new \RuntimeException('Book replay resource mismatch.'); }
          continue;
        }
        $uri = 'private://derivatives/hocr/' . $pageId . '/' . $pageId . '-scribe.hocr';
        $mapping = $this->reserve($source, $uri, $pageId, 'file', (int) $term->id(), $operation['context_id']);
        $this->database->update('islandora_scribe_mapping')->fields([
          'operation_id' => $pageOperation, 'book_node_id' => (int) $book->id(), 'external_reference_id' => $book->uuid(),
          'item_id' => $item['id'], 'item_image_id' => $imageId, 'ingest_complete' => 1,
        ])->condition('operation_id', $mapping['operation_id'])->execute();
      }
    }
    catch (\Throwable $e) { $transaction?->rollBack(); throw $e; }
    finally { unset($transaction); $this->lock->release($key); }
  }

  /** Validates a manifest Canvas against service-file and book membership. */
  public function sourceForCanvas(NodeInterface $book, string $manifest, string $canvas): MediaInterface {
    $base = preg_replace('~/book-manifest$~D', '', $manifest);
    if (!preg_match('~^' . preg_quote($base, '~') . '/canvas/([1-9][0-9]*)$~D', $canvas, $matches)) { throw new \InvalidArgumentException('Canvas is outside the book.'); }
    $media = $this->entities->getStorage('media')->load($matches[1]);
    $page = $media && $media->hasField(IslandoraUtils::MEDIA_OF_FIELD) ? $this->entities->getStorage('node')->load($media->get(IslandoraUtils::MEDIA_OF_FIELD)->target_id) : NULL;
    $members = $page && $page->hasField(IslandoraUtils::MEMBER_OF_FIELD) ? array_column($page->get(IslandoraUtils::MEMBER_OF_FIELD)->getValue(), 'target_id') : [];
    $service = $this->termForUri('http://pcdm.org/use#ServiceFile');
    $uses = $media && $media->hasField(IslandoraUtils::MEDIA_USAGE_FIELD) ? array_column($media->get(IslandoraUtils::MEDIA_USAGE_FIELD)->getValue(), 'target_id') : [];
    if (!$page || !in_array((string) $book->id(), array_map('strval', $members), TRUE) || !in_array((string) $service->id(), array_map('strval', $uses), TRUE)) { throw new \InvalidArgumentException('Canvas source is not a service file on a page of this book.'); }
    return $media;
  }

  /** Attaches a revision-checked searchable PDF only on explicit action. */
  public function attachPdf(NodeInterface $book): void {
    if (!$this->isPagedContent($book)) { throw new \InvalidArgumentException('Select a paged-content node.'); }
    $settings = $this->settings->get();
    $ids = $this->database->select('islandora_scribe_mapping', 'm')->fields('m', ['item_id'])
      ->condition('book_node_id', $book->id())->condition('workspace_id', $settings['workspace_id'])->condition('ingest_complete', 1)->distinct()->execute()->fetchCol();
    if (count($ids) !== 1 || !$ids[0]) { throw new \RuntimeException('Transcribe all book pages with Scribe before attaching a PDF.'); }
    $item = $this->rpc('ItemService/GetItem', ['itemId' => $ids[0]]);
    $revisions = $item['annotationRevisions'] ?? [];
    if (!$revisions || count($revisions) !== count($item['item']['images'] ?? [])) { throw new \RuntimeException('Book transcription is incomplete; retry.'); }
    $export = $this->rpc('ItemService/PrepareItemExport', ['itemId' => $ids[0], 'format' => 'ANNOTATION_EXPORT_FORMAT_PDF', 'expectedRevisions' => $revisions]);
    $url = $export['downloadUrl'] ?? '';
    if (str_starts_with($url, '/v1/item-exports/')) { $url = $settings['base_url'] . $url; }
    if (!str_starts_with($url, $settings['base_url'] . '/v1/item-exports/') || !preg_match('~^/v1/item-exports/[A-Za-z0-9_-]+$~D', parse_url($url, PHP_URL_PATH) ?? '') || parse_url($url, PHP_URL_QUERY) !== NULL) { throw new \RuntimeException('Invalid prepared PDF URL.'); }
    $response = $this->http->request('GET', $url, $this->requestOptions());
    if ($response->getStatusCode() === 409) { throw new \RuntimeException('Book changed; retry PDF attachment.'); }
    if ($response->getStatusCode() !== 200 || strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0])) !== 'application/pdf') { throw new \RuntimeException('PDF download failed; retry.'); }
    $bytes = $this->readBounded($response->getBody(), 134217728);
    if (!str_starts_with($bytes, '%PDF-')) { throw new \RuntimeException('Invalid PDF response.'); }
    // Fetch and validate the complete export before touching the existing PDF.
    $stream = fopen('php://temp', 'w+'); fwrite($stream, $bytes); rewind($stream);
    try {
      $type = $this->entities->getStorage('media_type')->load('document');
      $term = $this->termForUri('http://pcdm.org/use#OriginalFile');
      if (!$type) { throw new \RuntimeException('Document media type unavailable.'); }
      $this->mediaSource->putToNode($book, $type, $term, $stream, 'application/pdf', 'private://derivatives/' . gmdate('Y/m') . '/pc/' . $book->id() . '.pdf');
    }
    finally { fclose($stream); }
  }

  private function termForUri(string $uri) {
    $term = $this->utils->getTermForUri($uri);
    if (!$term) { throw new \InvalidArgumentException('Required media-use term unavailable.'); }
    return $term;
  }

  private function requestOptions(): array {
    $settings = $this->settings->get();
    return ['headers' => ['X-Scribe-API-Key' => $settings['api_key'], 'X-Scribe-Workspace-ID' => (string) $settings['workspace_id'], 'Connect-Protocol-Version' => '1'], 'connect_timeout' => 10, 'timeout' => 100, 'read_timeout' => 10, 'allow_redirects' => FALSE, 'http_errors' => FALSE, 'stream' => TRUE];
  }

  private function rpc(string $method, array $body): array {
    $settings = $this->settings->get();
    $response = $this->http->request('POST', $settings['base_url'] . '/scribe.v1.' . $method, $this->requestOptions() + ['json' => $body]);
    if ($response->getStatusCode() === 409) { throw new \RuntimeException('Book changed; retry.'); }
    if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 500 && $response->getStatusCode() !== 429) { throw new \InvalidArgumentException('Scribe rejected the book request; check access and context.'); }
    if ($response->getStatusCode() !== 200) { throw new \RuntimeException('Scribe request failed; retry.'); }
    return json_decode($this->readBounded($response->getBody(), 20971520), TRUE, 32, JSON_THROW_ON_ERROR);
  }

  private function readBounded($body, int $limit): string {
    $bytes = ''; $deadline = microtime(TRUE) + 100;
    try {
      while (!$body->eof()) {
        if (microtime(TRUE) >= $deadline || strlen($bytes) > $limit) { throw new \RuntimeException('Scribe response exceeds limit.'); }
        $chunk = $body->read(min(65536, $limit - strlen($bytes) + 1));
        if ($chunk === '' && !$body->eof()) { throw new \RuntimeException('Scribe response stalled.'); }
        $bytes .= $chunk;
      }
      if (strlen($bytes) > $limit) { throw new \RuntimeException('Scribe response exceeds limit.'); }
      return $bytes;
    }
    finally { $body->close(); }
  }

  private function stage(string $bytes, string $destination): FileInterface {
    $directory = $this->fileSystem->dirname($destination);
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new \RuntimeException('hOCR destination directory is unavailable.');
    }
    $file = $this->files->writeData($bytes, $destination, FileExists::Replace);
    $file->setMimeType('text/vnd.hocr+html');
    $file->setPermanent();
    $file->save();
    return $file;
  }

  /**
   * Exports the expected published revision from Scribe.
   */
  private function export(array $mapping, int $revision): string {
    $settings = $this->settings->get();
    $deadline = microtime(TRUE) + 100;
    $response = $this->http->request('POST', $settings['base_url'] . '/scribe.v1.AnnotationService/ExportAnnotationPage', [
      'headers' => [
        'X-Scribe-API-Key' => $settings['api_key'],
        'X-Scribe-Workspace-ID' => (string) $settings['workspace_id'],
        'Connect-Protocol-Version' => '1',
      ],
      'json' => [
        'itemImageId' => (string) $mapping['item_image_id'],
        'expectedRevision' => (string) $revision,
        'format' => 'ANNOTATION_EXPORT_FORMAT_HOCR',
      ],
      'connect_timeout' => 10,
      'timeout' => 100,
      'read_timeout' => 10,
      'allow_redirects' => FALSE,
      'stream' => TRUE,
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
