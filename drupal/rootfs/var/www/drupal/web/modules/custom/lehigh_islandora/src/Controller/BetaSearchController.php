<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Drupal-owned page shell for beta search.
 */
final class BetaSearchController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('request_stack'),
    );
  }

  /**
   * Builds a lightweight placeholder that beta-search.js fills from Go.
   */
  public function page(): array {
    $request = $this->requestStack->getCurrentRequest();
    $node_id = NULL;
    $node = $request?->attributes->get('node');
    if ($node instanceof NodeInterface) {
      $node_id = (int) $node->id();
    }
    elseif (is_numeric($node)) {
      $node_id = (int) $node;
    }

    $attributes = [
      'class' => [
        'view',
        'view-browse',
        'view-display-id-main',
        'main',
      ],
      'data-beta-search-results' => 'true',
    ];
    if ($node_id !== NULL) {
      $attributes['data-beta-search-node-id'] = (string) $node_id;
    }

    return [
      '#type' => 'container',
      '#attributes' => $attributes,
      'loading' => [
        '#markup' => '<div class="result-summary">Loading search results...</div>',
      ],
      '#attached' => [
        'library' => [
          'lehigh/beta-search',
        ],
        'drupalSettings' => [
          'lehighBetaSearch' => [
            'enabled' => TRUE,
            'endpoint' => '/_go-search/browse',
            'nodeId' => $node_id,
          ],
        ],
      ],
      '#cache' => [
        'contexts' => [
          'url.path',
          'url.query_args',
        ],
      ],
    ];
  }

}
