<?php

namespace Drupal\Tests\lehigh_islandora\ExistingSite;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\media\Entity\Media;
use weitzman\DrupalTestTraits\ExistingSiteBase;
use weitzman\DrupalTestTraits\Entity\MediaCreationTrait;
use weitzman\DrupalTestTraits\Entity\NodeCreationTrait;

/**
 * Test to ensure PDF gets created when a microsoft document is created.
 */
class DerivativeTestBase extends ExistingSiteBase {

  use MediaCreationTrait;
  use NodeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUpTest($source_path): void {
    parent::setUp();

    // Copy test files into place.
    if (is_dir($source_path)) {
      $destination_dir = 'public://tests';
      $fs = \Drupal::service('file_system');
      $fs->prepareDirectory($destination_dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $files = scandir($source_path);
      foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
          continue;
        }

        $source = $source_path . '/' . $file;
        $destination_path = $destination_dir . '/' . $file;

        $fs->copy($source, $destination_path, FileExists::Replace);
      }
    }
    else {
      $filename = basename($source_path);
      $destination_dir = 'public://tests';
      $fs = \Drupal::service('file_system');
      $fs->prepareDirectory($destination_dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $destination_path = "$destination_dir/$filename";
      $fs->copy($source_path, $destination_path, FileExists::Replace);
    }

    $this->failOnPhpWatchdogMessages = FALSE;
    $this->ignoreLoggedErrors();
  }

  /**
   * Run the test.
   */
  protected function cleanupDerivatives($nids, $tids) {
    $mids = \Drupal::database()->query('SELECT m.entity_id
      FROM media__field_media_of m
      INNER JOIN media__field_media_use mu ON mu.entity_id = m.entity_id
      WHERE field_media_of_target_id IN (:nids[])
        AND field_media_use_target_id NOT IN (:tids[])', [
          ':tids[]' => $tids,
          ':nids[]' => $nids,
        ])->fetchCol();

    foreach ($mids as $mid) {
      $media = Media::load($mid);

      $file = FALSE;
      switch ($media->bundle()) {
        case 'document':
          $file = $media->field_media_document->entity;
          break;

        case 'file':
        case 'fits_technical_metadata':
          $file = $media->field_media_file->entity;
          break;

        case 'image':
          $file = $media->field_media_image->entity;
          break;
      }
      if ($file) {
        $this->markEntityForCleanup($file);
      }
      $this->markEntityForCleanup($media);
    }
  }

}
