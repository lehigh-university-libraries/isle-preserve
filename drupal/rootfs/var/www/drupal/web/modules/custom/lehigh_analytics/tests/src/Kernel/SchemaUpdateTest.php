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
    lehigh_analytics_update_11001();
    foreach (lehigh_analytics_schema() as $table => $definition) {
      self::assertTrue($schema->tableExists($table));
    }
    $database->insert('lehigh_analytics_progress')->fields([
      'id' => 1,
      'cursor' => 1234,
      'ready' => 1,
      'signature' => 'UTC:7',
    ])->execute();
    $schema->dropTable('lehigh_analytics_work_totals');
    lehigh_analytics_update_11001();
    lehigh_analytics_update_11001();
    self::assertTrue($schema->tableExists('lehigh_analytics_work_totals'));
    self::assertSame(1234, (int) $database->select('lehigh_analytics_progress', 'p')->fields('p', ['cursor'])->condition('id', 1)->execute()->fetchField());
  }

}
