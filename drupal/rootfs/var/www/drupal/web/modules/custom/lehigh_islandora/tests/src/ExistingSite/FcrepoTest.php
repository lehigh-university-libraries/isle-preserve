<?php

namespace Drupal\Tests\lehigh_islandora\ExistingSite;

use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Test to ensure uploads to Fcrepo work.
 */
class FcrepoTest extends ExistingSiteBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->failOnPhpWatchdogMessages = FALSE;
    $this->ignoreLoggedErrors();
  }

  /**
   * Create and delete a file in fcrepo.
   */
  public function testGcsUploadAndDelete() {
    $admin = User::load(1);
    $this->drupalLogin($admin);

    $file_system = \Drupal::service('file_system');
    $path = '/tmp/test.txt';
    $dir = dirname($path);
    $data = 'hello world';
    $file_system->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);
    $file_system->saveData($data, $path, FileSystemInterface::EXISTS_REPLACE);

    $file_storage = \Drupal::entityTypeManager()->getStorage('file');
    /** @var \Drupal\file\FileInterface $entity */
    $file = $file_storage->create([
      'uri' => "fedora://test.txt",
      'filename' => "test.txt",
      'filemime' => 'text/plain',
    ]);
    $file_system->copy($path, 'fedora://test.txt', FileSystemInterface::EXISTS_REPLACE);

    $file->save();

    $uri = $file->getFileUri();
    $this->drupalGet($uri);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertEquals($data, $this->getSession()->getPage()->getContent());

    $file->delete();
    $this->drupalGet($uri);
    $this->assertSession()->statusCodeEquals(404);
  }

}
