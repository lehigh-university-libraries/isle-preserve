<?php

namespace Drupal\Tests\lehigh_islandora\ExistingSite;

use Drupal\Core\File\FileSystemInterface;
use Drupal\user\Entity\User;
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
  public function testFcrepoUploadAndDelete() {
    $admin = User::load(1);
    $this->drupalLogin($admin);

    $file_system = \Drupal::service('file_system');
    $path = '/tmp/test.html';
    $dir = dirname($path);
    $data = 'hello world';
    $file_system->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);
    $file_system->saveData($data, $path, FileSystemInterface::EXISTS_REPLACE);

    $file_storage = \Drupal::entityTypeManager()->getStorage('file');
    /** @var \Drupal\file\FileInterface $entity */
    $file = $file_storage->create([
      'uri' => "fedora://test.html",
      'filename' => "test.html",
      'filemime' => 'text/plain',
      'uid' => 1,
      'status' => 1,
    ]);
    $file_system->copy($path, 'fedora://test.html', FileSystemInterface::EXISTS_REPLACE);

    $file->save();

    $uri = $file->createFileUrl();
    $this->drupalGet($uri);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertEquals($data, $this->getSession()->getPage()->getContent());

    $file->delete();
    $this->drupalGet($uri);
    $this->assertSession()->statusCodeEquals(404);
  }

}
