<?php

declare(strict_types=1);

namespace Drupal\Tests\lehigh_analytics\Unit;

use Drupal\lehigh_analytics\ReportingPeriod;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Checks fiscal rollover, leap years, and timezone-aware half-open ranges.
 */
#[Group('lehigh_analytics')]
final class ReportingPeriodTest extends UnitTestCase {

  /**
   * The September communications periods use completed years and live YTD.
   */
  public function testSeptemberPeriods(): void {
    $now = strtotime('2026-09-22T12:00:00-04:00');
    $periods = ReportingPeriod::options($now, 'America/New_York', 7);
    $this->assertSame(strtotime('2025-07-01T00:00:00-04:00'), $periods['fiscal']['start']);
    $this->assertSame(strtotime('2026-07-01T00:00:00-04:00'), $periods['fiscal']['end']);
    $this->assertSame(strtotime('2025-01-01T00:00:00-05:00'), $periods['calendar']['start']);
    $this->assertSame(strtotime('2026-01-01T00:00:00-05:00'), $periods['calendar']['end']);
    $this->assertSame($periods['calendar']['end'], $periods['calendar_ytd']['start']);
    $this->assertSame($periods['fiscal']['end'], $periods['fiscal_ytd']['start']);
    foreach (['all', 'calendar_ytd', 'fiscal_ytd'] as $key) {
      $this->assertSame($now + 1, $periods[$key]['end']);
    }
  }

  /**
   * UTC July 1 is still the prior fiscal year in Pennsylvania until midnight.
   */
  public function testFiscalRolloverAndLeapYear(): void {
    $before = ReportingPeriod::options(strtotime('2026-07-01T03:59:59Z'), 'America/New_York', 7);
    $after = ReportingPeriod::options(strtotime('2026-07-01T04:00:00Z'), 'America/New_York', 7);
    $this->assertSame(strtotime('2024-07-01T00:00:00-04:00'), $before['fiscal']['start']);
    $this->assertSame($before['fiscal']['end'], $after['fiscal']['start']);
    $this->assertSame($before['fiscal_ytd']['start'], $after['fiscal']['start']);
    $leap = ReportingPeriod::options(strtotime('2025-01-01T05:00:00Z'), 'America/New_York', 1);
    $this->assertSame(366 * 86400, $leap['calendar']['end'] - $leap['calendar']['start']);
    $this->assertSame($leap['calendar']['start'], $leap['fiscal']['start']);
  }

}
