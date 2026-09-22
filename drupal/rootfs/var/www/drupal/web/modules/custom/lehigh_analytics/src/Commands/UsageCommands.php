<?php

namespace Drupal\lehigh_analytics\Commands;

use Drupal\lehigh_analytics\UsageRollup;
use Drush\Commands\DrushCommands;

/**
 * Builds usage totals outside the web request timeout.
 */
final class UsageCommands extends DrushCommands {

  public function __construct(private readonly UsageRollup $rollup) {
    parent::__construct();
  }

  /**
   * Refreshes usage totals; interrupted runs resume from their last batch.
   *
   * @command lehigh-analytics:refresh
   * @option rebuild Rebuild derived totals after source corrections or setting changes.
   * @usage lehigh-analytics:refresh
   *   Backfill or catch up the saved usage totals.
   */
  public function refresh(array $options = ['rebuild' => FALSE]): void {
    $complete = $this->rollup->refresh(5000, (bool) $options['rebuild']);
    while (!$complete) {
      $this->logger()->notice('Processed through event ID ' . $this->rollup->status()['cursor']);
      $complete = $this->rollup->refresh();
    }
    $this->logger()->success('Usage totals are ready.');
  }

}
