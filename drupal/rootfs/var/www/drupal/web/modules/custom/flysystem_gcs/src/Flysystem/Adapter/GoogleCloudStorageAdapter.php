<?php

namespace Drupal\flysystem_gcs\Flysystem\Adapter;

use League\Flysystem\AdapterInterface;
use Superbalist\Flysystem\GoogleStorage\GoogleStorageAdapter;

/**
 * Flysystem adapter for the Google Cloud Storage.
 *
 * @package Drupal\flysystem_gcs\Flysystem\Adapter
 */
class GoogleCloudStorageAdapter extends GoogleStorageAdapter {

  /**
   * Checks if an object or directory exists at the given path.
   *
   * @param string $path
   *   Path to the object or directory.
   *
   * @return bool
   *   Returns true if the object or directory exists or otherwise false.
   */
  public function has($path) {
    return parent::has($path) || $this->hasDirectory($path);
  }

  /**
   * Checks if a directory exists at the given path.
   *
   * @param string $path
   *   Path to the directory.
   *
   * @return bool
   *   Returns true if the directory exists or false if it doesn't exist.
   */
  public function hasDirectory($path) {
    return parent::has($path . '/');
  }

  /**
   * Returns metadata about the object or directory at the given path.
   *
   * @param string $path
   *   Path to the object or directory.
   *
   * @return array|false
   *   Returns an array with metadata on success or false on failure.
   */
  public function getMetadata($path) {
    if ($this->hasDirectory($path)) {
      return [
        'type' => 'dir',
        'path' => $path,
        'timestamp' => \Drupal::time()->getRequestTime(),
        'visibility' => AdapterInterface::VISIBILITY_PUBLIC,
      ];
    }
    return parent::getMetadata($path);
  }

  /**
   * @param string $name
   * @param array $arguments
   * @param callable $callback
   *
   * @return mixed
   */
  protected function getCachedResult($name, $arguments, $callback) {
    $cacheKey = $name . ':' . implode(',', $arguments);
    $result = \Drupal::cache()->get($cacheKey);

    if ($result !== false) {
      return $result->data;
    }

    $result = call_user_func_array($callback, $arguments);
    \Drupal::cache()->set($cacheKey, $result);

    return $result;
  }

}
