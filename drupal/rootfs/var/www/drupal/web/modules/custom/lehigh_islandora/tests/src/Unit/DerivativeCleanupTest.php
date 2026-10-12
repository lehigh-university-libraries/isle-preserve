<?php

namespace Drupal\Tests\lehigh_islandora\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Tests\UnitTestCase;
use Drupal\Tests\lehigh_islandora\ExistingSite\DerivativeTestBase;
use PHPUnit\Framework\Attributes\Group;

require_once dirname(__DIR__) . '/ExistingSite/DerivativeTestBase.php';

/**
 * Tests bounded cleanup retries without running an existing Drupal site.
 */
#[Group('lehigh_islandora')]
final class DerivativeCleanupTest extends UnitTestCase {

  /**
   * Invokes the shared derivative cleanup logic without bootstrapping a site.
   */
  private function delete(EntityInterface $entity): void {
    $base = (new \ReflectionClass(DerivativeTestBase::class))->newInstanceWithoutConstructor();
    (new \ReflectionMethod($base, 'deleteEntityWithRetry'))->invoke($base, $entity);
  }

  /**
   * A nested serialization error is retried and the next delete succeeds.
   */
  public function testDeadlockRetry(): void {
    $deadlock = new EntityStorageException('Delete failed', 0, new \RuntimeException('Database failed', 0, new \PDOException('Deadlock', 40001)));
    $attempts = 0;
    $entity = $this->createMock(EntityInterface::class);
    $entity->expects($this->exactly(2))->method('delete')->willReturnCallback(static function () use (&$attempts, $deadlock): void {
      if ($attempts++ === 0) {
        throw $deadlock;
      }
    });
    $this->delete($entity);
  }

  /**
   * Persistent deadlocks fail after four attempts.
   */
  public function testRetryLimit(): void {
    $entity = $this->createMock(EntityInterface::class);
    $entity->expects($this->exactly(4))->method('delete')->willThrowException(new EntityStorageException('Deadlock', 40001));
    $this->expectException(EntityStorageException::class);
    $this->delete($entity);
  }

  /**
   * Other storage errors are propagated without retrying.
   */
  public function testOtherErrorsAreNotRetried(): void {
    $entity = $this->createMock(EntityInterface::class);
    $entity->expects($this->once())->method('delete')->willThrowException(new EntityStorageException('Missing table', 42));
    $this->expectException(EntityStorageException::class);
    $this->delete($entity);
  }

}
