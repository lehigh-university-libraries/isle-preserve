<?php

namespace Drupal\Tests\islandora_scribe\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Scribe schema repair on sites with missing tables.
 */
#[Group('islandora_scribe')]
#[RunTestsInSeparateProcesses]
final class SchemaUpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Earlier updates can start without tables; repair preserves accepted events.
   */
  public function testMissingTablesUpdate(): void {
    require_once dirname(__DIR__, 3) . '/islandora_scribe.install';
    $database = $this->container->get('database');
    $schema = $database->schema();
    islandora_scribe_update_10001();
    self::assertTrue($schema->tableExists('islandora_scribe_mapping'));
    self::assertTrue($schema->fieldExists('islandora_scribe_mapping', 'context_id'));
    $database->insert('islandora_scribe_event')->fields([
      'event_id' => 'accepted',
      'operation_id' => 'operation',
      'revision' => 7,
    ])->execute();
    $schema->dropTable('islandora_scribe_mapping');
    islandora_scribe_update_10003();
    islandora_scribe_update_10003();
    self::assertTrue($schema->fieldExists('islandora_scribe_mapping', 'ingest_complete'));
    self::assertSame(7, (int) $database->select('islandora_scribe_event', 'e')->fields('e', ['revision'])->condition('event_id', 'accepted')->execute()->fetchField());
  }

}
