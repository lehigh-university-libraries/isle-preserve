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
   * Builds a signed book callback payload after the action reserves its ID.
   */
  private function bookRequest(string $mode = 'transcribe', int $context = 123): array {
    return [
      'nodeId' => 100,
      'workspaceId' => 42,
      'contextId' => $context,
      'operationId' => $this->integration->reserveBook($this->book, $mode, $context),
      'phase' => 'plan',
      'mode' => $mode,
    ];
  }

  /**
   * Builds the page mapping returned by the Scribe microservice.
   */
  private function correlation(array $request, string $item = 'book-item', int $revision = 0): array {
    return array_replace($request, [
      'phase' => 'correlate',
      'itemId' => $item,
      'externalReferenceId' => 'book-uuid',
      'sourceSignature' => str_repeat('f', 64),
      'images' => [
        ['id' => 7, 'revision' => $revision, 'canvasUri' => 'http://localhost/node/100/canvas/300'],
      ],
    ]);
  }

  /**
   * Tests that Drupal returns metadata without calling Scribe.
   */
  public function testNodeEventReservesOnlyCorrelationMetadata(): void {
    $request = $this->bookRequest();
    $plan = $this->integration->bookCallback($request);
    self::assertSame('book-uuid', $plan['externalReferenceId']);
    self::assertSame('', $plan['itemId']);
    self::assertSame([], $this->requests);
    self::assertSame(0, $this->container->get('queue')->get('islandora_scribe_book', TRUE)->numberOfItems());
  }

  /**
   * Tests that a replay keeps its fixed revision and retires obsolete mappings.
   */
  public function testMicroserviceCorrelationIsIdempotentAcrossReruns(): void {
    $request = $this->bookRequest();
    $body = $this->correlation($request);
    $this->integration->bookCallback($body);
    $this->integration->bookCallback($body);
    self::assertFalse($this->integration->mapping(str_repeat('a', 32)));
    $next = $this->bookRequest('transcribe', 124);
    $body = $this->correlation($next, 'book-item', 3);
    $this->integration->bookCallback($body);
    $body['images'][0]['revision'] = 4;
    $plan = $this->integration->bookCallback($body);
    self::assertSame([['id' => 7, 'revision' => 3]], $plan['reprocess']);
    self::assertSame([], $this->requests);
    $this->expectException(\InvalidArgumentException::class);
    $this->integration->bookCallback($request);
  }

  /**
   * Tests Canvas ownership before committing any page mapping.
   */
  public function testMicroserviceCannotMapForeignCanvas(): void {
    $body = $this->correlation($this->bookRequest());
    $body['images'][0]['canvasUri'] = 'http://localhost/node/999/canvas/300';
    try {
      $this->integration->bookCallback($body);
      self::fail('Foreign Canvas accepted.');
    }
    catch (\InvalidArgumentException $e) {
      self::assertSame('', $this->integration->bookCallback(array_replace($body, ['phase' => 'plan']))['itemId']);
    }
    self::assertSame([], $this->requests);
  }

  /**
   * Tests that the PDF action only supplies the microservice upload destination.
   */
  public function testPdfNodeRequestReturnsTheOriginalFileDestination(): void {
    $this->integration->bookCallback($this->correlation($this->bookRequest()));
    $plan = $this->integration->bookCallback($this->bookRequest('pdf', 0));
    self::assertSame('book-item', $plan['itemId']);
    self::assertSame('http://localhost/node/100/media/document/5', $plan['uploadUrl']);
    self::assertSame('private://derivatives/' . gmdate('Y/m') . '/pc/100.pdf', $plan['fileUri']);
    self::assertSame([], $this->requests);
  }

  /**
   * Tests that completed pages use the existing durable publication queue.
   */
  public function testCompletedBeforeCorrelationCanBeReconciled(): void {
    $request = $this->bookRequest();
    $this->integration->bookCallback($this->correlation($request));
    $this->integration->bookCallback(array_replace($request, [
      'phase' => 'reconcile',
      'pages' => [['id' => 7, 'revision' => 3]],
    ]));
    self::assertSame(1, $this->container->get('queue')->get('islandora_scribe_publication', TRUE)->numberOfItems());
  }

}
