<?php

declare(strict_types=1);

if ($argc !== 3) {
  fwrite(STDERR, "Usage: php convert-dates.php newspaper.csv collection-key\n");
  exit(1);
}
try {
  $file = new SplFileObject($argv[1]);
  $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
  $file->setCsvControl(',', '"', '');
  $headers = $file->fgetcsv();
  if ($headers) {
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
  }
  if (!$headers || array_diff(['Vol/Iss', 'Date', 'CDM ID'], $headers)) {
    throw new RuntimeException('Expected CSV headers Vol/Iss, Date, CDM ID');
  }
  $rows = [];
  $seen = [];
  while (!$file->eof()) {
    $values = $file->fgetcsv();
    if (!$values || $values === [NULL]) {
      continue;
    }
    if (count($values) !== count($headers)) {
      throw new RuntimeException('Invalid CSV row at line ' . ($file->key() + 1));
    }
    $row = array_combine($headers, $values);
    $id = trim($row['CDM ID']);
    $date_text = trim($row['Date']);
    $date = DateTimeImmutable::createFromFormat('!n/j/Y', $date_text);
    $date_valid = preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/D', $date_text, $date_parts)
      && checkdate((int) $date_parts[1], (int) $date_parts[2], (int) $date_parts[3]);
    if (!preg_match('/^\s*(\d+)\s*\(\s*(\d+)\s*\)\s*$/D', $row['Vol/Iss'], $parts)
      || !ctype_digit($id) || isset($seen[$id]) || !$date || !$date_valid) {
      throw new RuntimeException('Invalid volume/issue, date, CDM ID, or duplicate ID at line ' . ($file->key() + 1));
    }
    $seen[$id] = TRUE;
    $rows[] = [$argv[2], 'digitalcollections:pfaffs_' . $id, $date->format('Y-m-d'), $parts[1], $parts[2]];
  }
  if (!$rows) {
    throw new RuntimeException('CSV contains no data rows');
  }
  $out = fopen('php://output', 'w');
  fputcsv($out, ['collection', 'target_pid', 'date_issued', 'volume', 'issue'], ',', '"', '');
  foreach ($rows as $row) {
    fputcsv($out, $row, ',', '"', '');
  }
}
catch (Throwable $e) {
  fwrite(STDERR, 'Cannot convert dates: ' . $e->getMessage() . "\n");
  exit(1);
}
