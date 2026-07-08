<?php

declare(strict_types=1);

namespace Drupal\islandora_rag\Exception;

/**
 * Signals a dependency outage where the queue should pause and retry later.
 */
final class TransientDependencyException extends \RuntimeException {
}
