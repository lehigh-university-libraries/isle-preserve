<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_collection_hero\Unit;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityViewBuilderInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\islandora_collection_hero\Plugin\Block\CollectionHeroBlock;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the inherited collection hero block.
 */
#[Group('islandora_collection_hero')]
final class CollectionHeroBlockTest extends UnitTestCase {

  /**
   * The closest ancestor with hero media supplies the rendered hero.
   */
  public function testNearestAncestorHeroIsRendered(): void {
    $child = $this->createNode(10, ['node:10']);
    $parent = $this->createNode(20, ['node:20']);
    $parent_list = $this->createMock(EntityReferenceFieldItemList::class);
    $parent_list->method('referencedEntities')->willReturn([$parent]);
    $child->method('hasField')
      ->with('field_member_of')
      ->willReturn(TRUE);
    $child->method('get')
      ->with('field_member_of')
      ->willReturn($parent_list);

    $media = $this->createMock(MediaInterface::class);
    $media->method('getCacheContexts')->willReturn([]);
    $media->method('getCacheTags')->willReturn(['media:99']);
    $media->method('getCacheMaxAge')->willReturn(-1);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->expects($this->exactly(2))
      ->method('execute')
      ->willReturnOnConsecutiveCalls([], [99 => 99]);

    $node_storage = $this->createMock(EntityStorageInterface::class);
    $node_storage->expects($this->once())
      ->method('load')
      ->with(10)
      ->willReturn($child);
    $media_storage = $this->createMock(EntityStorageInterface::class);
    $media_storage->method('getQuery')->willReturn($query);
    $media_storage->expects($this->once())
      ->method('load')
      ->with(99)
      ->willReturn($media);

    $view_builder = $this->createMock(EntityViewBuilderInterface::class);
    $view_builder->expects($this->once())
      ->method('view')
      ->with($media, 'full_width')
      ->willReturn(['#markup' => 'Inherited hero']);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')
      ->willReturnMap([
        ['node', $node_storage],
        ['media', $media_storage],
      ]);
    $entity_type_manager->expects($this->once())
      ->method('getViewBuilder')
      ->with('media')
      ->willReturn($view_builder);

    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getParameter')
      ->with('node')
      ->willReturn('10');

    $block = new CollectionHeroBlock(
      [],
      'islandora_collection_hero',
      ['provider' => 'islandora_collection_hero'],
      $entity_type_manager,
      $route_match,
    );
    $build = $block->build();

    $this->assertSame('Inherited hero', $build['#markup']);
    $this->assertContains('route', $build['#cache']['contexts']);
    $this->assertContains('media_list', $build['#cache']['tags']);
    $this->assertContains('node:10', $build['#cache']['tags']);
    $this->assertContains('node:20', $build['#cache']['tags']);
    $this->assertContains('media:99', $build['#cache']['tags']);
  }

  /**
   * Routes without a node render no hero and retain cache metadata.
   */
  public function testRouteWithoutNodeReturnsCacheableEmptyBuild(): void {
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->never())->method('getStorage');
    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getParameter')
      ->with('node')
      ->willReturn('all');

    $block = new CollectionHeroBlock(
      [],
      'islandora_collection_hero',
      ['provider' => 'islandora_collection_hero'],
      $entity_type_manager,
      $route_match,
    );
    $build = $block->build();

    $this->assertSame(['route'], $build['#cache']['contexts']);
    $this->assertSame(['media_list'], $build['#cache']['tags']);
  }

  /**
   * Creates a viewable cacheable node mock.
   *
   * @param int $id
   *   The node ID.
   * @param string[] $cache_tags
   *   The node cache tags.
   */
  private function createNode(int $id, array $cache_tags): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($id);
    $node->method('access')->willReturn(AccessResult::allowed());
    $node->method('getCacheContexts')->willReturn([]);
    $node->method('getCacheTags')->willReturn($cache_tags);
    $node->method('getCacheMaxAge')->willReturn(-1);
    return $node;
  }

}
