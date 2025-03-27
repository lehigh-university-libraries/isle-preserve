<?php

namespace Drupal\lehigh_islandora\Encoder;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Symfony\Component\Serializer\Encoder\CsvEncoder;

/**
 * Workbench CSV encoder.
 */
class WorkbenchCsvEncoder extends CsvEncoder {

  /**
   * The formats that this Encoder supports.
   *
   * @var string
   */
  protected $format = 'workbench_csv';

  /**
   * Serializer used to transform nodes to JSON.
   *
   * @var \Symfony\Component\Serializer\SerializerInterface
   */
  protected $serializer;

  /**
   * Serializer used to transform nodes to JSON.
   *
   * @var Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * {@inheritdoc}
   */
  public function supportsEncoding(string $format) : bool {
    return $format == $this->format;
  }

  /**
   * {@inheritdoc}
   */
  public function supportsDecoding(string $format) : bool {
    return in_array($format, [$this->format]);
  }

  /**
   * {@inheritdoc}
   */
  public function getRow(array $data, array $multipleBundles, array $header = []) : array {
    $entity_type_manager = \Drupal::entityTypeManager();
    $entity_storage = [];
    $remove = [
      'uid',
      'uuid',
      'vid',
      'langcode',
      'type',
      'status',
      'path',
      'revision_timestamp',
      'revision_uid',
      'revision_log',
      'metatag',
      'sticky',
      'promote',
      'changed',
      'created',
      'default_langcode',
      'revision_translation_affected',
      'content_translation_source',
      'content_translation_outdated',
      'citation',
      // @todo make this configurable
      'field_thumbnail',
    ];
    foreach ($remove as $field) {
      unset($data[$field]);
    }

    $row = [
      'node_id' => '',
      'title' => '',
      'field_model' => '',
      'url' => '',
      'thumbnail' => '',
      'file' => '',
    ];
    $file_url_generator = \Drupal::service('file_url_generator');
    $alias_manager = \Drupal::service('path_alias.manager');
    foreach ($data as $fieldName => $fieldValues) {
      $cell = [];
      foreach ($fieldValues as $k => $fieldValue) {
        $value = '';
        if (!empty($fieldValue['target_id'])) {
          $entity_type = $fieldValue['target_type'];
          if (!isset($entity_storage[$entity_type])) {
            $entity_storage[$entity_type] = $entity_type_manager->getStorage($entity_type);
          }
          if ($fieldName !== "field_member_of" && !empty($entity_storage[$entity_type])) {
            $entity = $entity_storage[$entity_type]->load($fieldValue['target_id']);
            if ($entity) {
              $value = in_array($fieldName, $multipleBundles) ? $entity->bundle() . ":" . $entity->label() : $entity->label();
              if (isset($fieldValue['rel_type'])) {
                $value = $fieldValue['rel_type'] . ":" . $value;
              }
            }
          }
          if ($value == '') {
            $value = $fieldValue['target_id'];
          }
        }
        elseif (array_key_exists('attr0', $fieldValue)
              || array_key_exists('format', $fieldValue)
              || array_key_exists('identifier_type', $fieldValue)
              || array_key_exists('caption', $fieldValue)
              || array_key_exists('city', $fieldValue)
          ) {
          $cleanValue = [];
          foreach ($fieldValue as $key => $value) {
            if (!is_null($value)) {
              $cleanValue[$key] = $value;
            }
          }
          if (count($cleanValue)) {
            $value = json_encode($cleanValue);
          }
        }
        elseif (isset($fieldValue['value'])) {
          $value = $fieldValue['value'];
        }

        $cell[] = $value;
      }
      if ($fieldName == 'nid') {
        $fieldName = 'node_id';
        $nid = implode("|", $cell);
        $node = Node::load($nid);
        if ($node) {
          $path = '/node/';
          if (lehigh_site_support_identify_collection($node)) {
            $path = '/browse-items/';
          }
          $alias = $alias_manager->getAliasByPath($path . $node->id());

          $url = Url::fromUserInput($alias, ['absolute' => TRUE]);
          $row['url'] = $url->toString();

          $original_file = lehigh_site_support_get_node_file($node, 'http://pcdm.org/use#OriginalFile');
          if ($original_file) {
            $row['file'] = $file_url_generator->generateAbsoluteString($original_file->getFileUri());
          }
          $thumbnail = lehigh_site_support_get_node_file($node, 'http://pcdm.org/use#ThumbnailImage');
          if ($thumbnail) {
            $row['thumbnail'] = $file_url_generator->generateAbsoluteString($thumbnail->getFileUri());
          }
        }
      }
      $row[$fieldName] = implode("|", $cell);
    }

    return $row;
  }

