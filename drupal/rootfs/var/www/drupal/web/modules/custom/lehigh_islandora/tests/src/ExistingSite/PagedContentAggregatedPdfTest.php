<?php

namespace Drupal\Tests\lehigh_islandora\ExistingSite;

use Drupal\media\Entity\Media;
use Drupal\user\Entity\User;

require_once __DIR__ . '/DerivativeTestBase.php';

/**
 * Test to ensure aggregated PDFs get created.
 */
class PagedContentAggregatedPdfTest extends DerivativeTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $source_dir = DRUPAL_ROOT . '/modules/custom/lehigh_islandora/tests/assets/pc';
    parent::setupTest($source_dir);
  }

  /**
   * Run the test.
   */
  public function testAggregatedPdf() {
    $admin = User::load(1);
    $this->drupalLogin($admin);

    $nids = [];
    $parent = $this->createNode([
      'title' => 'Paged Content Item #1',
      'type' => 'islandora_object',
      'uid' => 1,
      'status' => 1,
      'field_model' => lehigh_islandora_get_tid_by_name('Paged Content', 'islandora_models'),
    ]);
    $nids[] = $parent->id();

    $file_storage = \Drupal::entityTypeManager()->getStorage('file');
    foreach (range(0, 5) as $children) {
      $node = $this->createNode([
        'title' => 'Page #' . $children,
        'type' => 'islandora_object',
        'uid' => 1,
        'field_model' => lehigh_islandora_get_tid_by_name('Page', 'islandora_models'),
        'field_member_of' => $parent->id(),
        'status' => 1,
      ]);
      $nids[] = $node->id();

      /** @var \Drupal\file\FileInterface $entity */
      $file = $file_storage->create([
        'uri' => "public://tests/$children.tiff",
        'filename' => "$children.tiff",
        'filemime' => 'image/tiff',
      ]);
      $file->save();
      $this->markEntityForCleanup($file);

      $this->createMedia([
        'name' => 'Page #' . $children,
        'bundle' => 'file',
        'uid' => 1,
        'field_media_file' => $file->id(),
        'field_media_use' => lehigh_islandora_get_tid_by_name('Original File', 'islandora_media_use'),
        'field_media_of' => $node->id(),
        'status' => 1,
      ]);
    }

    $cron = \Drupal::service('cron');
    $cron->run();

    $pdfCreated = FALSE;
    foreach (range(0, 20) as $i) {
      $mid = \Drupal::database()->query('SELECT m.entity_id
        FROM media__field_media_of m
        INNER JOIN media__field_media_use mu ON mu.entity_id = m.entity_id
        WHERE field_media_use_target_id = :tid AND field_media_of_target_id = :nid', [
          ':tid' => lehigh_islandora_get_tid_by_name('Original File', 'islandora_media_use'),
          ':nid' => $parent->id(),
        ])->fetchField();
      if ($mid) {
        $media = Media::load($mid);
        $this->assertEquals($parent->id() . ".pdf", $media->label());
        $pdfCreated = TRUE;
        $this->markEntityForCleanup($media->field_media_document->entity);
        $this->markEntityForCleanup($media);
        break;
      }
      sleep(5);
    }
    $this->assertTrue($pdfCreated, 'PDF was created');
    $ignoreTids = [
      lehigh_islandora_get_tid_by_name('Original File', 'islandora_media_use'),
    ];
    parent::cleanupDerivatives($nids, $ignoreTids);
  }

}
