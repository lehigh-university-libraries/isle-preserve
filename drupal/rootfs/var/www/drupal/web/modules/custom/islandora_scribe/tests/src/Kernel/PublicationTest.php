<?php

namespace Drupal\Tests\islandora_scribe\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\node\NodeInterface;
use Drupal\media\MediaTypeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\islandora\MediaSource\MediaSourceService;
use Drupal\islandora_scribe\Integration;
use Drupal\islandora_scribe\RuntimeSettings;
use Drupal\islandora_scribe\SignedEvent;
use Drupal\media\MediaInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;

/**
 * Exercises durable acceptance, ordering, replacement and storage retry.
 *
 */
#[\PHPUnit\Framework\Attributes\Group('islandora_scribe')]
final class PublicationTest extends KernelTestBase {
  protected static $modules = ['system'];
  private Integration $integration;
  private MockHandler $http;
  private array $requests = [];
  private bool $failStorage = FALSE;
  private int $writes = 0;
  private int $mediaSaves = 0;
  private int $mediaFileId = 20;
  private array $mapping;

  protected function setUp(): void {
    parent::setUp();
    $root = dirname(__DIR__, 3);
    require_once $root . '/islandora_scribe.install';
    foreach (['RuntimeSettings', 'SignedEvent', 'Integration'] as $class) {
      require_once $root . '/src/' . $class . '.php';
    }
    $database = $this->container->get('database');
    foreach (islandora_scribe_schema() as $table => $schema) {
      $database->schema()->createTable($table, $schema);
    }
    $settings = new RuntimeSettings(new Settings(['islandora_scribe' => [
      'base_url' => 'https://scribe.example', 'workspace_id' => 42,
      'webhook_secret' => str_repeat('w', 32), 'correlation_secret' => str_repeat('c', 32),
      'api_key' => 'test-read-key',
    ]]));
    $old = $this->createMock(FileInterface::class);
    $old->method('id')->willReturn(20);
    $old->method('getFileUri')->willReturn('public://test.hocr');
    $new = $this->createMock(FileInterface::class);
    $new->method('id')->willReturn(21);
    $media = $this->createMock(MediaInterface::class);
    $media->method('id')->willReturn(10);
    $media->method('bundle')->willReturn('file');
    $media->method('set')->willReturnCallback(function ($field, $value) use ($media) {
      self::assertSame('field_media_file', $field);
      $this->mediaFileId = $value['target_id'];
      return $media;
    });
    $media->method('save')->willReturnCallback(function () {
      $this->mediaSaves++;
      return 2;
    });
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($media);
    $query = $this->createMock(QueryInterface::class);
    foreach (['accessCheck', 'condition', 'range'] as $method) {
      $query->method($method)->willReturnSelf();
    }
    $query->method('execute')->willReturn([10 => 10]);
    $storage->method('getQuery')->willReturn($query);
    $storage->expects(self::never())->method('create');
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->with('media')->willReturn($storage);
    $source = $this->createMock(MediaSourceService::class);
    $source->method('getSourceFile')->willReturnCallback(fn() => $this->writes ? $new : $old);
    $source->method('getSourceFieldName')->willReturn('field_media_file');
    $files = $this->createMock(FileRepositoryInterface::class);
    $files->method('writeData')->willReturnCallback(function ($bytes) use ($new) {
      self::assertSame('<html>published</html>', $bytes);
      if ($this->failStorage) {
        throw new \RuntimeException('Simulated storage failure');
      }
      $this->writes++;
      return $new;
    });
    $fileSystem = $this->createMock(\Drupal\Core\File\FileSystemInterface::class);
    $fileSystem->method('dirname')->willReturn('public://');
    $fileSystem->method('prepareDirectory')->willReturn(TRUE);
    $this->http = new MockHandler();
    $handler = HandlerStack::create($this->http);
    $handler->push(\GuzzleHttp\Middleware::history($this->requests));
    $this->integration = new Integration($database, $this->container->get('queue'), $this->container->get('lock'), $entities, $source, new Client(['handler' => $handler]), $settings, $files, $fileSystem, new NullLogger());
    $this->mapping = [
      'operation_id' => str_repeat('a', 32), 'workspace_id' => 42,
      'source_media_id' => 1, 'source_file_id' => 2,
      'derivative_media_id' => 10, 'derivative_file_id' => 20, 'ingest_complete' => 1,
      'external_reference_id' => 'source-uuid', 'item_id' => 'item-1', 'item_image_id' => 7,
      'file_uri' => 'public://test.hocr', 'uri_hash' => hash('sha256', 'public://test.hocr'),
    ];
    $database->insert('islandora_scribe_mapping')->fields($this->mapping)->execute();
  }

