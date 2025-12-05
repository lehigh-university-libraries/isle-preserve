<?php

namespace Drupal\lehigh_islandora\Commands;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileExists;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drush\Commands\DrushCommands;

/**
 * Defines Drush commands for content migration.
 */
class PublishSubmission extends DrushCommands {

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructs a new MyMigrationCommands object.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   */
  public function __construct(FileSystemInterface $file_system) {
    $this->fileSystem = $file_system;
  }

  /**
   * Migrates files from a node's field_file_uploads to new media entities.
   *
   * @param int $nid
   *   The Node ID of the node to process.
   *
   * @command lehigh-islandora:publish-submission
   * @aliases isle-publish-submission
   * @usage lehigh-islandora:publish-submission 123
   * Publishes node 123, creating media entities for each file.
   */
  public function migrateFiles(int $nid) {
    $node = Node::load($nid);
    if (!$node) {
      $this->io()->error(dt('Node with ID @nid not found.', ['@nid' => $nid]));
      return;
    }
    $file_field_name = 'field_file_uploads';

    if (!$node->hasField($file_field_name)) {
      $this->io()->error(dt('Node @nid does not have the field_file_uploads field.', ['@nid' => $nid]));
      return;
    }
    if ($node->get($file_field_name)->isEmpty()) {
      $this->io()->note(dt('Node @nid has no files in @field_name. Skipping.', [
        '@nid' => $nid,
        '@field_name' => $file_field_name,
      ]));
      return;
    }

    $media_bundle_id = 'document';
    $media_file_field = 'field_media_document';
    $files_processed = 0;
    foreach ($node->get($file_field_name) as $file) {
      /** @var \Drupal\file\FileInterface $file */
      $file = $file->entity ?? NULL;
      $original_uri = $file->getFileUri();
      $file_name = $file->getFilename();
      $mime_type = $file->getMimeType();
      if ($mime_type === 'application/pdf') {
        $media_bundle_id = 'document';
        $media_file_field = 'field_media_document';
        $this->io()->text(dt('Processing PDF file: @filename.', ['@filename' => $file_name]));
      }
      else {
        $this->io()->error(dt('File "@filename" has unsupported MIME type "@mime". Exiting script.', [
          '@filename' => $file_name,
          '@mime' => $mime_type,
        ]));
        return;
      }

      $destination_uri = 'fedora://' . $file_name;
      try {
        $new_uri = $this->fileSystem->copy($original_uri, $destination_uri, FileExists::Rename);

        if (!$new_uri) {
          throw new \Exception('Failed to copy file to the fedora stream wrapper.');
        }
        $file->setFileUri($new_uri);
        $file->setPermanent();
        $file->save();
        $media = Media::create([
          'bundle' => $media_bundle_id,
          'name' => $file->getFilename(),
          $media_file_field => [
            'target_id' => $file->id(),
          ],
          'field_media_of' => $nid,
          'field_media_use' => lehigh_islandora_get_tid_by_name('Original File', 'islandora_media_use'),
          'status' => 1,
        ]);

        $media->save();
        $this->io()->success(dt('Created Media entity @mid from File @fid.', [
          '@mid' => $media->id(),
          '@fid' => $file->id(),
        ]));

        $files_processed++;
      }
      catch (\Exception $e) {
        $this->io()->error(dt('Error creating media entity for file @fid: @message', [
          '@fid' => $file->id(),
          '@message' => $e->getMessage(),
        ]));
        continue;
      }
    }

    if ($files_processed > 0) {
      $node->set($file_field_name, []);
      $node->set('status', 1);
      $node->save();

      $this->io()->success(dt('Node @nid successfully updated: @count files migrated, @field_name set to NULL, and status set to Published.', [
        '@nid' => $nid,
        '@count' => $files_processed,
        '@field_name' => $file_field_name,
      ]));
    }
    else {
      $this->io()->warning(dt('No media entities were created for node @nid. Node not updated.', ['@nid' => $nid]));
    }
  }

}
