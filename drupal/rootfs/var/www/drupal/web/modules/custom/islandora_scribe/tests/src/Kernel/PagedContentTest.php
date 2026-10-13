<?php

namespace Drupal\Tests\islandora_scribe\Kernel;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Drupal\file\FileRepositoryInterface;
use Drupal\file\FileInterface;
use Drupal\islandora\IslandoraUtils;
use Drupal\islandora\MediaSource\MediaSourceService;
use Drupal\islandora_scribe\Integration;
use Drupal\islandora_scribe\RuntimeSettings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\MediaInterface;
use Drupal\media\MediaTypeInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;

/**
 * Tests book Canvas ownership and revision-checked explicit PDF attachment.
 */
#[\PHPUnit\Framework\Attributes\Group('islandora_scribe')]
final class PagedContentTest extends KernelTestBase {
  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];
  /**
   * The book integration under test.
   */
  private Integration $integration;
  /**
   * Queued HTTP responses.
   */
  private MockHandler $http;
  /**
   * Captured requests.
   */
  private array $requests = [];
  /**
   * The paged-content node.
   */
  private NodeInterface $book;
  /**
   * Whether the page belongs to the book.
   */
  private bool $member = TRUE;
  /**
   * The number of PDF attachments.
   */
  private int $attachments = 0;
  /**
   * The last PDF destination.
   */
  private string $destination = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $root = dirname(__DIR__, 3);
    require_once $root . '/islandora_scribe.install';
    foreach (['RuntimeSettings', 'SignedEvent', 'Integration'] as $class) {
      require_once $root . '/src/' . $class . '.php';
    }
    foreach (islandora_scribe_schema() as $table => $schema) {
      $this->container->get('database')->schema()->createTable($table, $schema);
    }
    $this->book = $this->createMock(NodeInterface::class);
    $this->book->method('id')->willReturn(100);
    $this->book->method('uuid')->willReturn('book-uuid');
    $this->book->method('label')->willReturn('Test book');
    $model = $this->getMockBuilder(FieldItemList::class)->disableOriginalConstructor()->onlyMethods([
      'getValue',
    ])->getMock();
    $model->method('getValue')->willReturn([['target_id' => 5]]);
    $this->book->method('hasField')->willReturn(TRUE);
    $this->book->method('get')->willReturn($model);
    $members = $this->getMockBuilder(FieldItemList::class)->disableOriginalConstructor()->onlyMethods([
      'getValue',
    ])->getMock();
    $members->method('getValue')->willReturnCallback(fn() => [
[
  'target_id' => $this->member ? 100 : 999,
],
    ]);
    $page = $this->createMock(NodeInterface::class);
    $page->method('hasField')->willReturn(TRUE);
    $page->method('get')->willReturn($members);
    $association = $this->getMockBuilder(FieldItemList::class)->disableOriginalConstructor()->onlyMethods([
      '__get',
    ])->getMock();
    $association->method('__get')->with('target_id')->willReturn(200);
    $uses = $this->getMockBuilder(FieldItemList::class)->disableOriginalConstructor()->onlyMethods([
      'getValue',
    ])->getMock();
    $uses->method('getValue')->willReturn([['target_id' => 5]]);
    $source = $this->createMock(MediaInterface::class);
    $source->method('id')->willReturn(300);
    $source->method('uuid')->willReturn('source-uuid');
    $source->method('hasField')->willReturn(TRUE);
    $source->method('get')->willReturnCallback(fn($field) => $field === IslandoraUtils::MEDIA_OF_FIELD ? $association : $uses);
    $mediaStorage = $this->createMock(EntityStorageInterface::class);
    $mediaStorage->method('load')->with('300')->willReturn($source);
    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $nodeStorage->method('load')->willReturnCallback(fn($id) => (int) $id === 100 ? $this->book : $page);
    $term = $this->createMock(TermInterface::class);
    $term->method('id')->willReturn(5);
    $query = $this->createMock(QueryInterface::class);
    foreach (['accessCheck', 'condition', 'range'] as $method) {
      $query->method($method)->willReturnSelf();
    }
    $query->method('execute')->willReturn([5]);
    $termStorage = $this->createMock(EntityStorageInterface::class);
    $termStorage->method('getQuery')->willReturn($query);
    $termStorage->method('load')->willReturn($term);
    $type = $this->createMock(MediaTypeInterface::class);
    $typeStorage = $this->createMock(EntityStorageInterface::class);
    $typeStorage->method('load')->with('document')->willReturn($type);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->willReturnMap([
    ['media', $mediaStorage],
    ['node', $nodeStorage],
    [
      'taxonomy_term',
      $termStorage,
    ],
    [
      'media_type',
      $typeStorage,
    ],
    ]);
    $mediaSource = $this->createMock(MediaSourceService::class);
    $file = $this->createMock(FileInterface::class);
    $file->method('id')->willReturn(2);
    $mediaSource->method('getSourceFile')->willReturn($file);
    $mediaSource->method('putToNode')->willReturnCallback(function ($node, $type, $term, $stream, $mime, $uri) {
      self::assertSame($this->book, $node);
      self::assertSame('application/pdf', $mime);
      self::assertSame('%PDF-fixture', stream_get_contents($stream));
      $this->attachments++;
      $this->destination = $uri;
      return FALSE;
    });
    $this->http = new MockHandler();
    $stack = HandlerStack::create($this->http);
    $stack->push(Middleware::history($this->requests));
    $settings = new RuntimeSettings(new Settings([
      'islandora_scribe' => [
        'base_url' => 'https://scribe.example',
        'workspace_id' => 42,
        'api_key' => 'test-key',
        'webhook_secret' => str_repeat('w', 32),
        'correlation_secret' => str_repeat('c', 32),
      ],
    ]));
    $utils = $this->createMock(IslandoraUtils::class);
    $utils->method('getTermForUri')->willReturn($term);
    $this->integration = new Integration($this->container->get('database'), $this->container->get('queue'), $this->container->get('lock'), $entities, $mediaSource, new Client([
      'handler' => $stack,
    ]), $settings, $this->createMock(FileRepositoryInterface::class), $this->createMock(FileSystemInterface::class), new NullLogger(), $utils);
    $this->container->get('database')->insert('islandora_scribe_mapping')->fields([
      'operation_id' => str_repeat('a', 32),
      'book_node_id' => 100,
      'workspace_id' => 42,
      'ingest_complete' => 1,
      'item_id' => 'book-item',
      'file_uri' => 'private://page.hocr',
      'uri_hash' => str_repeat('b', 64),
    ])->execute();
  }

  /**
   * Tests canvas must reference service media on book page.
   */
  public function testCanvasMustReferenceServiceMediaOnBookPage(): void {
    self::assertInstanceOf(MediaInterface::class, $this->integration->sourceForCanvas($this->book, 'https://repository.example/node/100/book-manifest', 'https://repository.example/node/100/canvas/300'));
    $this->member = FALSE;
    $this->expectException(\InvalidArgumentException::class);
    $this->integration->sourceForCanvas($this->book, 'https://repository.example/node/100/book-manifest', 'https://repository.example/node/100/canvas/300');
  }

  /**
   * Tests foreign canvas rejected before loading media.
   */
  public function testForeignCanvasRejectedBeforeLoadingMedia(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->integration->sourceForCanvas($this->book, 'https://repository.example/node/100/book-manifest', 'https://repository.example/node/999/canvas/300');
  }

  /**
   * Queues a completed revision vector for the PDF export fixture.
   */
  private function preparePdf(): void {
    $this->http->append(new Response(200, [], json_encode([
      'item' => ['images' => [['id' => '7']]],
      'annotationRevisions' => [
    [
      'itemImageId' => '7',
      'revision' => '3',
    ],
      ],
    ])));
  }

  /**
   * Tests explicit pdf uses complete revision vector and original file slot.
   */
  public function testExplicitPdfUsesCompleteRevisionVectorAndOriginalFileSlot(): void {
    $this->preparePdf();
    $this->http->append(new Response(200, [], json_encode([
      'downloadUrl' => '/v1/item-exports/token',
    ])));
    $this->http->append(new Response(200, ['Content-Type' => 'application/pdf'], '%PDF-fixture'));
    $this->integration->attachPdf($this->book);
    self::assertSame(1, $this->attachments);
    self::assertSame('private://derivatives/' . gmdate('Y/m') . '/pc/100.pdf', $this->destination);
    $body = json_decode((string) $this->requests[1]['request']->getBody(), TRUE);
    self::assertSame([['itemImageId' => '7', 'revision' => '3']], $body['expectedRevisions']);
    self::assertSame('test-key', $this->requests[2]['request']->getHeaderLine('X-Scribe-API-Key'));
  }

  /**
   * Tests revision conflict leaves existing pdf in place.
   */
  public function testRevisionConflictLeavesExistingPdfInPlace(): void {
    $this->preparePdf();
    $this->http->append(new Response(409));
    try {
      $this->integration->attachPdf($this->book);
      self::fail('Conflict accepted');
    }
    catch (\RuntimeException $e) {
      self::assertStringContainsString('retry', $e->getMessage());
    }
    self::assertSame(0, $this->attachments);
  }

  /**
   * Builds the anonymous public manifest fixture.
   */
  private function bookManifest(): Response {
    return new Response(200, [], json_encode([
      'items' => [
    [
      'id' => 'http://localhost/node/100/canvas/300',
      'width' => 1000,
      'height' => 2000,
      'items' => [],
    ],
      ],
    ]));
  }

  /**
   * Builds the imported item and revision fixture.
   */
  private function bookItem(int $revision = 1): Response {
    return new Response(200, [], json_encode([
      'item' => [
        'id' => 'book-item',
        'externalReferenceId' => 'book-uuid',
        'images' => [
    [
      'id' => '7',
      'canvasUri' => 'http://localhost/node/100/canvas/300',
    ],
        ],
      ],
      'annotationRevisions' => [
      [
        'itemImageId' => '7',
        'revision' => (string) $revision,
      ],
      ],
    ]));
  }

  /**
   * Tests book import maps canvas and replays the operation.
   */
  public function testBookImportMapsCanvasAndReplaysTheOperation(): void {
    $operation = ['node_id' => 100, 'context_id' => 123, 'operation_id' => str_repeat('c', 32)];
    $this->http->append($this->bookManifest(), $this->bookItem());
    $this->integration->importBook($operation);
    $this->http->append($this->bookManifest(), $this->bookItem());
    $this->integration->importBook($operation);
    self::assertSame('', $this->requests[0]['request']->getHeaderLine('X-Scribe-API-Key'));
    $body = json_decode((string) $this->requests[1]['request']->getBody(), TRUE);
    self::assertTrue($body['ignoreExistingOcr']);
    self::assertSame('book-uuid', $body['externalReferenceId']);
    self::assertStringStartsWith('book-', $body['idempotencyKey']);
    self::assertSame('123', $body['contextId']);
    self::assertStringEndsWith('/GetItem', $this->requests[3]['request']->getUri()->getPath());
    $mapping = $this->integration->mapping(substr(hash('sha256', str_repeat('c', 32) . ':300'), 0, 32));
    self::assertSame(100, (int) $mapping['book_node_id']);
    self::assertSame(200, (int) $mapping['node_id']);
    self::assertSame(7, (int) $mapping['item_image_id']);
    self::assertSame(1, (int) $mapping['ingest_complete']);
    // The obsolete mapping seeded by setUp has been retired.
    self::assertFalse($this->integration->mapping(str_repeat('a', 32)));
  }

  /**
   * Tests rerun reuses item and retries with the same base revision.
   */
  public function testRerunReusesItemAndRetriesWithTheSameBaseRevision(): void {
    $operation = ['node_id' => 100, 'context_id' => 123, 'operation_id' => str_repeat('c', 32)];
    $this->http->append($this->bookManifest(), $this->bookItem());
    $this->integration->importBook($operation);
    $operation['operation_id'] = str_repeat('d', 32);
    $this->http->append($this->bookManifest(), $this->bookItem(3), new Response(200, [], '{}'));
    $this->integration->importBook($operation);
    $this->http->append($this->bookManifest(), $this->bookItem(4), new Response(200, [], '{}'));
    $this->integration->importBook($operation);
    foreach ([4, 7] as $index) {
      $body = json_decode((string) $this->requests[$index]['request']->getBody(), TRUE);
      self::assertSame('3', $body['expectedRevision']);
      self::assertSame('7', $body['itemImageId']);
    }
    $state = $this->container->get('database')->select('islandora_scribe_book', 'b')->fields('b')->execute()->fetchAll();
    self::assertCount(1, $state);
    self::assertSame('book-item', $state[0]->item_id);
  }

  /**
   * Tests changed painting replaces the active item.
   */
  public function testChangedPaintingReplacesTheActiveItem(): void {
    $operation = ['node_id' => 100, 'context_id' => 123, 'operation_id' => str_repeat('c', 32)];
    $this->http->append($this->bookManifest(), $this->bookItem());
    $this->integration->importBook($operation);
    $manifest = json_decode((string) $this->bookManifest()->getBody(), TRUE);
    $manifest['items'][0]['width'] = 2000;
    $item = json_decode((string) $this->bookItem()->getBody(), TRUE);
    $item['item']['id'] = 'new-book-item';
    $item['item']['images'][0]['id'] = '8';
    $operation['operation_id'] = str_repeat('d', 32);
    $this->http->append(new Response(200, [], json_encode($manifest)), new Response(200, [], json_encode($item)));
    $this->integration->importBook($operation);
    self::assertStringEndsWith('/ImportManifest', $this->requests[3]['request']->getUri()->getPath());
    $rows = $this->container->get('database')->select('islandora_scribe_mapping', 'm')->fields('m')->condition('book_node_id', 100)->execute()->fetchAll();
    self::assertCount(1, $rows);
    self::assertSame('new-book-item', $rows[0]->item_id);
    self::assertSame(8, (int) $rows[0]->item_image_id);
  }

  /**
   * Tests permanent book request is not requeued.
   */
  public function testPermanentBookRequestIsNotRequeued(): void {
    $this->http->append(new Response(400));
    $this->integration->processBookOperation([
      'node_id' => 100,
      'context_id' => 123,
      'operation_id' => str_repeat('c', 32),
    ]);
    self::assertSame(0, $this->container->get('queue')->get('islandora_scribe_book', TRUE)->numberOfItems());
  }

  /**
   * Tests transient book retries stop after three attempts.
   */
  public function testTransientBookRetriesStopAfterThreeAttempts(): void {
    $data = ['node_id' => 100, 'context_id' => 123, 'operation_id' => str_repeat('c', 32)];
    $this->http->append(new Response(503));
    $this->integration->processBookOperation($data);
    $queue = $this->container->get('queue')->get('islandora_scribe_book', TRUE);
    self::assertSame(1, $queue->numberOfItems());
    $data['attempt'] = 2;
    $this->http->append(new Response(503));
    $this->integration->processBookOperation($data);
    self::assertSame(1, $queue->numberOfItems());
  }

  /**
   * Tests pdf action only queues work.
   */
  public function testPdfActionOnlyQueuesWork(): void {
    $this->integration->enqueuePdf($this->book);
    self::assertSame(1, $this->container->get('queue')->get('islandora_scribe_book', TRUE)->numberOfItems());
    self::assertSame([], $this->requests);
  }

}