  private function event(string $id, int $revision): array {
    return ['id' => $id, 'specversion' => '1.0', 'type' => 'dev.scribe.annotations.published', 'data' => [
      'workspaceId' => 42, 'itemId' => 'item-1', 'itemImageId' => 7,
      'externalReferenceId' => 'source-uuid', 'publishedRevision' => $revision,
    ]];
  }

  private function export(int $revision): void {
    $this->http->append(new Response(200, [], json_encode([
      'itemImageId' => '7', 'revision' => (string) $revision,
      'mediaType' => 'text/vnd.hocr+html; charset=utf-8', 'content' => base64_encode('<html>published</html>'),
    ])));
  }

  public function testReadKeyValidation(): void {
    foreach ([NULL, '', "key\r\nInjected: header", ' key ', str_repeat('x', 4097)] as $key) {
      $settings = new RuntimeSettings(new Settings(['islandora_scribe' => [
        'base_url' => 'https://scribe.example', 'workspace_id' => 42,
        'webhook_secret' => str_repeat('w', 32), 'correlation_secret' => str_repeat('c', 32),
        'api_key' => $key,
      ]]));
      try {
        $settings->get();
        self::fail('Invalid read key accepted');
      }
      catch (\RuntimeException $e) {
        self::assertSame('Configure a protected Scribe read API key.', $e->getMessage());
      }
    }
  }

  public function testSignatures(): void {
    $body = json_encode($this->event('one', 1));
    $secret = str_repeat('w', 32);
    $signature = 'v1=' . hash_hmac('sha256', '1000.' . $body, $secret);
    self::assertSame($this->event('one', 1), SignedEvent::decode($body, '1000', $signature, $secret, 1000));
    foreach ([[$body . ' ', '1000', $signature, 1000], [$body, '1000', $signature, 1301], [$body, '1000', 'v2=bad', 1000], [str_repeat('x', 65537), '1000', $signature, 1000]] as [$bytes, $timestamp, $sig, $now]) {
      try {
        SignedEvent::decode($bytes, $timestamp, $sig, $secret, $now);
        self::fail('Invalid signature accepted');
      }
      catch (\InvalidArgumentException $e) {}
    }
  }

  public function testReplayOrderingAndReplacement(): void {
    $this->integration->accept($this->event('new', 3));
    $this->integration->accept($this->event('new', 3));
    self::assertSame(1, (int) $this->container->get('queue')->get('islandora_scribe_publication', TRUE)->numberOfItems());
    $this->export(3);
    $this->integration->apply('new');
    $this->integration->apply('new');
    $this->integration->accept($this->event('old', 2));
    $this->integration->apply('old');
    self::assertSame(1, $this->writes);
    self::assertCount(1, $this->requests);
    $request = $this->requests[0]['request'];
    self::assertSame('test-read-key', $request->getHeaderLine('X-Scribe-API-Key'));
    self::assertSame('42', $request->getHeaderLine('X-Scribe-Workspace-ID'));
    self::assertFalse($request->hasHeader('Authorization'));
    self::assertStringNotContainsString('test-read-key', (string) $request->getUri());
    self::assertSame(1, $this->mediaSaves);
    self::assertSame(21, $this->mediaFileId);
    $mapping = $this->integration->mapping($this->mapping['operation_id']);
    self::assertSame(3, (int) $mapping['last_revision']);
    self::assertSame(21, (int) $mapping['derivative_file_id']);
  }

  public function testCrossResourceRejection(): void {
    foreach (['workspaceId' => 43, 'itemId' => 'other', 'itemImageId' => 8, 'externalReferenceId' => 'other'] as $key => $value) {
      $event = $this->event('invalid', 1);
      $event['data'][$key] = $value;
      try {
        $this->integration->accept($event);
        self::fail('Cross-resource event accepted');
      }
      catch (\InvalidArgumentException $e) {}
    }
    self::assertSame(0, (int) $this->container->get('database')->select('islandora_scribe_event')->countQuery()->execute()->fetchField());
  }

