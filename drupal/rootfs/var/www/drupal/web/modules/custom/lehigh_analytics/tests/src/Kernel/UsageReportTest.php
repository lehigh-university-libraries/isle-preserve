<?php

declare(strict_types=1);

namespace Drupal\Tests\lehigh_analytics\Kernel;

use Drupal\lehigh_analytics\Controller\ExplorerController;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Tests\SchemaCheckTestTrait;
use Drupal\Core\Database\Database;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\lehigh_analytics\Controller\ExportController;
use Drupal\lehigh_analytics\Form\ReportForm;
use Drupal\Core\Form\FormState;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Route;

/**
 * Exercises real SQL, metadata filters, report rendering, and CSV output.
 */
#[Group('lehigh_analytics')]
#[RunTestsInSeparateProcesses]
final class UsageReportTest extends KernelTestBase {

  use SchemaCheckTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'node', 'text', 'taxonomy', 'link', 'block', 'entity_metrics', 'lehigh_analytics',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'taxonomy_term'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['system', 'lehigh_analytics']);
    (Role::load('anonymous') ?: Role::create(['id' => 'anonymous', 'label' => 'Anonymous']))->grantPermission('access content')->save();
    NodeType::create(['type' => 'islandora_object', 'name' => 'Object'])->save();
    Vocabulary::create(['vid' => 'islandora_models', 'name' => 'Models'])->save();
    Vocabulary::create(['vid' => 'genre', 'name' => 'Genre'])->save();
    foreach (['field_model' => 'taxonomy_term', 'field_genre' => 'taxonomy_term', 'field_member_of' => 'node'] as $name => $target) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => ['target_type' => $target],
        'cardinality' => -1,
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => 'islandora_object',
        'label' => $name,
      ])->save();
    }
    FieldStorageConfig::create([
      'field_name' => 'field_external_uri',
      'entity_type' => 'taxonomy_term',
      'type' => 'link',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_external_uri',
      'entity_type' => 'taxonomy_term',
      'bundle' => 'islandora_models',
    ])->save();
    $this->container->get('module_handler')->loadInclude('entity_metrics', 'install');
    entity_metrics_install();
    $this->installSchema('node', ['node_access']);
    $this->installSchema('lehigh_analytics', [
      'lehigh_analytics_totals', 'lehigh_analytics_progress',
      'lehigh_analytics_work_totals', 'lehigh_analytics_work_countries',
      'lehigh_analytics_dirty_works',
    ]);
    // Only the media columns read by the reporting join are needed here.
    $schema = $this->container->get('database')->schema();
    $schema->createTable('media__field_media_of', [
    'fields' => [
      'entity_id' => ['type' => 'int'],
      'field_media_of_target_id' => ['type' => 'int'],
      'deleted' => ['type' => 'int', 'default' => 0],
      'langcode' => ['type' => 'varchar', 'length' => 12],
    ]
]);
    $schema->createTable('media_field_data', [
    'fields' => [
      'mid' => ['type' => 'int'],
      'langcode' => ['type' => 'varchar', 'length' => 12],
      'default_langcode' => ['type' => 'int'],
    ]
]);
  }

  /**
   * The counts stay separate and joins neither lose nor multiply events.
   */
  public function testReportsAndExports(): void {
    $db = $this->container->get('database');
    $reports = $this->container->get('lehigh_analytics.reports');
    $document = $this->term('Digital Document', 'https://schema.org/DigitalDocument');
    $collection = $this->term('Collection', 'http://purl.org/dc/dcmitype/Collection');
    $page = $this->term('Page', 'http://id.loc.gov/ontologies/bibframe/part');
    $genre = Term::create(['vid' => 'genre', 'name' => 'Thesis']);
    $genre->save();
    $parent = $this->node('Collection', $collection);
    $first = $this->node('=unsafe spreadsheet title', $document, [
      'field_genre' => [$genre->id(), $genre->id()],
      'field_member_of' => [$parent->id(), $parent->id()],
    ]);
    $second = $this->node('Second document', $document);
    $component = $this->node('Component', $page);
    $unpublished = $this->node('Draft', $document, ['status' => 0]);
    $db->insert('entity_metrics_regions')->fields(['id' => 1, 'country' => 'US'])->execute();
    $db->insert('entity_metrics_regions')->fields(['id' => 2, 'country' => ' us '])->execute();
    $stamp = $reports->periods()['fiscal']['start'];
    $this->event('node', (int) $first->id(), $stamp, 1);
    $this->event('node', (int) $first->id(), $stamp, 2);
    $this->event('node', (int) $first->id(), $stamp, 1, 1);
    $this->event('node', (int) $second->id(), $stamp);
    $this->event('node', (int) $parent->id(), $stamp);
    $this->event('node', (int) $component->id(), $stamp);
    $this->event('node', (int) $unpublished->id(), $stamp);
    $this->event('node', 9999999, $stamp);
    foreach (['en' => 1, 'fr' => 0] as $language => $default) {
      $db->insert('media_field_data')->fields(['mid' => 100, 'langcode' => $language, 'default_langcode' => $default])->execute();
      // Deliberately repeated references to prove they don't multiply counts.
      for ($i = 0; $i < 2; $i++) {
        $db->insert('media__field_media_of')->fields([
          'entity_id' => 100,
          'field_media_of_target_id' => $first->id(),
          'langcode' => $language,
          'deleted' => 0,
        ])->execute();
      }
    }
    $this->event('media', 100, $stamp, 1);
    $this->event('media', 100, $stamp, 999);
    $this->event('media', 101, $stamp);
    $criteria = ['period' => 'fiscal'];
    $this->container->get('lehigh_analytics.rollup')->refresh();
    $types = $reports->report('types', $criteria)['rows'];
    $this->assertSame(['Digital Document', 3, 2], $types[0]);
    $this->assertCount(3, $types);
    $this->assertSame([
      ['US', 2, 1],
      ['Unknown', 3, 1],
    ], array_reverse($reports->report('countries', $criteria)['rows']));
    $views = $reports->report('views', $criteria)['rows'];
    $this->assertCount(2, $views);
    $this->assertSame([(int) $first->id(), '=unsafe spreadsheet title', 2, 2], $views[0]);
    $this->assertCount(1, $reports->report('downloads', $criteria)['rows']);
    $criteria['filters'] = [
      'field_member_of' => [$parent->id()],
      'field_genre' => [$genre->id()],
      'field_model' => [$document],
    ];
    $this->assertSame([['Digital Document', 2, 2]], $reports->report('types', $criteria)['rows']);
    $this->assertSame([['Thesis', 2, 2]], $reports->report('types', ['group' => 'field_genre'] + $criteria)['rows']);
    $this->assertSame([], $reports->report('views', ['filters' => ['field_model' => [$collection]]] + $criteria)['rows']);
    $coverage = $reports->coverage($criteria);
    $this->assertSame($stamp, (int) $coverage['first']);
    $this->assertSame(2, (int) $coverage['page_views']);
    $end = $reports->periods()['fiscal']['end'];
    $this->event('node', (int) $first->id(), $stamp - 1);
    $this->event('node', (int) $first->id(), $end);
    $this->container->get('lehigh_analytics.rollup')->refresh();
    $this->assertSame(2, $reports->report('views', $criteria)['rows'][0][2]);
    $this->assertSame(1, $reports->report('views', ['period' => 'fiscal_ytd'] + $criteria)['rows'][0][2]);

    $controller = ExportController::create($this->container);
    $request = Request::create('/admin/reports/lehigh-analytics/review', 'GET', $criteria);
    $response = $controller->export('views', $request);
    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();
    $this->assertStringContainsString("'=unsafe spreadsheet title", $csv);
    $this->assertStringContainsString('entity_metrics', $csv);
    $this->assertStringContainsString('America/New_York', $csv);
    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    $this->assertStringNotContainsString('ip_address', $csv);

    $request->query->set('report_view', 'views');
    $this->container->get('request_stack')->push($request);
    $form = ReportForm::create($this->container)->buildForm([], new FormState());
    $this->container->get('request_stack')->pop();
    $this->assertSame($criteria['period'], $form['period']['#default_value']);
    $this->assertCount(5, $form['period']['#options']);
    $this->assertCount(1, $form['views']['table']['#rows']);
    $this->assertSame(0, $form['#cache']['max-age']);

    $this->container->get('router.builder')->rebuild();
    $access = $this->container->get('access_manager');
    $user = User::create(['uid' => 10, 'name' => 'Unprivileged', 'status' => 1]);
    $user->save();
    Role::create(['id' => 'analyst', 'label' => 'Analyst', 'permissions' => ['view lehigh analytics']])->save();
    $analyst = User::create(['uid' => 11, 'name' => 'Analyst', 'status' => 1, 'roles' => ['analyst']]);
    $analyst->save();
    foreach (['lehigh_analytics.review' => [], 'lehigh_analytics.export' => ['report' => 'views']] as $route => $parameters) {
      $this->assertFalse($access->checkNamedRoute($route, $parameters, new AnonymousUserSession()));
      $this->assertFalse($access->checkNamedRoute($route, $parameters, $user));
      $this->assertTrue($access->checkNamedRoute($route, $parameters, $analyst));
    }
  }

  /**
   * Media totals retain regions and distinct parents after aggregation.
   */
  public function testMediaTotalsBeforeParentLookup(): void {
    $db = $this->container->get('database');
    $reports = $this->container->get('lehigh_analytics.reports');
    $model = $this->term('Document', 'https://schema.org/DigitalDocument');
    $second = $this->node('Second parent', $model);
    $first = $this->node('First parent', $model, ['field_member_of' => [$second->id()]]);
    foreach ([200 => 'en', 201 => 'fr', 202 => 'en'] as $mid => $default) {
      foreach (['en', 'fr'] as $language) {
        $db->insert('media_field_data')->fields([
          'mid' => $mid,
          'langcode' => $language,
          'default_langcode' => (int) ($language === $default),
        ])->execute();
      }
    }
    foreach ([
      [200, 'en', $first->id(), 0],
      [200, 'en', $first->id(), 0],
      [200, 'en', $second->id(), 0],
      [200, 'fr', $second->id(), 0],
      [201, 'fr', $first->id(), 0],
      [201, 'en', $second->id(), 0],
      [202, 'en', $second->id(), 1],
    ] as [$mid, $language, $parent, $deleted]) {
      $db->insert('media__field_media_of')->fields([
        'entity_id' => $mid,
        'langcode' => $language,
        'field_media_of_target_id' => $parent,
        'deleted' => $deleted,
      ])->execute();
    }
    foreach ([1 => 'US', 2 => 'CA'] as $id => $country) {
      $db->insert('entity_metrics_regions')->fields(['id' => $id, 'country' => $country])->execute();
    }
    $stamp = $reports->periods()['calendar']['start'];
    $this->event('node', (int) $first->id(), $stamp, 1);
    foreach ([[200, 1], [200, 1], [200, 2], [201, 2], [202, 1], [203, 1]] as [$mid, $region]) {
      $this->event('media', $mid, $stamp, $region);
    }
    $this->container->get('lehigh_analytics.rollup')->refresh();
    $criteria = $reports->criteria(['period' => 'calendar']);
    $rows = $reports->report('downloads', $criteria)['rows'];
    $this->assertSame([4, 3], array_column($rows, 3));
    $this->assertSame([['US', 1, 4], ['CA', 0, 3]], $reports->report('countries', $criteria)['rows']);
    // Raw anomaly queries must retain separate events, even at one timestamp.
    $raw = $reports->events($criteria);
    $raw->condition('e.entity_type', 'media');
    $this->assertSame(7, (int) $raw->countQuery()->execute()->fetchField());
    $criteria['filters'] = ['field_member_of' => [$second->id()]];
    $this->assertSame([4], array_column($reports->report('downloads', $criteria)['rows'], 3));
    $this->assertSame([['US', 1, 2], ['CA', 0, 2]], $reports->report('countries', $criteria)['rows']);
  }

  /**
   * Public cold requests never scan history; batched ingestion is resumable and exact.
   */
  public function testSavedTotalsAndPublicAccess(): void {
    $db = $this->container->get('database');
    $rollup = $this->container->get('lehigh_analytics.rollup');
    $reports = $this->container->get('lehigh_analytics.reports');
    $model = $this->term('Digital Document', 'https://schema.org/DigitalDocument');
    $work = $this->node('Public document', $model);
    $draft = $this->node('Secret draft', $model, ['status' => 0]);
    $stamp = $reports->periods()['fiscal']['start'];
    $this->event('node', (int) $work->id(), $stamp);
    $this->event('node', (int) $work->id(), $stamp, NULL, 1);
    $this->event('node', (int) $draft->id(), $stamp);
    $controller = ExplorerController::create($this->container);
    $this->assertSame(503, $controller->data(Request::create('/usage/data'))->getStatusCode());
    $this->assertFalse($rollup->refresh(1));
    $this->assertFalse($rollup->status()['ready']);
    while (!$rollup->refresh(1)) {
    }
    $this->assertTrue($rollup->status()['ready']);
    $this->assertSame(6, (int) $db->select('lehigh_analytics_totals')->countQuery()->execute()->fetchField());
    $this->assertSame(1, $reports->report('summary', [])['rows'][0][0]);
    $rollup->refresh();
    $this->assertSame(1, $reports->report('summary', [])['rows'][0][0]);
    // An event arriving late belongs to its original fiscal year, not arrival year.
    $this->event('node', (int) $work->id(), $stamp - 1);
    $rollup->refresh();
    $this->assertSame(2, $reports->report('summary', [])['rows'][0][0]);
    $this->assertSame(1, $reports->report('summary', ['period' => 'fiscal'])['rows'][0][0]);
    $this->assertNotSame($rollup->bucket('fiscal', $stamp), $rollup->bucket('fiscal', $stamp - 1));
    $this->container->get('cache.default')->deleteAll();
    Database::startLog('public_cold');
    $data = json_decode($controller->data(Request::create('/usage/data'))->getContent(), TRUE);
    $this->assertSame(['updated', 'period', 'summary'], array_keys($data));
    $this->assertSame(2, $data['summary']['rows'][0][0]);
    $this->assertStringNotContainsString('Secret draft', json_encode($data));
    $public_queries = Database::getLog('public_cold');
    foreach ($public_queries as $entry) {
      foreach (['entity_metrics_data', 'lehigh_analytics_totals', 'media__field_media_of', 'entity_metrics_regions'] as $source) {
        $this->assertStringNotContainsString($source, $entry['query']);
      }
    }
    $queries = array_filter($public_queries, static fn(array $entry): bool => str_contains($entry['query'], 'lehigh_analytics_work_totals'));
    $this->assertCount(1, $queries, 'A public request runs only one aggregate report.');
    foreach (['types', 'views', 'downloads', 'collections', 'countries'] as $report) {
      $request = Request::create('/usage/data', 'GET', ['report' => $report]);
      $data = json_decode($controller->data($request)->getContent(), TRUE);
      $this->assertSame(['updated', 'period', $report], array_keys($data));
      $this->assertStringNotContainsString('Secret draft', json_encode($data));
    }
    foreach (['anomalies', 'unknown', ['summary']] as $invalid) {
      try {
        $controller->data(Request::create('/usage/data', 'GET', ['report' => $invalid]));
        $this->fail('Invalid public report selection was accepted.');
      }
      catch (BadRequestHttpException) {
        $this->addToAssertionCount(1);
      }
    }
    $this->container->get('router.builder')->rebuild();
    $access = $this->container->get('access_manager');
    foreach (['lehigh_analytics.explorer', 'lehigh_analytics.data'] as $route) {
      $this->assertTrue($access->checkNamedRoute($route, [], new AnonymousUserSession()));
    }
    $this->assertTrue($access->checkNamedRoute('lehigh_analytics.public_export', ['report' => 'views'], new AnonymousUserSession()));
    $this->assertFalse($access->checkNamedRoute('lehigh_analytics.review', [], new AnonymousUserSession()));
    // Rebuilding derived counts leaves the source intact and produces the same sum.
    $rollup->refresh(5000, TRUE);
    $this->assertSame(2, $reports->report('summary', [])['rows'][0][0]);
    $this->assertSame(4, (int) $db->select('entity_metrics_data')->countQuery()->execute()->fetchField());
    $this->config('lehigh_analytics.settings')->set('timezone', 'UTC')->save();
    $this->assertFalse($rollup->status()['ready']);
  }

  /**
   * Media edits reattribute saved usage without recounting events.
   */
  public function testMediaChanges(): void {
    $db = $this->container->get('database');
    $rollup = $this->container->get('lehigh_analytics.rollup');
    $reports = $this->container->get('lehigh_analytics.reports');
    $model = $this->term('Document', 'https://schema.org/DigitalDocument');
    $first = $this->node('First', $model);
    $second = $this->node('Second', $model);
    $db->insert('media_field_data')->fields(['mid' => 200, 'langcode' => 'en', 'default_langcode' => 1])->execute();
    $db->insert('media__field_media_of')->fields([
      'entity_id' => 200,
      'langcode' => 'en',
      'field_media_of_target_id' => $first->id(),
    ])->execute();
    $stamp = $reports->periods()['calendar']['start'];
    $this->event('node', (int) $first->id(), $stamp);
    $this->event('media', 200, $stamp);
    $this->assertTrue($rollup->refresh());
    $this->assertSame([(int) $first->id(), 'First', 1, 1], $reports->report('downloads', [])['rows'][0]);

    // Exercise the actual entity hook with the original and changed parents.
    $media = $this->createMock(ContentEntityInterface::class);
    $original = $this->createMock(ContentEntityInterface::class);
    foreach ([[$original, $first], [$media, $second]] as [$version, $parent]) {
      $field = $this->createMock(FieldItemListInterface::class);
      $field->method('getValue')->willReturn([['target_id' => $parent->id()]]);
      $version->method('getEntityTypeId')->willReturn('media');
      $version->method('hasField')->with('field_media_of')->willReturn(TRUE);
      $version->method('getUntranslated')->willReturnSelf();
      $version->method('get')->with('field_media_of')->willReturn($field);
    }
    $media->method('getOriginal')->willReturn($original);
    $db->update('media__field_media_of')->fields(['field_media_of_target_id' => $second->id()])->execute();
    lehigh_analytics_entity_update($media);
    lehigh_analytics_entity_update($media);
    $this->assertSame(2, (int) $db->select('lehigh_analytics_dirty_works')->countQuery()->execute()->fetchField());
    $rollup->refresh();
    $this->assertSame([(int) $second->id(), 'Second', 0, 1], $reports->report('downloads', [])['rows'][0]);
    $this->assertSame(1, $reports->report('summary', [])['rows'][0][0]);
    $this->assertSame(1, $reports->report('summary', [])['rows'][0][1]);
    $db->delete('media_field_data')->condition('mid', 200)->execute();
    lehigh_analytics_entity_delete($media);
    $rollup->refresh();
    $this->assertSame([], $reports->report('downloads', [])['rows']);
    $this->assertSame(0, $reports->report('summary', [])['rows'][0][1]);
    $this->assertSame(2, (int) $db->select('entity_metrics_data')->countQuery()->execute()->fetchField());
  }

  /**
   * Node and model edits change report eligibility without a refresh.
   */
  public function testEligibilityFollowsEdits(): void {
    $db = $this->container->get('database');
    $rollup = $this->container->get('lehigh_analytics.rollup');
    $reports = $this->container->get('lehigh_analytics.reports');
    $model = $this->term('Document', 'https://schema.org/DigitalDocument');
    $work = $this->node('Edited work', $model);
    $this->event('node', (int) $work->id(), time() - 60);
    // Events inside the settle window wait, so late-committing IDs aren't skipped.
    $this->event('node', (int) $work->id(), time());
    $this->assertTrue($rollup->refresh());
    $this->assertSame(1, $reports->report('summary', [])['rows'][0][0]);
    $cursor = $rollup->status()['cursor'];
    $this->assertTrue($rollup->refresh());
    $this->assertSame($cursor, $rollup->status()['cursor']);

    $page = $this->term('Page', 'http://id.loc.gov/ontologies/bibframe/part');
    $work->set('field_model', $page)->save();
    $this->assertSame([], $reports->report('views', [])['rows']);
    $this->assertSame(1, $reports->report('summary', [])['rows'][0][0]);
    $work->set('field_model', $model)->save();
    $this->assertCount(1, $reports->report('views', [])['rows']);
    $work->setUnpublished()->save();
    $this->assertSame(0, $reports->report('summary', [])['rows'][0][0]);
    $this->assertSame([], $reports->report('countries', [])['rows']);
    $work->setPublished()->save();
    $this->assertSame(1, $reports->report('summary', [])['rows'][0][0]);

    // Changing what a model term means queues its works for re-flagging.
    $term = Term::load($model);
    $term->set('field_external_uri', ['uri' => 'http://purl.org/dc/dcmitype/Collection'])->save();
    $this->assertSame([(string) $work->id()], $db->select('lehigh_analytics_dirty_works', 'q')->fields('q', ['nid'])->execute()->fetchCol());
    $rollup->refresh();
    $this->assertSame([], $reports->report('views', [])['rows']);

    $work->delete();
    $this->assertSame(0, $reports->report('summary', [])['rows'][0][0]);
    $this->assertSame(0, (int) $db->select('lehigh_analytics_work_totals', 'w')->condition('published', 1)->countQuery()->execute()->fetchField());
  }

  /**
   * Warming leaves the explorer's first unfiltered requests as cache hits.
   */
  public function testWarmCache(): void {
    $reports = $this->container->get('lehigh_analytics.reports');
    $work = $this->node('Warm work', $this->term('Document', 'https://schema.org/DigitalDocument'));
    $this->event('node', (int) $work->id(), time() - 60);
    $this->container->get('lehigh_analytics.rollup')->refresh();
    $reports->warm();
    Database::startLog('warm');
    $controller = ExplorerController::create($this->container);
    foreach (['summary', 'types', 'views', 'downloads', 'collections', 'countries'] as $report) {
      $this->assertSame(200, $controller->data(Request::create('/usage/data', 'GET', ['report' => $report]))->getStatusCode());
    }
    $queries = Database::getLog('warm');
    // Filters in a different order share one cache entry.
    $second = $this->term('Other', 'https://example.com/other');
    $reports->report('summary', ['filters' => ['field_model' => [$second, $work->get('field_model')->target_id]]]);
    Database::startLog('warm_filters');
    $reports->report('summary', ['filters' => ['field_model' => [$work->get('field_model')->target_id, $second]]]);
    foreach (array_merge($queries, Database::getLog('warm_filters')) as $entry) {
      $this->assertStringNotContainsString('lehigh_analytics_work_', $entry['query']);
    }
  }

  /**
   * Administrators cannot warm public caches with access-restricted works.
   */
  public function testAnonymousGrants(): void {
    $this->enableModules(['node_access_test']);
    $db = $this->container->get('database');
    $model = $this->term('Document', 'https://schema.org/DigitalDocument');
    $collection_model = $this->term('Collection', 'http://purl.org/dc/dcmitype/Collection');
    $parent = $this->node('Restricted collection', $collection_model, ['uid' => 2]);
    $public = $this->node('Public work', $model, ['uid' => 2, 'field_member_of' => [$parent->id()]]);
    $private = $this->node('Restricted published work', $this->term('Restricted model', 'https://example.com/restricted'), ['uid' => 2]);
    $db->delete('node_access')->execute();
    $db->insert('node_access')->fields([
      'nid' => $public->id(),
      'langcode' => 'en',
      'fallback' => 1,
      'realm' => 'all',
      'gid' => 0,
      'grant_view' => 1,
      'grant_update' => 0,
      'grant_delete' => 0,
    ])->execute();
    foreach ([$public, $private] as $node) {
      $this->event('node', (int) $node->id(), time() - 60);
    }
    $this->container->get('lehigh_analytics.rollup')->refresh();
    $admin = User::create(['uid' => 1, 'name' => 'Admin', 'status' => 1]);
    $admin->save();
    $this->container->get('current_user')->setAccount($admin);
    $reports = $this->container->get('lehigh_analytics.reports');
    $this->assertSame(1, $reports->report('summary', [])['rows'][0][0]);
    $this->assertSame([(int) $public->id()], array_column($reports->report('views', [])['rows'], 0));
    $this->assertSame([], $reports->report('collections', [])['rows']);
    $controller = ExplorerController::create($this->container);
    $this->assertSame([], json_decode($controller->options('field_member_of', Request::create('/'))->getContent(), TRUE)['rows']);
    // Terms used only by restricted works are not suggested.
    $this->assertSame(['Document'], array_column(json_decode($controller->options('field_model', Request::create('/'))->getContent(), TRUE)['rows'], 'label'));
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $this->assertSame(1, $reports->report('summary', [])['rows'][0][0]);
    Term::load($model)->set('status', 0)->save();
    $this->assertSame('Unspecified', $reports->report('types', [])['rows'][0][0]);
    $this->assertSame([], json_decode($controller->options('field_model', Request::create('/'))->getContent(), TRUE)['rows']);
    $public->setUnpublished()->save();
    $this->assertSame(0, $reports->report('summary', [])['rows'][0][0]);
  }

  /**
   * Suggestions page through every used value and follow period/filter scope.
   */
  public function testMetadataSuggestions(): void {
    $reports = $this->container->get('lehigh_analytics.reports');
    $controller = ExplorerController::create($this->container);
    foreach (['field_subject_general', 'field_resource_type'] as $name) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'taxonomy_term'],
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => 'islandora_object',
      ])->save();
    }
    $this->assertArrayHasKey('field_subject_general', $reports->metadataFields());
    $this->assertArrayNotHasKey('field_resource_type', $reports->metadataFields());
    $models = [];
    for ($i = 0; $i < 52; $i++) {
      $models[] = $this->term(sprintf('Model %02d', $i), 'https://schema.org/DigitalDocument');
    }
    $genres = [];
    foreach (['Used', 'No usage', 'Previous only', 'Download only'] as $label) {
      $term = Term::create(['vid' => 'genre', 'name' => $label]);
      $term->save();
      $genres[$label] = $term->id();
    }
    $collection = $this->node('Parent collection', $this->term('Collection', 'http://purl.org/dc/dcmitype/Collection'));
    $book = $this->node('Parent book', $models[0]);
    $work = $this->node('Used work', $models[0], [
      'field_model' => $models,
      'field_genre' => [$genres['Used']],
      'field_member_of' => [$collection->id(), $book->id()],
    ]);
    $unused = $this->node('Unused work', $models[0], ['field_genre' => [$genres['No usage']]]);
    $previous = $this->node('Previous work', $models[0], ['field_genre' => [$genres['Previous only']]]);
    $stamp = $reports->periods()['fiscal']['start'];
    $this->event('node', (int) $work->id(), $stamp);
    $this->event('node', (int) $previous->id(), $stamp - 1);
    $download = $this->node('Downloaded work', $models[0], ['field_genre' => [$genres['Download only']]]);
    $db = $this->container->get('database');
    $db->insert('media_field_data')->fields(['mid' => 300, 'langcode' => 'en', 'default_langcode' => 1])->execute();
    $db->insert('media__field_media_of')->fields([
      'entity_id' => 300,
      'field_media_of_target_id' => $download->id(),
      'langcode' => 'en',
      'deleted' => 0,
    ])->execute();
    $this->event('media', 300, $stamp);
    $rollup = $this->container->get('lehigh_analytics.rollup');
    $rollup->refresh();
    $options = static fn(string $field, array $input = []): array => json_decode($controller->options($field, Request::create('/', 'GET', $input))->getContent(), TRUE);
    $first = $options('field_model');
    $this->assertCount(50, $first['rows']);
    $this->assertTrue($first['more']);
    $last = $options('field_model', ['offset' => 50]);
    $this->assertSame(['Model 50', 'Model 51'], array_column($last['rows'], 'label'));
    $this->assertFalse($last['more']);
    $this->assertSame(['Model 51'], array_column($options('field_model', ['q' => '51'])['rows'], 'label'));
    $this->assertSame($first, $options('field_model', ['filters' => ['field_model' => [$models[0]]]]), 'Same-field alternatives remain available for OR selection.');
    $this->assertSame(['Download only', 'Previous only', 'Used'], array_column($options('field_genre')['rows'], 'label'));
    $this->assertSame(['Download only', 'Used'], array_column($options('field_genre', ['period' => 'fiscal'])['rows'], 'label'));
    $this->assertSame(['Parent collection'], array_column($options('field_member_of')['rows'], 'label'));
    $scope = ['period' => 'fiscal', 'filters' => ['field_genre' => [$genres['Previous only']]]];
    $this->assertSame([], $options('field_model', $scope)['rows']);
    $available = json_decode($controller->fields(Request::create('/', 'GET', $scope))->getContent(), TRUE);
    $this->assertSame(['field_genre'], $available, 'Empty fields are hidden, while an active field can offer alternatives.');
    $this->assertSame([], $options('field_model', ['q' => '%'])['rows'], 'Search wildcards are literal.');
    foreach ([['offset' => -1], ['offset' => 'bad'], ['q' => str_repeat('x', 101)]] as $input) {
      try {
        $options('field_model', $input);
        $this->fail('Invalid suggestion parameters were accepted.');
      }
      catch (BadRequestHttpException) {
        $this->addToAssertionCount(1);
      }
    }
    try {
      $options('field_resource_type');
      $this->fail('Unsupported metadata was accepted.');
    }
    catch (BadRequestHttpException) {
      $this->addToAssertionCount(1);
    }
    // Refresh invalidates cached choices as soon as a value gains usage.
    $this->event('node', (int) $unused->id(), $stamp);
    $rollup->refresh();
    $this->assertSame(['Download only', 'No usage', 'Used'], array_column($options('field_genre', ['period' => 'fiscal'])['rows'], 'label'));
  }

  /**
   * Repeated history collapses into a small indexed reporting dataset.
   */
  public function testLargeHistoryColdRequest(): void {
    $db = $this->container->get('database');
    $model = $this->term('Document', 'https://schema.org/DigitalDocument');
    $work = $this->node('Frequently used document', $model);
    $stamp = time() - 3600;
    for ($batch = 0; $batch < 100; $batch++) {
      $insert = $db->insert('entity_metrics_data')->fields([
        'entity_type', 'entity_id', 'timestamp', 'session_id', 'cookie_set',
      ]);
      for ($event = 0; $event < 1000; $event++) {
        $insert->values(['node', $work->id(), $stamp, 'fixture', 0]);
      }
      $insert->execute();
    }
    $rollup = $this->container->get('lehigh_analytics.rollup');
    while (!$rollup->refresh()) {
    }
    $this->assertSame(3, (int) $db->select('lehigh_analytics_totals')->countQuery()->execute()->fetchField());
    $this->container->get('cache.default')->deleteAll();
    Database::startLog('large_history');
    $controller = ExplorerController::create($this->container);
    $response = $controller->data(Request::create('/usage/data'));
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame(100000, $data['summary']['rows'][0][0]);
    $response = $controller->data(Request::create('/usage/data', 'GET', ['report' => 'views']));
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame(100000, $data['views']['rows'][0][2]);
    foreach (Database::getLog('large_history') as $entry) {
      $this->assertStringNotContainsString('entity_metrics_data', $entry['query']);
    }
  }

  /**
   * Invalid field names cannot be interpolated into SQL.
   */
  public function testInvalidFilters(): void {
    $reports = $this->container->get('lehigh_analytics.reports');
    foreach ([
      ['period' => ['all']],
      ['group' => 'field_model; DROP TABLE node'],
      ['filters' => ['field_model' => ['1 OR 1=1']]],
      ['filters' => ['made_up_field' => [1]]],
      ['filters' => 'invalid'],
      ['period' => 'invalid'],
      ['minimum_views' => 0],
      ['baseline_days' => 999],
      ['anomaly_scope' => 'invalid'],
    ] as $input) {
      try {
        $reports->criteria($input);
        $this->fail('Invalid report criteria were accepted.');
      }
      catch (BadRequestHttpException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * The landing page doesn't scan events; repeated reports use the result cache.
   */
  public function testLandingPageAndReportCache(): void {
    $request = Request::create('/admin/reports/lehigh-analytics/review');
    $this->container->get('request_stack')->push($request);
    Database::startLog('lehigh_landing');
    $form = ReportForm::create($this->container)->buildForm([], new FormState());
    $this->container->get('request_stack')->pop();
    foreach (Database::getLog('lehigh_landing') as $entry) {
      $this->assertStringNotContainsString('entity_metrics_data', $entry['query']);
    }
    $this->assertArrayHasKey('instructions', $form);
    $this->assertSame('fiscal', $form['period']['#default_value']);
    $reports = $this->container->get('lehigh_analytics.reports');
    $this->container->get('lehigh_analytics.rollup')->refresh();
    $first = $reports->report('types', []);
    Database::startLog('lehigh_cached');
    $this->assertSame($first, $reports->report('types', []));
    foreach (Database::getLog('lehigh_cached') as $entry) {
      $this->assertStringNotContainsString('entity_metrics_data', $entry['query']);
    }
  }

  /**
   * Rankings stop at 100 and resolve tied counts deterministically.
   */
  public function testTop100(): void {
    $model = $this->term('Digital Document', 'https://schema.org/DigitalDocument');
    $reports = $this->container->get('lehigh_analytics.reports');
    $stamp = $reports->periods()['calendar']['start'];
    $ids = [];
    for ($i = 0; $i < 102; $i++) {
      $node = $this->node('Document ' . $i, $model);
      $ids[] = (int) $node->id();
      $this->event('node', (int) $node->id(), $stamp);
    }
    $this->container->get('lehigh_analytics.rollup')->refresh();
    $rows = $reports->report('views', ['period' => 'calendar'])['rows'];
    $this->assertCount(100, $rows);
    $this->assertSame(array_slice($ids, 0, 100), array_column($rows, 0));
    $this->assertSame([], $reports->report('downloads', [])['rows']);
    $image = $this->term('Image', 'http://purl.org/coar/resource_type/c_c513');
    for ($i = 0; $i < 2; $i++) {
      $node = $this->node('Image ' . $i, $image);
      $this->event('node', (int) $node->id(), $stamp);
    }
    $this->container->get('lehigh_analytics.rollup')->refresh();
    $rows = $reports->report('top_types_views', ['period' => 'calendar'])['rows'];
    $this->assertCount(102, $rows);
    $this->assertSame(['Digital Document', 1, $ids[0]], array_slice($rows[0], 0, 3));
    $this->assertSame(['Image', 1], array_slice($rows[100], 0, 2));
    $this->assertSame(['Image', 2], array_slice($rows[101], 0, 2));
    $this->assertSame([], $reports->report('top_types_downloads', [])['rows']);
  }

  /**
   * Collection counts, exports, and contextual blocks share the same filters.
   */
  public function testCollectionBlocksAndMappings(): void {
    $reports = $this->container->get('lehigh_analytics.reports');
    $model = $this->term('Digital Document', 'https://schema.org/DigitalDocument');
    $collection_model = $this->term('Collection', 'http://purl.org/dc/dcmitype/Collection');
    $first = $this->node('First collection', $collection_model);
    $second = $this->node('Second collection', $collection_model);
    $work = $this->node('Shared work', $model, ['field_member_of' => [$first->id(), $first->id(), $second->id()]]);
    $other = $this->node('Other work', $model, ['field_member_of' => [$second->id()]]);
    $stamp = $reports->periods()['fiscal']['start'];
    $this->event('node', (int) $work->id(), $stamp);
    $this->event('node', (int) $other->id(), $stamp);
    $this->event('node', (int) $first->id(), $stamp);
    $this->container->get('lehigh_analytics.rollup')->refresh();
    $rows = $reports->report('collections', ['period' => 'fiscal'])['rows'];
    $this->assertSame([
      [(int) $second->id(), 'Second collection', 2, 2, 0],
      [(int) $first->id(), 'First collection', 1, 1, 0],
    ], $rows);
    $this->assertCount(1, $reports->report('collections', ['filters' => ['field_member_of' => [$first->id()]]])['rows']);
    $criteria = $reports->criteria(['period' => 'fiscal', 'filters' => ['field_member_of' => [$first->id()]]]);
    $this->container->set('current_route_match', new RouteMatch('entity.node.canonical', new Route('/node/{node}'), ['node' => $second]));
    $manager = $this->container->get('plugin.manager.block');
    $block = $manager->createInstance('lehigh_analytics_usage', [
      'report' => 'views',
      'scope' => 'current_collection',
      'criteria' => $criteria,
    ]);
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->with('view lehigh analytics')->willReturn(TRUE);
    $this->assertTrue($block->access($account));
    $this->assertTrue($block->access(new AnonymousUserSession()));
    $build = $block->build();
    $this->assertCount(2, $build['table']['#rows']);
    $this->assertSame([(int) $second->id()], $build['export']['#url']->getOption('query')['filters']['field_member_of']);
    // Render-cacheable until usage, metadata, or the day's period labels change.
    $this->assertGreaterThan(0, $build['#cache']['max-age']);
    $this->assertContains('lehigh_analytics', $build['#cache']['tags']);
    $this->assertContains('node:' . $second->id(), $build['#cache']['tags']);
    $this->assertContains('route', $block->getCacheContexts());
    $saved = $manager->createInstance('lehigh_analytics_usage', [
      'report' => 'views',
      'scope' => 'filters',
      'criteria' => $criteria,
    ]);
    $this->assertCount(1, $saved->build()['table']['#rows']);
    $controls = $saved->blockForm([], new FormState());
    $this->assertSame('fiscal', $controls['period']['#default_value']);
    $this->assertArrayHasKey('field_model', $controls['filters']);
    $state = (new FormState())->setValues([
      'report' => 'downloads',
      'scope' => 'filters',
      'period' => 'calendar',
      'group' => 'field_model',
      'filters' => ['field_model' => [['target_id' => $model]]],
      'anomaly' => ['baseline_days' => 14, 'spike_multiplier' => 8, 'minimum_views' => 100, 'session_share' => 75],
    ]);
    $saved->blockSubmit([], $state);
    $config = $saved->getConfiguration();
    $this->assertSame(14, $config['criteria']['baseline_days']);
    $this->assertSame([$model], $config['criteria']['filters']['field_model']);
    $this->assertConfigSchema($this->container->get('config.typed'), 'block.block.usage', [
      'plugin' => 'lehigh_analytics_usage',
      'settings' => $config,
    ]);

    foreach ([NULL, $work] as $context) {
      $this->container->set('current_route_match', new RouteMatch('example', new Route('/example'), ['node' => $context]));
      $hidden = $manager->createInstance('lehigh_analytics_usage', ['scope' => 'current_collection']);
      $this->assertFalse($hidden->access($account));
      $this->assertArrayNotHasKey('table', $hidden->build());
    }
    $counter = $reports->report('counter', $criteria);
    $this->assertSame(1, $counter['rows'][0][2]);
    $this->assertSame(0, $counter['rows'][1][2]);
    $this->assertSame('Unavailable', $counter['rows'][2][2]);
    $this->assertStringContainsString('Provisional', $counter['rows'][0][4]);
    $this->assertStringContainsString('Q51', $reports->report('acrl', $criteria)['rows'][0][0]);
    $controller = ExportController::create($this->container);
    foreach (['counter', 'acrl', 'collections'] as $report) {
      $response = $controller->export($report, Request::create('/', 'GET', $criteria));
      ob_start();
      $response->sendContent();
      $csv = ob_get_clean();
      $this->assertStringContainsString($reports->periods()['fiscal']['label'], $csv);
      $this->assertStringNotContainsString('session_id', $csv);
    }
  }

  /**
   * Baselines include prior-period history, zeros, and session-quality evidence.
   */
  public function testAnomalies(): void {
    $reports = $this->container->get('lehigh_analytics.reports');
    $model = $this->term('Digital Document', 'https://schema.org/DigitalDocument');
    $collection_model = $this->term('Collection', 'http://purl.org/dc/dcmitype/Collection');
    $day = ((int) floor($reports->periods()['fiscal']['start'] / 86400) + 3) * 86400;
    $parent = $this->node('Collection', $collection_model, ['created' => $day - 90 * 86400]);
    $works = [];
    foreach (['concentrated', 'distributed', 'missing', 'new'] as $kind) {
      $work = $this->node($kind, $model, [
        'created' => $kind === 'new' ? $day : $day - 90 * 86400,
        'field_member_of' => [$parent->id(), $parent->id()],
      ]);
      $works[$kind] = (int) $work->id();
      if ($kind !== 'new') {
        for ($i = 1; $i <= 29; $i++) {
          $this->event('node', (int) $work->id(), $day - $i * 86400);
        }
      }
      for ($i = 0; $i < 50; $i++) {
        $session = match ($kind) {
          'distributed' => 'reader-' . $i,
          'missing' => '',
          default => 'single-reader',
        };
        $this->event('node', (int) $work->id(), $day + $i, NULL, 0, $session);
      }
    }
    $criteria = ['period' => 'fiscal'];
    $this->container->get('lehigh_analytics.rollup')->refresh();
    $rows = $reports->report('anomalies', $criteria)['rows'];
    $this->assertCount(3, $rows);
    $this->assertSame(1.0, $rows[0][4]);
    $this->assertSame(50.0, $rows[0][5]);
    $this->assertSame(100.0, $rows[0][7]);
    $this->assertStringContainsString('Concentrated', $rows[0][9]);
    $this->assertSame(50, $rows[1][6]);
    $this->assertSame(2.0, $rows[1][7]);
    $this->assertStringContainsString('Distributed', $rows[1][9]);
    $this->assertSame(50, $rows[2][8]);
    $this->assertStringContainsString('Insufficient', $rows[2][9]);
    $this->assertSame([], $reports->report('anomalies', ['minimum_views' => 51] + $criteria)['rows']);
    $filtered = ['filters' => ['field_member_of' => [$parent->id()]], 'anomaly_scope' => 'collection'] + $criteria;
    $collections = $reports->report('anomalies', $filtered)['rows'];
    $this->assertCount(1, $collections);
    $this->assertSame(200, $collections[0][3]);
    $this->assertSame(3.0, $collections[0][4]);
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(TRUE);
    $this->container->get('current_user')->setAccount($account);
    $response = ExportController::create($this->container)->export('anomalies', Request::create('/', 'GET', $criteria));
    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();
    $this->assertStringContainsString('baseline_days', $csv);
    $this->assertStringNotContainsString('single-reader', $csv);
    $this->assertStringNotContainsString('reader-0', $csv);
  }

  /**
   * Creates a model identified by its stable URI.
   */
  private function term(string $name, string $uri): int {
    $term = Term::create(['vid' => 'islandora_models', 'name' => $name, 'field_external_uri' => ['uri' => $uri]]);
    $term->save();
    return (int) $term->id();
  }

  /**
   * Creates a published repository work.
   */
  private function node(string $title, int $model, array $extra = []): Node {
    $node = Node::create($extra + [
      'type' => 'islandora_object',
      'title' => $title,
      'field_model' => $model,
      'status' => 1,
      'langcode' => 'en',
    ]);
    $node->save();
    return $node;
  }

  /**
   * Adds a source event using the real Entity Metrics schema.
   */
  private function event(string $type, int $id, int $timestamp, ?int $region = NULL, int $staff = 0, string $session = 'test'): void {
    $this->container->get('database')->insert('entity_metrics_data')->fields([
      'entity_type' => $type,
      'entity_id' => $id,
      'timestamp' => $timestamp,
      'session_id' => $session,
      'region_id' => $region,
      'cookie_set' => $staff,
    ])->execute();
    // Fixture mutations should be visible immediately rather than after TTL.
    $this->container->get('cache_tags.invalidator')->invalidateTags(['lehigh_analytics']);
  }

}