  /**
   * {@inheritdoc}
   */
  public function encode($data, $format, array $context = []) : string {
    // Find which fields have multiple taxonomy vocabs to choose from.
    $multipleBundles = [];
    if (!empty($data['nid'][0]['value'])) {
      $node_storage = \Drupal::entityTypeManager()->getStorage('node');
      $node = $node_storage->load($data['nid'][0]['value']);
      foreach ($node as $fieldName => $values) {
        $settings = $node->get($fieldName)->getSettings();
        if (!empty($settings['handler_settings']['target_bundles']) && count($settings['handler_settings']['target_bundles']) > 1) {
          $multipleBundles[] = $fieldName;
        }
      }
    }

    $row = $this->getRow($data, $multipleBundles);
    $header = array_keys($row);
    $rows = [
      $header,
      array_values($row),
    ];
    if (!empty($row['node_id'])) {
      $this->serializer = \Drupal::service('serializer');
      $this->fileSystem = \Drupal::service('file_system');
      $entity_ids = \Drupal::entityQuery('node')
        ->condition('field_member_of', $row['node_id'])
        ->accessCheck(TRUE)
        ->execute();
      foreach (array_chunk($entity_ids, 100) as $chunk) {
        $nodes = $node_storage->loadMultiple($chunk);
        foreach ($nodes as $node) {
          $data = $this->getNodeJson($node);
          $data = json_decode($data, TRUE);
          $row = $this->getRow($data, $multipleBundles, $header);
          $rows[] = array_values($row);
        }
      }
    }
    $context['no_headers'] = TRUE;

    // Assume all columns are empty.
    $empty_cell = [];
    foreach ($header as $k => $v) {
      $empty_cell[$k] = TRUE;
    }

    // Mark columns that have a value in some row as non-empty.
    $first = TRUE;
    foreach ($rows as $row) {
      if ($first) {
        $first = FALSE;
        continue;
      }
      foreach ($row as $k => $cell) {
        if (!empty($cell)) {
          $empty_cell[$k] = FALSE;
        }
      }
    }

    // Remove empty columns.
    $trimmed_rows = [];
    foreach ($rows as $row) {
      $trimmed_row = [];
      foreach ($row as $k => $cell) {
        if (!$empty_cell[$k]) {
          $trimmed_row[] = $cell;
        }
      }
      $trimmed_rows[] = $trimmed_row;
    }

    $csv = parent::encode($trimmed_rows, 'csv', $context);

    return $csv;
  }

  /**
   * Get node json.
   */
  public function getNodeJson($node): string {
    $filename = $node->id() . '.json';
    $cache_path = 'private://serialized/node/' . $filename;
    $file_path = $this->fileSystem->realpath($cache_path);
    if (file_exists($file_path)) {
      return file_get_contents($file_path);
    }

    $base_dir = dirname($cache_path);
    $this->fileSystem->prepareDirectory($base_dir, FileSystemInterface::CREATE_DIRECTORY);
    $json = $this->serializer->serialize($node, 'json', ['plugin_id' => 'entity']);
    $file_path = $this->fileSystem->realpath($base_dir) . '/' . $filename;
    $f = fopen($file_path, 'w');
    if ($f) {
      fwrite($f, $json);
      fclose($f);
    }

    return $json;
  }

}
