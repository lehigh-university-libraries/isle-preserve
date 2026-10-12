<?php

// Run with: php scripts/tests/edtf-converter.php (after Composer patches apply).
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require $root . '/web/modules/contrib/rdf/src/CommonDataConverter.php';
require $root . '/web/modules/contrib/controlled_access_terms/src/EDTFUtils.php';
require $root . '/web/modules/contrib/controlled_access_terms/src/EDTFConverter.php';

use Drupal\controlled_access_terms\EDTFConverter;

set_error_handler(static function ($severity, $message, $file, $line) {
  throw new ErrorException($message, 0, $severity, $file, $line);
});

foreach ([
  [[], ''],
  [['value' => NULL], ''],
  [['value' => ''], ''],
  [['value' => '2020-05-01'], '2020-05-01'],
  [['value' => '2020/2021'], '2020'],
  [['value' => '[2020,2021]'], '2020'],
] as [$input, $expected]) {
  foreach (['datetimeIso8601Value', 'dateIso8601Value'] as $method) {
    if (EDTFConverter::$method($input) !== $expected) {
      throw new RuntimeException("Unexpected result from $method for " . json_encode($input));
    }
  }
}

restore_error_handler();
echo "EDTF conversion passed without warnings.\n";
