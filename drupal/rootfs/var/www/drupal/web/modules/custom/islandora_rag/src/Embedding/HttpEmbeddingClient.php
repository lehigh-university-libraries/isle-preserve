<?php

declare(strict_types=1);

namespace Drupal\islandora_rag\Embedding;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\islandora_rag\Exception\TransientDependencyException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Embedding client that POSTs to the hosted embedding service.
 *
 * Posts to POST /embed/documents accepting {texts, model, dimension} and
 * returning {embeddings: [[...], ...]}.
 */
final class HttpEmbeddingClient implements EmbeddingClientInterface {

  /**
   * Hosted Qwen embedding service (isle-microservice).
   */
  private const SERVICE_URL = 'https://isle-microservices.cc.lehigh.edu/transformer';

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function embedDocuments(array $texts): array {
    if ($texts === []) {
      return [];
    }

    $base = self::SERVICE_URL;

    $config = $this->configFactory->get('islandora_rag.settings');
    try {
      $response = $this->httpClient->request('POST', $base . '/embed/documents', [
        'json' => [
          'texts' => array_values($texts),
          'model' => $config->get('embedding_model'),
          'dimension' => (int) $config->get('embedding_dimension'),
        ],
        'timeout' => 600,
      ]);
    }
    catch (RequestException $e) {
      $status = $e->getResponse()?->getStatusCode();
      if ($status !== NULL && $status >= 400 && $status < 500) {
        throw new \RuntimeException('Embedding request rejected: ' . $e->getMessage(), 0, $e);
      }
      throw new TransientDependencyException('Embedding request failed: ' . $e->getMessage(), 0, $e);
    }
    catch (\Throwable $e) {
      throw new TransientDependencyException('Embedding request failed: ' . $e->getMessage(), 0, $e);
    }

    $data = json_decode((string) $response->getBody(), TRUE);
    $vectors = $data['embeddings'] ?? NULL;
    if (!is_array($vectors) || count($vectors) !== count($texts)) {
      throw new \RuntimeException('Embedding service returned an unexpected payload.');
    }
    $dimension = (int) $config->get('embedding_dimension');
    $returned_dimension = (int) ($data['dimension'] ?? 0);
    if ($returned_dimension !== 0 && $returned_dimension !== $dimension) {
      throw new \RuntimeException(sprintf(
        'Embedding service returned dimension %d, expected %d.',
        $returned_dimension,
        $dimension,
      ));
    }

    // Coerce to float vectors.
    return array_map(static function (array $v) use ($dimension): array {
      if (count($v) !== $dimension) {
        throw new \RuntimeException(sprintf('Embedding vector length %d does not match expected dimension %d.', count($v), $dimension));
      }
      return array_map(static fn($x): float => (float) $x, $v);
    }, $vectors);
  }

}
