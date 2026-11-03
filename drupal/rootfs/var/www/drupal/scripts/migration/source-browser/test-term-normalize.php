<?php

/**
 * @file
 * Checks source-browser subject label normalization.
 */

declare(strict_types=1);

require __DIR__ . '/term-normalize.php';

$cases = [
  'Seaman&#xFFFD;s Bethel Church' => ['Seaman�s Bethel Church'],
  '10 Rodney Buildings, New Kent Road&#xFFFD;132 Bleecker Street' => ['10 Rodney Buildings, New Kent Road�132 Bleecker Street'],
  '[New York, New York]; Charleston, [South Carolina]' => ['[New York, New York]', 'Charleston, [South Carolina]'],
  'Fish &amp; Chips; Broadway' => ['Fish & Chips', 'Broadway'],
  'A&semi;B; C' => ['A;B', 'C'],
];
foreach ($cases as $input => $expected) {
  if (source_browser_normalize_term($input)['parts'] !== $expected) {
    throw new RuntimeException('Unexpected split for: ' . $input);
  }
}
if (!source_browser_normalize_term('Seaman&#xFFFD;s Bethel Church')['flags']) {
  throw new RuntimeException('Replacement characters must be flagged for review.');
}
echo "Term normalization checks passed.\n";
