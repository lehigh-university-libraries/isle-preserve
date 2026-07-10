<?php

declare(strict_types=1);

namespace Drupal\islandora_rag\Indexer;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\islandora_rag\Exception\TransientDependencyException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Thin client for the dedicated islandora_rag Solr vector core.
 *
 * Writes chunk docs directly (this core is NOT Search API-managed). Host/port
 * come from the same env the Drupal Solr connection uses; the core name is
 * configurable.
 */
final class RagSolrClient {

  private const UPDATE_BATCH_SIZE = 500;
  private const CURL_OPTIONS = [
    \CURLOPT_NOPROXY => '*',
    \CURLOPT_IPRESOLVE => \CURL_IPRESOLVE_V4,
  ];

  private readonly ClientInterface $httpClient;

  public function __construct(
    ClientInterface $_httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {
    // Do not reuse Drupal's shared client for internal Solr traffic: site-wide
    // proxy/client defaults can break Docker service-name resolution.
    $this->httpClient = new Client([
      'proxy' => '',
      'force_ip_resolve' => 'v4',
      'curl' => self::CURL_OPTIONS,
    ]);
  }

  /**
   * Upsert chunk documents.
   *
   * @param array<int, array<string, mixed>> $docs
   *   Solr documents matching the islandora_rag schema.
   */
  public function addDocuments(array $docs): void {
    if ($docs === []) {
      return;
    }
    foreach (array_chunk(array_values($docs), self::UPDATE_BATCH_SIZE) as $batch) {
      $this->post('/update?commitWithin=10000', $batch);
    }
  }

  /**
   * Delete all chunk docs for a node.
   */
  public function deleteByNode(int $nid): void {
    $this->post('/update?commitWithin=10000', ['delete' => ['query' => sprintf('node_id:"%d"', $nid)]]);
  }

  /**
   * Return node IDs currently represented in the vector core.
   *
   * @return int[]
   *   Node IDs from distinct chunk docs.
   */
  public function indexedNodeIds(): array {
    $params = http_build_query([
      'q' => '*:*',
      'rows' => 0,
      'wt' => 'json',
      'json.facet' => json_encode([
        'nodes' => [
          'type' => 'terms',
          'field' => 'node_id',
          'limit' => -1,
        ],
      ]),
    ]);

    try {
      $response = $this->httpClient->request('GET', $this->baseUrl() . '/select?' . $params, [
        'timeout' => 60,
      ]);
    }
    catch (RequestException $e) {
      $this->logger->error('RAG Solr select failed: @msg', ['@msg' => $e->getMessage()]);
      $status = $e->getResponse()?->getStatusCode();
      if ($status !== NULL && $status >= 400 && $status < 500) {
        throw new \RuntimeException('RAG Solr select rejected: ' . $e->getMessage(), 0, $e);
      }
      throw new TransientDependencyException('RAG Solr select failed: ' . $e->getMessage(), 0, $e);
    }
    catch (\Throwable $e) {
      $this->logger->error('RAG Solr select failed: @msg', ['@msg' => $e->getMessage()]);
      throw new TransientDependencyException('RAG Solr select failed: ' . $e->getMessage(), 0, $e);
    }

    $data = json_decode((string) $response->getBody(), TRUE);
    $buckets = $data['facets']['nodes']['buckets'] ?? [];
    $ids = [];
    foreach ($buckets as $bucket) {
      if (isset($bucket['val']) && is_numeric($bucket['val'])) {
        $ids[] = (int) $bucket['val'];
      }
    }
    return $ids;
  }

  /**
   * Base URL of the vector core, e.g. http://solr:8983/solr/islandora_rag.
   */
  private function baseUrl(): string {
    $override = getenv('SOLR_RAG_URL');
    if (is_string($override) && $override !== '') {
      return rtrim($override, '/');
    }
    $host = getenv('DRUPAL_DEFAULT_SOLR_HOST') ?: 'solr';
    $port = getenv('DRUPAL_DEFAULT_SOLR_PORT') ?: '8983';
    $core = $this->configFactory->get('islandora_rag.settings')->get('solr_core') ?: 'islandora_rag';
    return sprintf('http://%s:%s/solr/%s', $host, $port, $core);
  }

  /**
   * POST a JSON body to a Solr update path.
   *
   * @param string $path
   *   Path beneath the core base URL.
   * @param mixed $body
   *   JSON-serializable body.
   */
  private function post(string $path, mixed $body): void {
    try {
      $this->httpClient->request('POST', $this->baseUrl() . $path, [
        'json' => $body,
        'timeout' => 30,
      ]);
    }
    catch (RequestException $e) {
      // Let the caller/queue decide on retry; surface for visibility.
      $this->logger->error('RAG Solr update failed: @msg', ['@msg' => $e->getMessage()]);
      $status = $e->getResponse()?->getStatusCode();
      if ($status !== NULL && $status >= 400 && $status < 500) {
        throw new \RuntimeException('RAG Solr update rejected: ' . $e->getMessage(), 0, $e);
      }
      throw new TransientDependencyException('RAG Solr update failed: ' . $e->getMessage(), 0, $e);
    }
    catch (\Throwable $e) {
      // Let the caller/queue decide on retry; surface for visibility.
      $this->logger->error('RAG Solr update failed: @msg', ['@msg' => $e->getMessage()]);
      throw new TransientDependencyException('RAG Solr update failed: ' . $e->getMessage(), 0, $e);
    }
  }

}
