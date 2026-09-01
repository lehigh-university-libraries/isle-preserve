<?php

declare(strict_types=1);

namespace Drupal\islandora_collection_hero\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays the nearest collection hero available in an Islandora hierarchy.
 */
#[Block(
  id: 'islandora_collection_hero',
  admin_label: new TranslatableMarkup('Islandora collection hero'),
  category: new TranslatableMarkup('Islandora'),
)]
final class CollectionHeroBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The PCDM service-file URI used for hero media.
   */
  private const SERVICE_FILE_URI = 'http://pcdm.org/use#ServiceFile';

  /**
   * Constructs an Islandora collection hero block.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RouteMatchInterface $routeMatch,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $build = [];
    $cacheability = (new CacheableMetadata())
      ->setCacheContexts(['route'])
      ->setCacheTags(['media_list']);
    $node = $this->resolveRouteNode();

    if (!$node instanceof NodeInterface) {
      $cacheability->applyTo($build);
      return $build;
    }

    $media = $this->findHeroMedia($node, $cacheability);
    if (!$media instanceof MediaInterface) {
      $cacheability->applyTo($build);
      return $build;
    }

    $cacheability->addCacheableDependency($media);
    $build = $this->entityTypeManager
      ->getViewBuilder('media')
      ->view($media, 'full_width');
    $cacheability->applyTo($build);
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), ['route']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return Cache::mergeTags(parent::getCacheTags(), ['media_list']);
  }

  /**
   * Resolves a node entity from a converted or raw route parameter.
   */
  private function resolveRouteNode(): ?NodeInterface {
    $node = $this->routeMatch->getParameter('node');
    if ($node instanceof NodeInterface) {
      return $node;
    }
    if (!is_int($node) && (!is_string($node) || !ctype_digit($node))) {
      return NULL;
    }

    $loaded = $this->entityTypeManager
      ->getStorage('node')
      ->load((int) $node);
    return $loaded instanceof NodeInterface ? $loaded : NULL;
  }

  /**
   * Finds hero media on the node or its nearest field_member_of ancestor.
   */
  private function findHeroMedia(NodeInterface $node, CacheableMetadata $cacheability): ?MediaInterface {
    $queue = [$node];
    $visited = [];

    while ($queue !== []) {
      $candidate = array_shift($queue);
      if (!$candidate instanceof NodeInterface || isset($visited[$candidate->id()])) {
        continue;
      }

      $visited[$candidate->id()] = TRUE;
      $cacheability->addCacheableDependency($candidate);
      $access = $candidate->access('view', NULL, TRUE);
      $cacheability->addCacheableDependency($access);
      if (!$access->isAllowed()) {
        continue;
      }

      $media = $this->loadHeroMedia((int) $candidate->id());
      if ($media instanceof MediaInterface) {
        return $media;
      }

      if (!$candidate->hasField('field_member_of')) {
        continue;
      }
      foreach ($candidate->get('field_member_of')->referencedEntities() as $parent) {
        if ($parent instanceof NodeInterface) {
          $queue[] = $parent;
        }
      }
    }

    return NULL;
  }

  /**
   * Loads the newest accessible image service file attached to a node.
   */
  private function loadHeroMedia(int $node_id): ?MediaInterface {
    $storage = $this->entityTypeManager->getStorage('media');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->condition('bundle', 'image')
      ->condition('field_media_of', $node_id)
      ->condition(
        'field_media_use.entity:taxonomy_term.field_external_uri.uri',
        self::SERVICE_FILE_URI,
      )
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->execute();

    if ($ids === []) {
      return NULL;
    }

    $media = $storage->load(reset($ids));
    return $media instanceof MediaInterface ? $media : NULL;
  }

}
