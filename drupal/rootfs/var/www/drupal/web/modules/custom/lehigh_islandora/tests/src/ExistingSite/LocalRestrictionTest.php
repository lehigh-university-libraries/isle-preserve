<?php

namespace Drupal\Tests\lehigh_islandora\ExistingSite;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use weitzman\DrupalTestTraits\Entity\MediaCreationTrait;
use weitzman\DrupalTestTraits\Entity\NodeCreationTrait;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests around The Preserve's local restriction functionality.
 */
class LocalRestrictionTest extends ExistingSiteBase {

  use MediaCreationTrait;
  use NodeCreationTrait;

  /**
   * The pdf file.
   *
   * @var \Drupal\file\FileInterface
   */
  protected $file;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $source_path = DRUPAL_ROOT . '/modules/custom/lehigh_islandora/tests/assets/pdf/test.pdf';
    $filename = basename($source_path);
    $destination_dir = 'private://tests';
    $fs = \Drupal::service('file_system');
    $fs->prepareDirectory($destination_dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $destination_path = "$destination_dir/$filename";
    $fs->copy($source_path, $destination_path, FileExists::Replace);

    $file_storage = \Drupal::entityTypeManager()->getStorage('file');
    /** @var \Drupal\file\FileInterface $entity */
    $this->file = $file_storage->create([
      'uri' => "private://tests/test.pdf",
      'filename' => "test.pdf",
      'filemime' => 'application/pdf',
      'uid' => 1,
      'status' => 1,
    ]);
    $this->file->save();
    $this->markEntityForCleanup($this->file);

    $this->failOnPhpWatchdogMessages = FALSE;
    $this->ignoreLoggedErrors();
  }

  /**
   * Ensure locally restricted children media can not be viewed.
   */
  public function testParentLocalRestriction() {
    $parent = $this->createNode([
      'title' => 'Parent Node',
      'type' => 'islandora_object',
      'uid' => 1,
      'status' => 1,
      'field_model' => lehigh_islandora_get_tid_by_name('Collection', 'islandora_models'),
      'field_local_restriction' => TRUE,
    ]);

    $node = $this->createNode([
      'title' => 'Item',
      'type' => 'islandora_object',
      'uid' => 1,
      'status' => 1,
      'field_member_of' => $parent->id(),
      'field_model' => lehigh_islandora_get_tid_by_name('Digital Document', 'islandora_models'),
      'field_local_restriction' => FALSE,
    ]);

    $this->createMedia([
      'name' => $node->id() . '.pdf',
      'bundle' => 'document',
      'uid' => 1,
      'field_media_document' => $this->file->id(),
      'field_media_use' => lehigh_islandora_get_tid_by_name('Original File', 'islandora_media_use'),
      'field_media_of' => $node->id(),
      'status' => 1,
    ]);

    $web_assert = $this->assertSession();
    $this->drupalGet($node->toUrl()->toString());
    $web_assert->pageTextContains('This file is locally restricted to on-campus only');

    $uri = $this->file->createFileUrl();
    $html = $this->getSession()->getPage()->getContent();
    $this->assertStringNotContainsString($uri, $html);
    $this->assertStringNotContainsString(urlencode($uri), $html);
    $this->drupalGet($uri, [
      'query' => ['foo' => rand()],
    ]);
    $this->assertSession()->statusCodeEquals(403);

  }

  /**
   * Ensure locally restricted item media can not be viewed.
   */
  public function testItemLocalRestriction() {
    $node = $this->createNode([
      'title' => 'Locally Restricted Node',
      'type' => 'islandora_object',
      'uid' => 1,
      'status' => 1,
      'field_model' => lehigh_islandora_get_tid_by_name('Digital Document', 'islandora_models'),
      'field_local_restriction' => TRUE,
    ]);

    $this->createMedia([
      'name' => $node->id() . '.pdf',
      'bundle' => 'document',
      'uid' => 1,
      'field_media_document' => $this->file->id(),
      'field_media_use' => lehigh_islandora_get_tid_by_name('Original File', 'islandora_media_use'),
      'field_media_of' => $node->id(),
      'status' => 1,
    ]);

    $web_assert = $this->assertSession();
    $this->drupalGet($node->toUrl()->toString());
    $web_assert->pageTextContains('This file is locally restricted to on-campus only');

    $uri = $this->file->createFileUrl();
    $html = $this->getSession()->getPage()->getContent();
    $this->assertStringNotContainsString($uri, $html);
    $this->assertStringNotContainsString(urlencode($uri), $html);
    $this->drupalGet($uri, [
      'query' => ['foo' => rand()],
    ]);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Ensure no local restrictions can be viewed.
   */
  public function testNoLocalRestriction() {
    $node = $this->createNode([
      'title' => 'No Local Restriction',
      'type' => 'islandora_object',
      'uid' => 1,
      'status' => 1,
      'field_model' => lehigh_islandora_get_tid_by_name('Digital Document', 'islandora_models'),
      'field_local_restriction' => FALSE,
    ]);

    $this->createMedia([
      'name' => $node->id() . '.pdf',
      'bundle' => 'document',
      'uid' => 1,
      'field_media_document' => $this->file->id(),
      'field_media_use' => lehigh_islandora_get_tid_by_name('Original File', 'islandora_media_use'),
      'field_media_of' => $node->id(),
      'status' => 1,
    ]);

    $web_assert = $this->assertSession();
    $this->drupalGet($node->toUrl()->toString());

    $uri = $this->file->createFileUrl();
    $html = $this->getSession()->getPage()->getContent();
    $this->assertStringContainsString($uri, $html);
    $web_assert->pageTextNotContains('This file is locally restricted to on-campus only');

    $this->drupalGet($uri, [
      'query' => ['foo' => rand()],
    ]);
    $this->assertSession()->statusCodeEquals(200);
  }

}
