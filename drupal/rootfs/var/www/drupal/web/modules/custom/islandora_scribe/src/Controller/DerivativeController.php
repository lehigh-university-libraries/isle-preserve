<?php

namespace Drupal\islandora_scribe\Controller;

use Drupal\islandora_scribe\Integration;
use Drupal\islandora\Controller\MediaSourceController;
use Drupal\media\MediaTypeInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives the initial Scribe hOCR derivative.
 */
final class DerivativeController extends MediaSourceController {
  /**
   * The Scribe integration service.
   */
  private Integration $integration;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $controller = parent::create($container);
    $controller->integration = $container->get('islandora_scribe.integration');
    return $controller;
  }

  /**
   * Verifies and dispatches a signed callback.
   */
  public function receive(string $operation, NodeInterface $node, MediaTypeInterface $media_type, TermInterface $taxonomy_term, Request $request): Response {
    try {
      $this->integration->initialDerivative($operation, $node, $media_type, $taxonomy_term, $request->getContent(TRUE), $request->headers->get('Content-Location', ''));
      return new Response('', 204);
    }
    catch (\InvalidArgumentException $e) {
      return new Response('', 400);
    }
    catch (\Throwable $e) {
      return new Response('', 503);
    }
  }

}
