<?php

declare(strict_types=1);

// Export the archived workbook without changing its contents.
$zip = new ZipArchive();
if ($zip->open(__DIR__ . '/gunn-index.xlsx') !== TRUE) {
  throw new RuntimeException('Cannot open gunn-index.xlsx');
}
function xmlFile(ZipArchive $zip, string $path): SimpleXMLElement {
  $data = $zip->getFromName($path);
  if ($data === FALSE || !($xml = simplexml_load_string($data, SimpleXMLElement::class, LIBXML_NONET))) {
    throw new RuntimeException("Cannot read workbook XML: $path");
  }
  return $xml;
}
$main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
$relationship = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
$strings = [];
$xml = xmlFile($zip, 'xl/sharedStrings.xml');
$xml->registerXPathNamespace('m', $main);
foreach ($xml->xpath('/m:sst/m:si') as $item) {
  $item->registerXPathNamespace('m', $main);
  $strings[] = implode('', array_map('strval', $item->xpath('.//m:t')));
}
$rels = [];
foreach (xmlFile($zip, 'xl/_rels/workbook.xml.rels')->children() as $rel) {
  $rels[(string) $rel['Id']] = (string) $rel['Target'];
}
$workbook = xmlFile($zip, 'xl/workbook.xml');
$workbook->registerXPathNamespace('m', $main);
foreach ($workbook->xpath('/m:workbook/m:sheets/m:sheet') as $sheet) {
  $name = (string) $sheet['name'];
  if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $name)) {
    throw new RuntimeException("Invalid sheet name: $name");
  }
  $id = (string) $sheet->attributes($relationship)['id'];
  $target = ltrim($rels[$id] ?? '', '/');
  $target = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
  $document = xmlFile($zip, $target);
  $document->registerXPathNamespace('m', $main);
  $rows = [];
  foreach ($document->xpath('/m:worksheet/m:sheetData/m:row') as $row) {
    $values = [];
    foreach ($row->children($main)->c as $cell) {
      preg_match('/^[A-Z]+/', (string) $cell->attributes()['r'], $match);
      $column = 0;
      foreach (str_split($match[0]) as $letter) {
        $column = $column * 26 + ord($letter) - 64;
      }
      while (count($values) < $column) {
        $values[] = '';
      }
      $type = (string) $cell->attributes()['t'];
      $content = $cell->children($main);
      if ($type === 's') {
        $value = $strings[(int) $content->v] ?? '';
      }
      elseif ($type === 'inlineStr') {
        $cell->registerXPathNamespace('m', $main);
        $value = implode('', array_map('strval', $cell->xpath('.//m:t')));
      }
      else {
        $value = (string) $content->v;
      }
      $values[$column - 1] = preg_match('/^\d+\.0$/D', $value) ? substr($value, 0, -2) : $value;
    }
    if (!$rows) {
      while ($values && end($values) === '') {
        array_pop($values);
      }
    }
    else {
      $width = count($rows[0]);
      if (array_filter(array_slice($values, $width), static fn($value) => $value !== '')) {
        throw new RuntimeException("Nonempty data outside named columns in $name");
      }
      $values = array_pad(array_slice($values, 0, $width), $width, '');
    }
    $rows[] = $values;
  }
  $out = fopen(__DIR__ . '/' . $name . '.csv', 'w');
  foreach ($rows as $row) {
    fputcsv($out, $row, ',', '"', '', "\n");
  }
  fclose($out);
  printf("%s: %d records\n", $name, count($rows) - 1);
}
