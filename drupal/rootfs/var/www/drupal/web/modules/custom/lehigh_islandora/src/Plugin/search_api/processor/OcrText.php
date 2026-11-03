<?php

namespace Drupal\lehigh_islandora\Plugin\search_api\processor;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\file\FileInterface;
use Drupal\lehigh_islandora\Plugin\search_api\processor\Property\OcrFieldProperty;
use Drupal\media\Plugin\media\Source\File;
use Drupal\node\NodeInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\SearchApiException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Process\Process;

/**
 * Index OCR text files.
 *
 * Adapted from https://github.com/discoverygarden/islandora_hocr/blob/main/src/Plugin/search_api/processor/HOCRField.php.
 *
 * @SearchApiProcessor(
 *   id = "islandora_ocr_text",
 *   label = @Translation("Islandora OCR field"),
 *   description = @Translation("Add OCR to the index directly from a file."),
 *   stages = {
 *     "add_properties" = 20,
 *     "preprocess_index" = 20,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
class OcrText extends ProcessorPluginBase {

  const PROPERTY_NAME = 'islandora_ocr_text';

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);

    $instance->entityTypeManager = $container->get('entity_type.manager');

    return $instance;
  }

  /**
   * {@inheritDoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL) {
    if (!$datasource || $datasource->getEntityTypeId() != 'node') {
      return [];
    }

    return [
      static::PROPERTY_NAME => new OcrFieldProperty([
        'label' => $this->t('OCR Text'),
        'description' => $this->t('OCR from referenced media.'),
        'processor_id' => $this->getPluginId(),
        'is_list' => FALSE,
        'computed' => FALSE,
      ]),
    ];
  }

  /**
   * {@inheritDoc}
   *
   * Adapted from https://git.drupalcode.org/project/search_api/-/blob/8.x-1.x/src/Plugin/search_api/processor/EntityType.php#L47-67
   */
  public function addFieldValues(ItemInterface $item) {
    try {
      $entity = $item->getOriginalObject()->getValue();
    }
    catch (SearchApiException $e) {
      return;
    }

    if (!($entity instanceof NodeInterface)) {
      return;
    }

    $data = [
      'file' => [
        'value' => NULL,
      ],
      'uri' => [
        'value' => NULL,
      ],
      'content' => [
        'value' => NULL,
      ],
    ];
    $fields = $item->getFields();

    foreach ($data as $key => $info) {
      $spec_fields = $this->getFieldsHelper()
        ->filterForPropertyPath(
          $fields,
          $item->getDatasourceId(),
          static::PROPERTY_NAME . ":$key"
        );
      foreach ($spec_fields as $field) {
        if (!$field->getValues()) {
          $field->addValue(NULL);
        }
      }
    }

  }

  /**
   * {@inheritDoc}
   *
   * Adapted from https://git.drupalcode.org/project/search_api/-/blob/8.x-1.x/src/Plugin/search_api/processor/EntityType.php#L47-67
   */
  public function preprocessIndexItems(array $items) {
    foreach ($items as &$item) {
      try {
        $entity = $item->getOriginalObject()->getValue();
      }
      catch (SearchApiException $e) {
        return;
      }

      if (!($entity instanceof NodeInterface)) {
        return;
      }

      $data = [
        'file' => [
          'value' => NULL,
        ],
        'uri' => [
          'value' => NULL,
        ],
        'content' => [
          'value' => NULL,
        ],
      ];
      [$file, $content] = $this->getIndexContent($entity);
      $data['file']['callable'] = static fn() => $file;
      $data['uri']['callable'] = static fn() => $file ? $file->getFileUri() : NULL;
      $data['content']['callable'] = static fn() => $content;

      $fields = $item->getFields();

      foreach ($data as $key => $info) {
        $spec_fields = $this->getFieldsHelper()
          ->filterForPropertyPath(
            $fields,
            $item->getDatasourceId(),
            static::PROPERTY_NAME . ":$key"
          );
        foreach ($spec_fields as $field) {
          $field->addValue($info['callable']());
        }
      }
    }
  }

  /**
   * Selects the first readable transcript, then falls back to OCR.
   */
  protected function getIndexContent(NodeInterface $node): array {
    foreach ([TRUE, FALSE] as $transcript) {
      foreach ($this->getFiles($node, $transcript) as $candidate) {
        $content = $this->readFile($candidate);
        if ($content !== NULL && trim($content) !== '') {
          return [$candidate, $content];
        }
      }
    }
    return [NULL, NULL];
  }

  /**
   * Extracts text from a PDF or reads a plain-text transcription or OCR file.
   */
  protected function readFile(FileInterface $file): ?string {
    $uri = $file->getFileUri();
    $extension = strtolower(pathinfo($uri, PATHINFO_EXTENSION));
    if ($file->getMimeType() === 'application/pdf' || $extension === 'pdf') {
      $stream = @fopen($uri, 'rb');
      if (!$stream) {
        return NULL;
      }
      try {
        $process = new Process(['pdftotext', '-enc', 'UTF-8', '-', '-']);
        $process->setInput($stream);
        $process->setTimeout(60);
        $process->run();
        if (!$process->isSuccessful()) {
          \Drupal::logger('lehigh_islandora')->warning('PDF transcription extraction failed for file @fid.', ['@fid' => $file->id()]);
          return NULL;
        }
        return $process->getOutput();
      }
      catch (\Throwable $error) {
        \Drupal::logger('lehigh_islandora')->warning('PDF transcription extraction failed for file @fid: @message', [
          '@fid' => $file->id(),
          '@message' => $error->getMessage(),
        ]);
        return NULL;
      }
      finally {
        fclose($stream);
      }
    }
    if (!str_starts_with((string) $file->getMimeType(), 'text/')
      && !in_array($extension, ['txt', 'text', 'hocr', 'html', 'htm', 'xml'], TRUE)) {
      return NULL;
    }
    $text = @file_get_contents($uri);
    return $text === FALSE ? NULL : $text;
  }

  /**
   * Finds accessible transcript or OCR files for this node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node for which to find a file containing OCR.
   * @param bool $transcript
   *   TRUE selects transcription media; FALSE selects extracted OCR.
   *
   * @return \Drupal\file\FileInterface[]
   *   Accessible files in deterministic order.
   */
  protected function getFiles(NodeInterface $node, bool $transcript): array {
    $media_storage = $this->entityTypeManager->getStorage('media');
    $query = $media_storage->getQuery();

    $query->condition('field_media_of', $node->id());
    if ($transcript) {
      $query->condition('field_media_use', 20);
    }
    else {
      $query->condition('field_media_use.entity:taxonomy_term.field_external_uri.uri', 'http://pcdm.org/use#ExtractedText');
    }
    $query->condition('status', 1);
    $query->sort('mid');
    $query->accessCheck(FALSE);

    $media = $query->execute();

    $anonymous = new AnonymousUserSession();

    $files = [];
    foreach ($media as $medium) {
      /** @var \Drupal\media\MediaInterface $entity */
      $entity = $media_storage->load($medium);
      if (!$entity) {
        continue;
      }
      elseif (!$entity->access('view', $anonymous, FALSE)) {
        continue;
      }

      $source = $entity->getSource();

      if ($source instanceof File) {
        $fid = $source->getSourceFieldValue($entity);
        /** @var \Drupal\file\FileInterface $file */
        $file = $this->entityTypeManager->getStorage('file')->load($fid);

        if (!$file || !$file->access('view', $anonymous, FALSE)) {
          continue;
        }

        $files[] = $file;
      }
    }

    return $files;
  }

}
