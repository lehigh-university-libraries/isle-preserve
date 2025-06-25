<?php

namespace Drupal\Tests\lehigh_islandora\ExistingSite;

use Drupal\media\Entity\Media;
use Drupal\user\Entity\User;

require_once __DIR__ . '/DerivativeTestBase.php';

/**
 * Test to ensure PDF coverpage gets created when a pdf is created.
 */
class CoverpageTest extends DerivativeTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $source_path = DRUPAL_ROOT . '/modules/custom/lehigh_islandora/tests/assets/pdf/test.pdf';
    parent::setUpTest($source_path);
  }

  /**
   * Run the test.
   */
  public function testPdfDerivative() {
    $admin = User::load(1);
    $this->drupalLogin($admin);

    $nids = [];
    $node = $this->createNode([
      'title' => 'PDF Item',
      'type' => 'islandora_object',
      'uid' => 1,
      'status' => 1,
      'field_model' => lehigh_islandora_get_tid_by_name('Digital Document', 'islandora_models'),
      'field_add_coverpage' => 1,
    ]);

    $nids[] = $node->id();

    $file_storage = \Drupal::entityTypeManager()->getStorage('file');
    /** @var \Drupal\file\FileInterface $entity */
    $file = $file_storage->create([
      'uri' => "public://tests/test.pdf",
      'filename' => "test.pdf",
      'filemime' => 'application/pdf',
    ]);
    $file->save();
    $this->markEntityForCleanup($file);

    $this->createMedia([
      'name' => $node->id() . '.pdf',
      'bundle' => 'document',
      'uid' => 1,
      'field_media_document' => $file->id(),
      'field_media_use' => lehigh_islandora_get_tid_by_name('Original File', 'islandora_media_use'),
      'field_media_of' => $node->id(),
      'status' => 1,
    ]);

    $pdfCreated = FALSE;
    foreach (range(0, 20) as $i) {
      $mid = \Drupal::database()->query('SELECT m.entity_id
        FROM media__field_media_of m
        INNER JOIN media__field_media_use mu ON mu.entity_id = m.entity_id
        WHERE field_media_use_target_id = :tid AND field_media_of_target_id = :nid', [
          ':tid' => lehigh_islandora_get_tid_by_name('Service File', 'islandora_media_use'),
          ':nid' => $node->id(),
        ])->fetchField();
      if ($mid) {
        $media = Media::load($mid);
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
