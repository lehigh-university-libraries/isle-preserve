<?php

namespace Drupal\lehigh_analytics;

/**
 * Calendar boundaries in the reporting timezone, with exclusive end times.
 */
final class ReportingPeriod {

  /**
   * Returns completed years and the current calendar/fiscal years to date.
   */
  public static function options(int $timestamp, string $timezone, int $fiscal_month): array {
    if ($fiscal_month < 1 || $fiscal_month > 12) {
      throw new \InvalidArgumentException('The fiscal start month must be 1–12.');
    }
    $now = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone($timezone));
    $calendar_end = $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0);
    $fiscal_end = $calendar_end->setDate((int) $now->format('Y'), $fiscal_month, 1);
    if ($now < $fiscal_end) {
      $fiscal_end = $fiscal_end->modify('-1 year');
    }
    $periods = [
      'all' => ['label' => 'All recorded time', 'start' => 0, 'end' => $timestamp + 1],
    ];
    foreach (['fiscal' => $fiscal_end, 'calendar' => $calendar_end] as $key => $end) {
      $start = $end->modify('-1 year');
      $periods[$key] = [
        'label' => sprintf('Latest completed %s year: %s – %s', $key, $start->format('Y-m-d'), $end->modify('-1 day')->format('Y-m-d')),
        'start' => $start->getTimestamp(),
        'end' => $end->getTimestamp(),
      ];
    }
    foreach (['fiscal_ytd' => $fiscal_end, 'calendar_ytd' => $calendar_end] as $key => $start) {
      $periods[$key] = [
        'label' => sprintf('%s year to date: %s – %s', $key === 'fiscal_ytd' ? 'Current fiscal' : 'Calendar', $start->format('Y-m-d'), $now->format('Y-m-d')),
        'start' => $start->getTimestamp(),
        'end' => $timestamp + 1,
      ];
    }
    return $periods;
  }

}
