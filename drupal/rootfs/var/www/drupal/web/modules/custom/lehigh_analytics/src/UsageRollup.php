<?php

namespace Drupal\lehigh_analytics;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\LockBackendInterface;

/**
 * Resumable, bounded background aggregation. Never called by report requests.
 */
final class UsageRollup {

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * Settings that determine the meaning of a bucket.
   */
  private function signature(): string {
    $config = $this->configFactory->get('lehigh_analytics.settings');
    return $config->get('timezone') . ':' . $config->get('fiscal_start_month');
  }

  /**
   * Reports readiness without reading the event table.
   */
  public function status(): array {
    $row = $this->database->select('lehigh_analytics_progress', 'p')->fields('p')->condition('id', 1)->execute()->fetchAssoc();
    $row = $row ?: ['cursor' => 0, 'ready' => 0, 'updated' => 0, 'signature' => $this->signature()];
    $row['ready'] = (bool) $row['ready'] && $row['signature'] === $this->signature();
    return $row;
  }

  /**
   * Converts an instant to its calendar/fiscal bucket in the reporting timezone.
   */
  public function bucket(string $period, int $timestamp): string {
    if ($period === 'all') {
      return 'all';
    }
    $config = $this->configFactory->get('lehigh_analytics.settings');
    $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone($config->get('timezone')));
    $year = (int) $date->format('Y');
    if (str_starts_with($period, 'fiscal')) {
      $year -= (int) $date->format('n') < (int) $config->get('fiscal_start_month') ? 1 : 0;
      return 'fiscal:' . $year;
    }
    return 'calendar:' . $year;
  }

  /**
   * Atomically records a bounded batch and its cursor; retrying cannot double it.
   *
   * Returns TRUE when caught up, FALSE if another batch is needed. A future-dated
   * record pauses ingestion until its timestamp rather than publishing future use.
   */
  public function refresh(int $limit = 5000, bool $rebuild = FALSE): bool {
    if (!$this->lock->acquire('lehigh_analytics.refresh', 300)) {
      throw new \RuntimeException('A usage refresh is already running.');
    }
    try {
      $transaction = $this->database->startTransaction();
      if ($rebuild) {
        $this->database->delete('lehigh_analytics_totals')->execute();
        $this->database->delete('lehigh_analytics_progress')->execute();
      }
      $status = $this->status();
      if ($status['signature'] !== $this->signature()) {
        throw new \RuntimeException('Reporting timezone/fiscal settings changed. Run lehigh-analytics:refresh --rebuild.');
      }
      $events = $this->database->select('entity_metrics_data', 'd')
        ->fields('d', ['id', 'entity_type', 'entity_id', 'region_id', 'timestamp', 'cookie_set'])
        ->condition('id', $status['cursor'], '>')->orderBy('id')->range(0, $limit)->execute()->fetchAll();
      $groups = [];
      $now = time();
      foreach ($events as $event) {
        if ((int) $event->timestamp > $now) {
          throw new \RuntimeException('Usage refresh paused at a future-dated event: ' . $event->id);
        }
        $status['cursor'] = (int) $event->id;
        if ($event->cookie_set || !in_array($event->entity_type, ['node', 'media'], TRUE)) {
          continue;
        }
        foreach (['all', 'calendar', 'fiscal'] as $period) {
          $key = [
            'bucket' => $this->bucket($period, (int) $event->timestamp),
            'entity_type' => $event->entity_type,
            'entity_id' => (int) $event->entity_id,
            'region_id' => (int) $event->region_id,
          ];
          $index = implode(':', $key);
          $groups[$index] ??= $key + [
            'event_count' => 0,
            'first_event' => (int) $event->timestamp,
            'last_event' => (int) $event->timestamp,
          ];
          $groups[$index]['event_count']++;
          $groups[$index]['first_event'] = min($groups[$index]['first_event'], (int) $event->timestamp);
          $groups[$index]['last_event'] = max($groups[$index]['last_event'], (int) $event->timestamp);
        }
      }
      foreach ($groups as $group) {
        $key = array_intersect_key($group, array_flip(['bucket', 'entity_type', 'entity_id', 'region_id']));
        $this->database->merge('lehigh_analytics_totals')->keys($key)->fields(array_diff_key($group, $key))
          ->expression('event_count', 'event_count + :increment', [':increment' => $group['event_count']])
          ->expression('first_event', 'CASE WHEN first_event < :first THEN first_event ELSE :first2 END', [
            ':first' => $group['first_event'],
            ':first2' => $group['first_event'],
          ])
          ->expression('last_event', 'CASE WHEN last_event > :last THEN last_event ELSE :last2 END', [
            ':last' => $group['last_event'],
            ':last2' => $group['last_event'],
          ])->execute();
      }
      $complete = count($events) < $limit;
      $this->database->merge('lehigh_analytics_progress')->key('id', 1)->fields([
        'cursor' => $status['cursor'],
        'ready' => (int) ($status['ready'] || $complete),
        'updated' => $now,
        'signature' => $this->signature(),
      ])->execute();
      unset($transaction);
      Cache::invalidateTags(['lehigh_analytics']);
      return $complete;
    }
    catch (\Throwable $exception) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }
      throw $exception;
    }
    finally {
      $this->lock->release('lehigh_analytics.refresh');
    }
  }

}
