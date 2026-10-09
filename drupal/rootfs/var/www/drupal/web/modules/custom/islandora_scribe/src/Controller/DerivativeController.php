<?php

namespace Drupal\islandora_scribe\Controller;

use Drupal\islandora\Controller\MediaSourceController;
use Drupal\media\MediaTypeInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class DerivativeController extends MediaSourceController {
  private \Drupal\islandora_scribe\Integration $integration;

  public static function create(ContainerInterface $container) {
    $controller = parent::create($container);
    $controller->integration = $container->get('islandora_scribe.integration');
    return $controller;
  }

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
