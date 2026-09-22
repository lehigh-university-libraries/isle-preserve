<?php

namespace Drupal\lehigh_analytics;

/**
 * Another worker holds the refresh lock; the caller can simply try later.
 */
final class RefreshLockedException extends \RuntimeException {}
