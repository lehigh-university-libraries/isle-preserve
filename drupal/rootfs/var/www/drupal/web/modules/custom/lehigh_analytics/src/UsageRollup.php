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

  /**
   * Seconds an event must age before ingestion.
   *
   * Auto-increment IDs are allocated before their transactions commit, so a
   * lower ID can become visible after a higher one. Reading only settled events
   * keeps the ID cursor from skipping a late-committing insert.
   */
  private const SETTLE_SECONDS = 30;

  /**
   * Rows per multi-row write or IN () list.
   */
  private const CHUNK = 500;

  /**
   * The stored progress row for this request; refreshes reset it.
   */
  private ?array $status = NULL;

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
   *
   * 'caught_up' means ingestion has reached the latest event at least once;
   * 'ready' also requires unchanged period settings.
   */
  public function status(): array {
    if ($this->status === NULL) {
      $row = $this->database->select('lehigh_analytics_progress', 'p')->fields('p')->condition('id', 1)->execute()->fetchAssoc();
      $this->status = $row ?: [
        'cursor' => 0,
        'ready' => 0,
        'updated' => 0,
        'signature' => $this->signature(),
      ];
    }
    $status = $this->status;
    $status['caught_up'] = (bool) $status['ready'];
    // Settings are compared on every call; only the stored row is memoized.
    $status['ready'] = $status['caught_up'] && $status['signature'] === $this->signature();
    return $status;
  }

  /**
   * Converts an instant to its calendar/fiscal bucket in the reporting timezone.
   */
  public function bucket(string $period, int $timestamp): string {
    if ($period === 'all') {
      return 'all';
    }
    $buckets = $this->buckets($timestamp, new \DateTimeZone($this->configFactory->get('lehigh_analytics.settings')->get('timezone')));
    return str_starts_with($period, 'fiscal') ? $buckets[2] : $buckets[1];
  }

  /**
   * Every bucket one event contributes to: all time, calendar, and fiscal year.
   */
  private function buckets(int $timestamp, \DateTimeZone $timezone): array {
    $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
    $year = (int) $date->format('Y');
    $fiscal = $year - ((int) $date->format('n') < (int) $this->configFactory->get('lehigh_analytics.settings')->get('fiscal_start_month') ? 1 : 0);
    return ['all', 'calendar:' . $year, 'fiscal:' . $fiscal];
  }

  /**
   * Atomically records a bounded batch and its cursor; retrying cannot double it.
   *
   * Returns TRUE when caught up, FALSE if another batch is needed. A future-dated
   * record pauses ingestion until its timestamp rather than publishing future use.
   * Report caches are invalidated only when saved counts actually changed.
   */
  public function refresh(int $limit = 5000, bool $rebuild = FALSE): bool {
    if (!$this->lock->acquire('lehigh_analytics.refresh', 300)) {
      throw new RefreshLockedException('A usage refresh is already running.');
    }
    $this->status = NULL;
    try {
      $transaction = $this->database->startTransaction();
      if ($rebuild) {
        foreach ([
          'lehigh_analytics_totals',
          'lehigh_analytics_work_totals',
          'lehigh_analytics_work_countries',
          'lehigh_analytics_progress',
        ] as $table) {
          $this->database->delete($table)->execute();
        }
        $this->status = NULL;
      }
      $status = $this->status();
      if ($status['signature'] !== $this->signature()) {
        throw new \RuntimeException('Reporting timezone/fiscal settings changed. Run lehigh-analytics:refresh --rebuild.');
      }
      $now = time();
      $events = $this->database->select('entity_metrics_data', 'd')
        ->fields('d', ['id', 'entity_type', 'entity_id', 'region_id', 'timestamp', 'cookie_set'])
        ->condition('id', $status['cursor'], '>')->orderBy('id')->range(0, $limit)->execute()->fetchAll();
      $complete = count($events) < $limit;
      $changed = ['node' => [], 'media' => []];
      $groups = [];
      $timezone = new \DateTimeZone($this->configFactory->get('lehigh_analytics.settings')->get('timezone'));
      foreach ($events as $event) {
        $timestamp = (int) $event->timestamp;
        if ($timestamp > $now) {
          throw new \RuntimeException('Usage refresh paused at a future-dated event: ' . $event->id);
        }
        if ($timestamp > $now - self::SETTLE_SECONDS) {
          // Stop without advancing; the next run resumes here.
          $complete = TRUE;
          break;
        }
        $status['cursor'] = (int) $event->id;
        if ($event->cookie_set || !in_array($event->entity_type, ['node', 'media'], TRUE)) {
          continue;
        }
        $id = (int) $event->entity_id;
        $changed[$event->entity_type][$id] = $id;
        foreach ($this->buckets($timestamp, $timezone) as $bucket) {
          $index = $bucket . ':' . $event->entity_type . ':' . $id . ':' . (int) $event->region_id;
          if (isset($groups[$index])) {
            $groups[$index]['event_count']++;
            $groups[$index]['first_event'] = min($groups[$index]['first_event'], $timestamp);
            $groups[$index]['last_event'] = max($groups[$index]['last_event'], $timestamp);
          }
          else {
            $groups[$index] = [
              'bucket' => $bucket,
              'entity_type' => $event->entity_type,
              'entity_id' => $id,
              'region_id' => (int) $event->region_id,
              'event_count' => 1,
              'first_event' => $timestamp,
              'last_event' => $timestamp,
            ];
          }
        }
      }
      $this->addTotals($groups);

      $dirty = $this->database->select('lehigh_analytics_dirty_works', 'q')->fields('q', ['nid'])->orderBy('nid')->range(0, $limit)->execute()->fetchCol();
      if ($dirty) {
        // Delete before rebuilding so concurrent edits can queue a fresh entry.
        $this->database->delete('lehigh_analytics_dirty_works')->condition('nid', $dirty, 'IN')->execute();
      }
      $works = array_merge($dirty, array_values($changed['node']));
      foreach (array_chunk(array_values($changed['media']), self::CHUNK) as $media) {
        $parents = $this->database->select('media__field_media_of', 'mo')->distinct();
        $parents->addField('mo', 'field_media_of_target_id');
        $parents->condition('entity_id', $media, 'IN')->condition('deleted', 0);
        $works = array_merge($works, $parents->execute()->fetchCol());
      }
      foreach (array_chunk(array_unique(array_map('intval', $works)), self::CHUNK) as $batch) {
        $this->refreshWorks($batch);
      }
      $this->database->merge('lehigh_analytics_progress')->key('id', 1)->fields([
        'cursor' => $status['cursor'],
        'ready' => (int) ($status['caught_up'] || $complete),
        'updated' => $now,
        'signature' => $this->signature(),
      ])->execute();
      unset($transaction);
      $this->status = NULL;
      // Readiness changes need no invalidation: 503 responses are never cached.
      if ($groups || $works || $rebuild) {
        Cache::invalidateTags(['lehigh_analytics']);
      }
      return $complete && count($dirty) < $limit;
    }
    catch (\Throwable $exception) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }
      $this->status = NULL;
      throw $exception;
    }
    finally {
      $this->lock->release('lehigh_analytics.refresh');
    }
  }

  /**
   * Adds a batch's counts to saved totals with a few multi-row statements.
   *
   * Replaces one merge (two queries) per bucket/entity/region group.
   */
  private function addTotals(array $groups): void {
    $table = '{lehigh_analytics_totals}';
    $update = $this->database->databaseType() === 'mysql'
      ? ' ON DUPLICATE KEY UPDATE event_count = event_count + VALUES(event_count), first_event = LEAST(first_event, VALUES(first_event)), last_event = GREATEST(last_event, VALUES(last_event))'
      : " ON CONFLICT (bucket, entity_type, entity_id, region_id) DO UPDATE SET event_count = $table.event_count + excluded.event_count,
        first_event = CASE WHEN excluded.first_event < $table.first_event THEN excluded.first_event ELSE $table.first_event END,
        last_event = CASE WHEN excluded.last_event > $table.last_event THEN excluded.last_event ELSE $table.last_event END";
    foreach (array_chunk(array_values($groups), self::CHUNK) as $chunk) {
      $rows = [];
      $arguments = [];
      foreach ($chunk as $i => $group) {
        $row = [];
        foreach ($group as $field => $value) {
          $row[] = ":{$field}_$i";
          $arguments[":{$field}_$i"] = $value;
        }
        $rows[] = '(' . implode(', ', $row) . ')';
      }
      $this->database->query("INSERT INTO $table (bucket, entity_type, entity_id, region_id, event_count, first_event, last_event) VALUES " . implode(', ', $rows) . $update, $arguments);
    }
  }

  /**
   * Replaces a bounded set of work totals using saved entity counts, not events.
   *
   * Called inside the refresh transaction, together with the ingestion cursor.
   */
  private function refreshWorks(array $nids): void {
    foreach (['lehigh_analytics_work_totals', 'lehigh_analytics_work_countries'] as $table) {
      $this->database->delete($table)->condition('nid', $nids, 'IN')->execute();
    }
    $branches = [];
    foreach (['node', 'media'] as $type) {
      $query = $this->database->select('lehigh_analytics_totals', 'd');
      $query->fields('d', ['bucket', 'entity_type', 'event_count', 'first_event', 'last_event']);
      $query->condition('d.entity_type', $type);
      if ($type === 'node') {
        $query->condition('d.entity_id', $nids, 'IN');
        $query->addField('d', 'entity_id', 'nid');
      }
      else {
        $parents = $this->database->select('media__field_media_of', 'mo')->distinct();
        $parents->fields('mo', ['entity_id', 'field_media_of_target_id']);
        $parents->innerJoin('media_field_data', 'm', 'm.mid = mo.entity_id AND m.langcode = mo.langcode AND m.default_langcode = 1');
        $parents->condition('mo.deleted', 0)->condition('mo.field_media_of_target_id', $nids, 'IN');
        $query->innerJoin($parents, 'parents', 'parents.entity_id = d.entity_id');
        $query->addField('parents', 'field_media_of_target_id', 'nid');
      }
      $query->leftJoin('entity_metrics_regions', 'r', 'r.id = d.region_id');
      $query->addExpression("COALESCE(NULLIF(UPPER(TRIM(r.country)), ''), 'Unknown')", 'country_code');
      $branches[] = $query;
    }
    $branches[0]->union($branches[1], 'ALL');
    $countries = $this->database->select($branches[0], 'e');
    $countries->fields('e', ['bucket', 'nid', 'country_code']);
    $countries->groupBy('e.bucket')->groupBy('e.nid')->groupBy('e.country_code');
    $countries->addExpression("SUM(CASE WHEN e.entity_type = 'node' THEN e.event_count ELSE 0 END)", 'page_views');
    $countries->addExpression("SUM(CASE WHEN e.entity_type = 'media' THEN e.event_count ELSE 0 END)", 'downloads');
    $countries->addExpression('MIN(e.first_event)', 'first_event');
    $countries->addExpression('MAX(e.last_event)', 'last_event');
    $this->database->insert('lehigh_analytics_work_countries')
      ->fields(['bucket', 'nid', 'country_code', 'page_views', 'downloads', 'first_event', 'last_event'])
      ->from($countries)->execute();
    $totals = $this->database->select('lehigh_analytics_work_countries', 'c');
    $totals->fields('c', ['bucket', 'nid'])->condition('nid', $nids, 'IN');
    $totals->groupBy('c.bucket')->groupBy('c.nid');
    $totals->addExpression('SUM(c.page_views)', 'page_views');
    $totals->addExpression('SUM(c.downloads)', 'downloads');
    $totals->addExpression('MIN(c.first_event)', 'first_event');
    $totals->addExpression('MAX(c.last_event)', 'last_event');
    $this->database->insert('lehigh_analytics_work_totals')
      ->fields(['bucket', 'nid', 'page_views', 'downloads', 'first_event', 'last_event'])
      ->from($totals)->execute();
    $this->refreshFlags($nids);
  }

  /**
   * Records each work's publication, document status, and default language.
   *
   * Reports filter on these instead of joining every work to its node and
   * model. Node saves call this directly, so publication changes apply
   * immediately; grants and metadata filters are still read live.
   */
  public function refreshFlags(array $nids): void {
    foreach (array_chunk(array_values(array_unique(array_map('intval', $nids))), self::CHUNK) as $chunk) {
      $nodes = $this->database->select('node_field_data', 'n')->fields('n', ['nid', 'langcode', 'type', 'status'])
        ->condition('n.nid', $chunk, 'IN')->condition('n.default_langcode', 1)
        ->execute()->fetchAllAssoc('nid');
      $excluded = $this->database->select('node__field_model', 'model')->distinct()->fields('model', ['entity_id']);
      $excluded->innerJoin('node_field_data', 'n', 'n.nid = model.entity_id AND n.langcode = model.langcode AND n.default_langcode = 1');
      $excluded->innerJoin('taxonomy_term__field_external_uri', 'uri', 'uri.entity_id = model.field_model_target_id AND uri.deleted = 0');
      $excluded = $excluded->condition('model.entity_id', $chunk, 'IN')->condition('model.deleted', 0)
        ->condition('uri.field_external_uri_uri', [UsageReport::COLLECTION_URI, UsageReport::PAGE_URI], 'IN')
        ->execute()->fetchCol();
      $published = [];
      $languages = [];
      foreach ($nodes as $nid => $node) {
        if ($node->type === 'islandora_object' && (int) $node->status === 1) {
          $published[] = $nid;
        }
        $languages[$node->langcode][] = $nid;
      }
      // Deleted nodes keep their counts but drop out of every report.
      $this->database->update('lehigh_analytics_work_totals')
        ->fields(['published' => 0, 'document' => 1, 'langcode' => ''])
        ->condition('nid', $chunk, 'IN')->execute();
      $this->database->update('lehigh_analytics_work_countries')->fields(['published' => 0])->condition('nid', $chunk, 'IN')->execute();
      if ($published) {
        foreach (['lehigh_analytics_work_totals', 'lehigh_analytics_work_countries'] as $table) {
          $this->database->update($table)->fields(['published' => 1])->condition('nid', $published, 'IN')->execute();
        }
      }
      if ($excluded) {
        $this->database->update('lehigh_analytics_work_totals')->fields(['document' => 0])->condition('nid', $excluded, 'IN')->execute();
      }
      foreach ($languages as $langcode => $ids) {
        $this->database->update('lehigh_analytics_work_totals')->fields(['langcode' => $langcode])->condition('nid', $ids, 'IN')->execute();
      }
    }
  }

}
