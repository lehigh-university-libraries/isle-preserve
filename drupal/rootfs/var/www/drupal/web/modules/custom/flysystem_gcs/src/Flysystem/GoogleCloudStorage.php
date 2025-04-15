<?php

namespace Drupal\flysystem_gcs\Flysystem;

use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\flysystem\Flysystem\Adapter\MissingAdapter;
use Drupal\flysystem\Plugin\FlysystemPluginInterface;
use Drupal\flysystem\Plugin\FlysystemUrlTrait;
use Drupal\flysystem\Plugin\ImageStyleGenerationTrait;
use Drupal\flysystem_gcs\Flysystem\Adapter\GoogleCloudStorageAdapter;
use Google\Cloud\Core\Exception\GoogleException;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drupal plugin for the Google Cloud Storage Flysystem adapter.
 *
 * @Adapter(id = "gcs")
 */
class GoogleCloudStorage implements FlysystemPluginInterface, ContainerFactoryPluginInterface {

  use FlysystemUrlTrait;
  use ImageStyleGenerationTrait;

  /**
   * The Google Storage Client.
   *
   * @var \Google\Cloud\Storage\StorageClient
   */
  protected $client;

  /**
   * The selected bucket.
   *
   * @var \Google\Cloud\Storage\Bucket
   */
  protected $bucket;

  /**
   * Custom URI (e.g., CNAME) for the storage.
   *
   * This is the adapter's "Storage API URI", however the API calls
   * are made to a consistent endpoint, specified in the gcloud library.
   *
   * @var string
   */
  protected $uri;

  /**
   * Path prefix.
   *
   * @var string
   */
  protected $prefix;

  /**
   * GoogleCloudStorage constructor.
   *
   * @param \Google\Cloud\Storage\StorageClient $client
   *   The Google Storage Client.
   * @param \Google\Cloud\Storage\Bucket $bucket
   *   The bucket to use.
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   */
  public function __construct(StorageClient $client, Bucket $bucket, array $configuration) {
    $this->client = $client;
    $this->bucket = $bucket;
    $this->prefix = $configuration['prefix'] ?? '';
    $this->uri = $configuration['uri'] ?? NULL;
  }

  /**
   * Creates an instance of the plugin.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container to pull out services used in the plugin.
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   *
   * @return static
   *   Returns an instance of this plugin.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $bucketName = $configuration['bucket'] ?? NULL;
    $localConfiguration = !empty($configuration['_localConfig'])
      ? $configuration['_localConfig']
      : [];
    unset($configuration['_localConfig']);

    if (empty($bucketName)) {
      throw new \InvalidArgumentException('A valid bucket name must be given');
    }

    $client = new StorageClient($configuration);
    $bucket = $client->bucket($bucketName);

    return new static($client, $bucket, $localConfiguration);
  }

  /**
   * Returns the Flysystem adapter.
   *
   * Plugins should not keep references to the adapter. If a plugin needs to
   * perform filesystem operations, it should either use a scheme:// or have the
   * \Drupal\flysystem\FlysystemFactory injected.
   *
   * @return \League\Flysystem\AdapterInterface
   *   The Flysystem adapter.
   */
  public function getAdapter() {
    try {
      return new GoogleCloudStorageAdapter(
        $this->client,
        $this->bucket,
        $this->prefix,
        $this->uri
      );
    }
    catch (\Exception $exc) {
      // @todo: improve error handling?
      return new MissingAdapter();
    }
  }

  /**
   * Returns a web accessible URL for the resource.
   *
   * This function should return a URL that can be embedded in a web page
   * and accessed from a browser. For example, the external URL of
   * "youtube://xIpLd0WQKCY" might be
   * "http://www.youtube.com/watch?v=xIpLd0WQKCY".
   *
   * @param string $uri
   *   The URI to provide a URL for.
   *
   * @return string
   *   Returns a string containing a web accessible URL for the resource.
   */
  public function getExternalUrl($uri) {
    $adapter = $this->getAdapter();
    $target = $this->getTarget($uri);

    if (strpos($target, 'styles/') === 0 && !file_exists($uri)) {
      $this->generateImageStyle($target);
    }

    return $adapter->getUrl($target);
  }

  /**
   * Checks the sanity of the filesystem.
   *
   * If this is a local filesystem, .htaccess file should be in place.
   *
   * @return array
   *   A list of error messages.
   */
  public function ensure($force = FALSE) {
    $result = [];
    try {
      if (!$this->bucket->exists()) {
        $result = [
          [
            'severity' => RfcLogLevel::ERROR,
            'message' => 'The bucket %bucket does not exist',
            'context' => [
              '%bucket' => $this->bucket->name(),
            ],
          ],
        ];
      }
    }
    catch (GoogleException $exc) {
      $result = [
        [
          'severity' => RfcLogLevel::ERROR,
          'message' => $exc->getMessage(),
          'context' => [],
        ],
      ];
    }

    return $result;
  }

}
