<?php

namespace Drupal\Tests\lehigh_analytics\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests analytics schema repair without losing existing usage state.
 */
#[Group('lehigh_analytics')]
#[RunTestsInSeparateProcesses]
final class SchemaUpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Creates missing tables, repairs partial installs, and preserves data.
   */
  public function testMissingTablesUpdate(): void {
    require_once dirname(__DIR__, 3) . '/lehigh_analytics.install';
    $database = $this->container->get('database');
    $schema = $database->schema();
    // The original 11001 update created only these two tables.
    $definitions = lehigh_analytics_schema();
    foreach (['lehigh_analytics_totals', 'lehigh_analytics_progress'] as $table) {
      $schema->createTable($table, $definitions[$table]);
    }
    $database->insert('lehigh_analytics_progress')->fields([
      'id' => 1,
      'cursor' => 1234,
      'ready' => 1,
      'signature' => 'UTC:7',
    ])->execute();
    $registry = $this->container->get('update.update_hook_registry');
    $registry->setInstalledVersion('lehigh_analytics', 11001);
    self::assertContains(11002, $registry->getAvailableUpdates('lehigh_analytics'));
    lehigh_analytics_update_11002();
    foreach ($definitions as $table => $definition) {
      self::assertTrue($schema->tableExists($table));
    }
    $schema->dropTable('lehigh_analytics_work_totals');
    lehigh_analytics_update_11002();
    lehigh_analytics_update_11002();
    self::assertTrue($schema->tableExists('lehigh_analytics_work_totals'));
    self::assertSame(1234, (int) $database->select('lehigh_analytics_progress', 'p')->fields('p', ['cursor'])->condition('id', 1)->execute()->fetchField());
  }

}
