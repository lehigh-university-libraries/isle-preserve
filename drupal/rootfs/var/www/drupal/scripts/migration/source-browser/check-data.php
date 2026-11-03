<?php

declare(strict_types=1);

$options = getopt('', ['directory:', 'subject:']);
$directory = $options['directory'] ?? __DIR__;
$subjectSearch = $options['subject'] ?? 'Clapp, Henry';
function readRows(string $path, array $required): array {
  $file = new SplFileObject($path);
  $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
  $file->setCsvControl(',', '"', '');
  $headers = $file->fgetcsv();
  if ($headers) {
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
  }
  if (!$headers || array_diff($required, $headers)) {
    throw new RuntimeException("Missing headers in $path");
  }
  $rows = [];
  while (!$file->eof()) {
    $row = $file->fgetcsv();
    if (!$row || $row === [NULL]) {
      continue;
    }
    if (count($row) !== count($headers)) {
      throw new RuntimeException("Malformed row in $path at line " . $file->key());
    }
    $rows[] = array_combine($headers, $row);
  }
  return $rows;
}
function duplicates(array $rows, string $field): array {
  return array_filter(array_count_values(array_column($rows, $field)), static fn($count) => $count > 1);
}
try {
  $subjects = readRows("$directory/gunn_subjects.csv", ['id', 'subject']);
  $volumes = readRows("$directory/gunn_volumes.csv", ['id', 'ptr']);
  $index = readRows("$directory/gunn_index.csv", ['id', 'subj', 'spage', 'epage', 'ptr', 'vol']);
  $dates = readRows("$directory/legacy-issue-dates.csv", ['collection', 'target_pid', 'date_issued']);
  $transcripts = readRows("$directory/gunn.csv", ['node_id', 'field_pid', 'Transcript URL']);
  $errors = [];
  foreach (['subject' => $subjects, 'volume' => $volumes, 'index' => $index] as $name => $rows) {
    foreach (duplicates($rows, 'id') as $id => $_) {
      $errors[] = "duplicate $name ID: $id";
    }
  }
  $subjectIds = array_flip(array_column($subjects, 'id'));
  $volumeIds = array_flip(array_column($volumes, 'id'));
  foreach ($index as $row) {
    $valid = isset($subjectIds[$row['subj']], $volumeIds[$row['vol']]);
    foreach (['id', 'subj', 'spage', 'epage', 'ptr', 'vol'] as $key) {
      $valid = $valid && ctype_digit($row[$key]);
    }
    if (!$valid || (int) $row['spage'] > (int) $row['epage']) {
      $errors[] = 'invalid index row: ' . $row['id'];
    }
  }
  foreach (duplicates($dates, 'target_pid') as $pid => $_) {
    $errors[] = "duplicate newspaper PID: $pid";
  }
  foreach ($dates as $row) {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $row['date_issued']);
    if (!$date || $date->format('Y-m-d') !== $row['date_issued']) {
      $errors[] = 'invalid date: ' . $row['target_pid'] . ' ' . $row['date_issued'];
    }
  }
  $active = array_values(array_filter($transcripts, static fn($row) => !in_array($row['Transcript URL'], ['', '[volume-level]'], TRUE)));
  foreach (duplicates($active, 'node_id') as $id => $_) {
    $errors[] = "duplicate transcript node: $id";
  }
  foreach ($active as $row) {
    if (!ctype_digit($row['node_id']) || !preg_match('/^digitalcollections:gunn_(\d+)$/D', $row['field_pid'], $match)
      || $row['Transcript URL'] !== 'https://pfaffs-web.lib.lehigh.edu/gunn_transcripts/' . $match[1] . '.txt') {
      $errors[] = 'invalid transcript mapping: ' . $row['node_id'];
    }
  }
  $groups = [];
  foreach ($subjects as $row) {
    $groups[mb_strtolower(preg_replace('/\s+/u', ' ', trim($row['subject']))) ][] = $row;
  }
  printf("Subjects: %d; volumes: %d; index rows: %d\n", count($subjects), count($volumes), count($index));
  echo 'Newspaper dates: ' . json_encode(array_count_values(array_column($dates, 'collection'))) . "\n";
  printf("Transcript URLs: %d; blank/volume-level: %d\n", count($active), count($transcripts) - count($active));
  echo "\nPossible duplicate subject labels (case and whitespace ignored):\n";
  foreach ($groups as $group) {
    if (count($group) > 1) {
      echo '  ' . implode(' | ', array_map(static fn($r) => $r['id'] . ': ' . $r['subject'], $group)) . "\n";
    }
  }
  echo "\nSubjects containing semicolons (review; do not split automatically):\n";
  foreach ($subjects as $row) {
    if (str_contains($row['subject'], ';')) {
      echo '  ' . $row['id'] . ': ' . $row['subject'] . "\n";
    }
  }
  echo "\nSubject matches for '$subjectSearch':\n";
  foreach ($subjects as $subject) {
    if (mb_stripos($subject['subject'], $subjectSearch) === FALSE) {
      continue;
    }
    $matches = array_filter($index, static fn($row) => $row['subj'] === $subject['id']);
    printf("  %s: %s (%d references)\n", $subject['id'], $subject['subject'], count($matches));
    foreach ($matches as $row) {
      printf("    index %s: volume %s, pages %s–%s, page PID gunn_%s\n", $row['id'], $row['vol'], $row['spage'], $row['epage'], $row['ptr']);
    }
  }
  echo "\nValidation errors: " . count($errors) . "\n";
  foreach ($errors as $error) {
    echo "  $error\n";
  }
  exit($errors ? 1 : 0);
}
catch (Throwable $e) {
  fwrite(STDERR, 'Unable to inspect CSVs: ' . $e->getMessage() . "\n");
  exit(2);
}