  public function testContextSelectionIsImmutable(): void {
    $source = $this->createMock(MediaInterface::class);
    $source->method('id')->willReturn(100);
    $source->method('uuid')->willReturn('source-context-uuid');
    $mapping = $this->integration->reserve($source, 'public://context-55.hocr', 3, 'file', 4, 55);
    self::assertSame(55, (int) $this->integration->mapping($mapping['operation_id'])['context_id']);
    $replay = $this->integration->reserve($source, 'public://context-55.hocr', 3, 'file', 4, 55);
    self::assertSame($mapping['operation_id'], $replay['operation_id']);
    $this->integration->correlate([
      'operationId' => $mapping['operation_id'], 'externalReferenceId' => 'source-context-uuid',
      'workspaceId' => 42, 'itemId' => 'context-item', 'itemImageId' => 99, 'contextId' => '55',
    ]);
    try {
      $this->integration->correlate([
        'operationId' => $mapping['operation_id'], 'externalReferenceId' => 'source-context-uuid',
        'workspaceId' => 42, 'itemId' => 'context-item', 'itemImageId' => 99, 'contextId' => '56',
      ]);
      self::fail('Changed callback context accepted');
    }
    catch (\InvalidArgumentException $e) {}
    $this->expectException(\RuntimeException::class);
    $this->integration->reserve($source, 'public://context-55.hocr', 3, 'file', 4, 56);
  }

  public function testNewContextReplacesSameDerivativeAndFencesOldEvents(): void {
    $this->container->get('database')->delete('islandora_scribe_mapping')->condition('operation_id', $this->mapping['operation_id'])->execute();
    $source = $this->createMock(MediaInterface::class);
    $source->method('id')->willReturn(100);
    $source->method('uuid')->willReturn('source-context-uuid');
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(3);
    $type = $this->createMock(MediaTypeInterface::class);
    $type->method('id')->willReturn('file');
    $term = $this->createMock(TermInterface::class);
    $term->method('id')->willReturn(4);
    $uri = 'public://single-hocr.hocr';
    $old = $this->integration->reserve($source, $uri, 3, 'file', 4, 11);
    $this->integration->correlate([
      'operationId' => $old['operation_id'], 'externalReferenceId' => 'source-context-uuid',
      'workspaceId' => 42, 'itemId' => 'old-item', 'itemImageId' => 99, 'contextId' => 11,
    ]);
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, '<html>published</html>');
    rewind($stream);
    $this->integration->initialDerivative($old['operation_id'], $node, $type, $term, $stream, $uri);
    rewind($stream);
    $this->integration->initialDerivative($old['operation_id'], $node, $type, $term, $stream, $uri);
    self::assertSame(1, $this->writes);
    $event = $this->event('superseded', 1);
    $event['data']['itemId'] = 'old-item';
    $event['data']['itemImageId'] = 99;
    $event['data']['externalReferenceId'] = 'source-context-uuid';
    $this->integration->accept($event);
    $new = $this->integration->reserve($source, $uri, 3, 'file', 4, 10);
    self::assertNotSame($old['operation_id'], $new['operation_id']);
    $this->integration->apply('superseded');
    self::assertSame(1, $this->writes);
    $this->integration->correlate([
      'operationId' => $new['operation_id'], 'externalReferenceId' => 'source-context-uuid',
      'workspaceId' => 42, 'itemId' => 'new-item', 'itemImageId' => 100, 'contextId' => 10,
    ]);
    rewind($stream);
    $this->integration->initialDerivative($new['operation_id'], $node, $type, $term, $stream, $uri);
    fclose($stream);
    self::assertSame(2, $this->writes);
    self::assertSame(10, (int) $this->integration->mapping($new['operation_id'])['derivative_media_id']);
    self::assertSame(2, $this->mediaSaves);
    $this->expectException(\InvalidArgumentException::class);
    $this->integration->accept($event);
  }

  public function testConflictingReplayIsRejected(): void {
    $this->integration->accept($this->event('same-id', 1));
    $this->expectException(\InvalidArgumentException::class);
    $this->integration->accept($this->event('same-id', 2));
  }

  public function testExportFailureRetries(): void {
    $this->integration->accept($this->event('export-retry', 5));
    $this->http->append(new Response(503, [], '{"code":"unavailable"}'));
    try {
      $this->integration->apply('export-retry');
      self::fail('Failed export acknowledged');
    }
    catch (\RuntimeException $e) {}
    self::assertSame(0, $this->writes);
    self::assertSame(0, (int) $this->integration->mapping($this->mapping['operation_id'])['last_revision']);
    $this->export(5);
    $this->integration->apply('export-retry');
    self::assertSame(5, (int) $this->integration->mapping($this->mapping['operation_id'])['last_revision']);
  }

  public function testStorageFailureRetries(): void {
    $this->integration->accept($this->event('retry', 4));
    $this->export(4);
    $this->failStorage = TRUE;
    try {
      $this->integration->apply('retry');
      self::fail('Storage failure acknowledged');
    }
    catch (\RuntimeException $e) {}
    self::assertSame(0, (int) $this->integration->mapping($this->mapping['operation_id'])['last_revision']);
    $this->failStorage = FALSE;
    $this->export(4);
    $this->integration->apply('retry');
    self::assertSame(4, (int) $this->integration->mapping($this->mapping['operation_id'])['last_revision']);
  }
}
