<?php

namespace Drupal\lehigh_analytics\Commands;

use Drupal\lehigh_analytics\UsageReport;
use Drupal\lehigh_analytics\UsageRollup;
use Drush\Commands\DrushCommands;

/**
 * Builds usage totals outside the web request timeout.
 */
final class UsageCommands extends DrushCommands {

  public function __construct(
    private readonly UsageRollup $rollup,
    private readonly UsageReport $reports,
  ) {
    parent::__construct();
  }

  /**
   * Refreshes usage totals; interrupted runs resume from their last batch.
   *
   * Once caught up, the explorer's default (unfiltered) reports are generated
   * so the first visitor after a refresh doesn't wait for them.
   *
   * @command lehigh-analytics:refresh
   * @option rebuild Rebuild derived totals after source corrections or setting changes.
   * @usage lehigh-analytics:refresh
   *   Backfill or catch up the saved usage totals.
   */
  public function refresh(array $options = ['rebuild' => FALSE]): void {
    $complete = $this->rollup->refresh(5000, (bool) $options['rebuild']);
    while (!$complete) {
      $status = $this->rollup->status();
      $this->logger()->notice('Processed through event ID ' . $status['cursor']);
      $complete = $this->rollup->refresh();
    }
    $this->reports->warm();
    $this->logger()->success('Usage totals are ready.');
  }

}
