<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\JournalBrowser;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\node\NodeInterface;
use GuzzleHttp\ClientInterface;

/**
 * Validates migration inputs before changing Drupal-managed browser content.
 */
final class SourceBrowserImporter {

  /**
   * Constructs the importer.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected JournalBrowserRepository $repository,
    protected Connection $database,
    protected FileRepositoryInterface $fileRepository,
    protected FileSystemInterface $fileSystem,
    protected ClientInterface $httpClient,
  ) {}

  /**
   * Reads CSV without executing the legacy PHP or requiring spreadsheet tools.
   */
  public function readCsv(string $path, array $required): array {
    if (!is_file($path) || !is_readable($path) || str_contains($path, '://')) {
      throw new \InvalidArgumentException('A readable local CSV file is required: ' . $path);
    }
    $handle = fopen($path, 'r');
    try {
      $headers = fgetcsv($handle, 0, ',', '"', '');
      if (!$headers) {
        throw new \InvalidArgumentException('The CSV is empty: ' . $path);
      }
      $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
      if (array_diff($required, $headers)) {
        throw new \InvalidArgumentException('CSV requires columns: ' . implode(', ', $required));
      }
      $rows = [];
      while (($values = fgetcsv($handle, 0, ',', '"', '')) !== FALSE) {
        if ($values === [NULL]) {
          continue;
        }
        if (count($values) !== count($headers)) {
          throw new \InvalidArgumentException('CSV column count differs at row ' . (count($rows) + 2));
        }
        $rows[] = array_combine($headers, array_map('trim', $values));
      }
      return $rows;
    }
    finally {
      fclose($handle);
    }
  }

  /**
   * Imports the three Gunn spreadsheet tabs with volume and page ranges intact.
   */
  public function importIndex(NodeInterface $source, string $directory, bool $apply = FALSE): array {
    $subjects = [];
    foreach ($this->readCsv($directory . '/gunn_subjects.csv', ['id', 'subject']) as $row) {
      $id = $this->integer($row['id']);
      if (isset($subjects[$id]) || $row['subject'] === '' || mb_strlen($row['subject']) > 255) {
        throw new \InvalidArgumentException('Invalid or duplicate subject row: ' . $row['id']);
      }
      $subjects[$id] = $row['subject'];
    }
    $volumes = [];
    foreach ($this->readCsv($directory . '/gunn_volumes.csv', ['id', 'ptr']) as $row) {
      $id = $this->integer($row['id']);
      if (isset($volumes[$id])) {
        throw new \InvalidArgumentException('Duplicate volume row: ' . $row['id']);
      }
      $volumes[$id] = 'digitalcollections:gunn_' . $this->integer($row['ptr']);
    }
    $rows = $this->readCsv($directory . '/gunn_index.csv', ['id', 'subj', 'spage', 'epage', 'ptr', 'vol']);
    $nodes = $this->nodesByPid($source);
    $valid = [];
    $errors = [];
    $seen = [];
    foreach ($rows as $row) {
      $legacy_id = 'gunn:' . $this->integer($row['id']);
      $subject_id = $this->integer($row['subj']);
      $volume_id = $this->integer($row['vol']);
      $start = $this->integer($row['spage']);
      $end = $this->integer($row['epage']);
      $pid = 'digitalcollections:gunn_' . $this->integer($row['ptr']);
      $page = $nodes[$pid] ?? NULL;
      $issue = $nodes[$volumes[$volume_id] ?? ''] ?? NULL;
      if (isset($seen[$legacy_id]) || !isset($subjects[$subject_id]) || !isset($volumes[$volume_id]) || $end < $start) {
        $errors[] = 'Invalid index row ' . $row['id'];
      }
      elseif (!$page || !$issue || !$this->repository->isDescendantOf($page, $issue)) {
        $errors[] = 'Missing page or volume mapping for ' . $pid . ' / ' . $volumes[$volume_id];
      }
      else {
        $valid[] = [
          'legacy_id' => $legacy_id,
          'source' => (int) $source->id(),
          'issue' => (int) $issue->id(),
          'page' => (int) $page->id(),
          'subject_label' => $subjects[$subject_id],
          'start_page' => $start,
          'end_page' => $end,
          'volume' => $volume_id,
        ];
      }
      $seen[$legacy_id] = TRUE;
    }
    $report = $this->report(count($rows), count($valid), $errors);
    if (!$apply) {
      return $report;
    }
    $this->assertValid($errors);
    $transaction = $this->database->startTransaction();
    try {
      $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
      $terms = [];
      foreach ($term_storage->loadByProperties(['vid' => 'subject']) as $term) {
        $terms[mb_strtolower($term->label())] = (int) $term->id();
      }
      $storage = $this->entityTypeManager->getStorage('lehigh_source_index_entry');
      $existing = $this->database->select('lehigh_source_index_entry', 'i')->fields('i', ['legacy_id', 'id'])
        ->condition('source', (int) $source->id())->execute()->fetchAllKeyed();
      $page_subjects = [];
      foreach ($valid as $values) {
        $key = mb_strtolower($values['subject_label']);
        if (!isset($terms[$key])) {
          $term = $term_storage->create(['vid' => 'subject', 'name' => $values['subject_label']]);
          $term->save();
          $terms[$key] = (int) $term->id();
        }
        unset($values['subject_label']);
        $values['subject'] = $terms[$key];
        $page_subjects[$values['page']][$terms[$key]] = $terms[$key];
        $entry = isset($existing[$values['legacy_id']]) ? $storage->load($existing[$values['legacy_id']]) : $storage->create();
        $changed = $entry->isNew();
        foreach ($values as $field => $value) {
          $current = $entry->get($field)->getValue()[0] ?? [];
          if ((string) ($current['target_id'] ?? $current['value'] ?? '') !== (string) $value) {
            $changed = TRUE;
            $entry->set($field, $value);
          }
        }
        if ($changed) {
          $entry->save();
          ++$report['written'];
        }
        $storage->resetCache();
      }
      foreach ($page_subjects as $nid => $tids) {
        $page = $this->entityTypeManager->getStorage('node')->load($nid);
        if ($page->hasField('field_subject_general')) {
          $current = array_column($page->get('field_subject_general')->getValue(), 'target_id');
          $combined = array_values(array_unique(array_merge($current, array_values($tids))));
          if ($combined !== $current) {
            $page->set('field_subject_general', $combined);
            $page->setNewRevision(TRUE);
            $page->setRevisionLogMessage('Imported curated Gunn index subjects for this page.');
            $page->save();
          }
        }
      }
    }
    catch (\Throwable $error) {
      $transaction->rollBack();
      throw $error;
    }
    return $report;
  }

  /**
   * Imports selected newspaper dates and part labels by exact existing PID.
   */
  public function importDates(NodeInterface $source, string $path, string $collection, bool $apply = FALSE, bool $overwrite = FALSE): array {
    $rows = array_values(array_filter($this->readCsv($path, [
      'collection', 'target_pid', 'date_issued', 'volume', 'issue',
    ]),
      static fn(array $row): bool => $row['collection'] === $collection));
    if (!$rows) {
      throw new \InvalidArgumentException('No dates match collection ' . $collection);
    }
    $nodes = $this->nodesByPid($source);
    $valid = [];
    $errors = [];
    $seen = [];
    foreach ($rows as $row) {
      $node = $nodes[$row['target_pid']] ?? NULL;
      $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $row['date_issued']);
      if (!$date || $date->format('Y-m-d') !== $row['date_issued'] || isset($seen[$row['target_pid']])) {
        $errors[] = 'Invalid/duplicate date mapping: ' . $row['target_pid'];
      }
      elseif (!$node || !$node->hasField('field_edtf_date_issued') || !$node->hasField('field_part_detail')) {
        $errors[] = 'Missing date target: ' . $row['target_pid'];
      }
      else {
        $existing = (string) $node->get('field_edtf_date_issued')->value;
        $conflict = $existing !== '' && $existing !== $row['date_issued'];
        foreach (['volume', 'issue'] as $type) {
          $number = $this->repository->getPartDetail($node, $type)['number'];
          $conflict = $conflict || ($number !== '' && $number !== $row[$type]);
        }
        if ($conflict && !$overwrite) {
          $errors[] = 'Existing metadata conflicts for ' . $row['target_pid'] . '; review before using --overwrite';
        }
        else {
          $valid[] = [$node, $row];
        }
      }
      $seen[$row['target_pid']] = TRUE;
    }
    $report = $this->report(count($rows), count($valid), $errors);
    if (!$apply) {
      return $report;
    }
    $this->assertValid($errors);
    $transaction = $this->database->startTransaction();
    try {
      foreach ($valid as [$node, $row]) {
        $before = $node->toArray();
        $node->set('field_edtf_date_issued', $row['date_issued']);
        $parts = $node->get('field_part_detail')->getValue();
        foreach (['volume', 'issue'] as $type) {
          $found = FALSE;
          foreach ($parts as &$part) {
            if ($part['type'] === $type) {
              $part['number'] = $row[$type];
              $found = TRUE;
              break;
            }
          }
          unset($part);
          if (!$found) {
            $parts[] = ['type' => $type, 'number' => $row[$type]];
          }
        }
        $node->set('field_part_detail', $parts);
        if ($node->toArray() !== $before) {
          $node->setNewRevision(TRUE);
          $node->setRevisionLogMessage('Imported reviewed source-browser issue dates and part labels.');
          $node->save();
          ++$report['written'];
        }
      }
    }
    catch (\Throwable $error) {
      $transaction->rollBack();
      throw $error;
    }
    return $report;
  }

  /**
   * Imports page-separated Gunn text as private, managed transcription media.
   */
  public function importTranscriptions(NodeInterface $source, string $directory, bool $apply = FALSE): array {
    $paths = glob(rtrim($directory, '/') . '/*.txt');
    if (!$paths) {
      throw new \InvalidArgumentException('No .txt files were found.');
    }
    $nodes = $this->nodesByPid($source);
    $valid = [];
    $errors = [];
    foreach ($paths as $path) {
      $id = pathinfo($path, PATHINFO_FILENAME);
      $pid = 'digitalcollections:gunn_' . $id;
      $node = $nodes[$pid] ?? NULL;
      if (!ctype_digit($id) || !$node || !is_readable($path) || filesize($path) > 10000000) {
        $errors[] = 'Missing target, invalid name, or oversized transcription: ' . basename($path);
      }
      else {
        $text = file_get_contents($path);
        if ($text === FALSE || $text === '' || !mb_check_encoding($text, 'UTF-8')) {
          $errors[] = 'Unreadable, empty, or non-UTF-8 transcription: ' . basename($path);
        }
        else {
          $valid[] = [$node, $pid, $text];
        }
      }
    }
    $report = $this->report(count($paths), count($valid), $errors);
    if (!$apply) {
      return $report;
    }
    $this->assertValid($errors);
    $destination = 'private://source-browser/transcriptions';
    if (!$this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new \RuntimeException('Unable to prepare private transcription storage.');
    }
    $storage = $this->entityTypeManager->getStorage('media');
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $uses = $term_storage->loadByProperties([
      'vid' => 'islandora_media_use',
      'field_external_uri' => 'http://pcdm.org/use#ExtractedText',
    ]);
    $use = reset($uses);
    if (!$use) {
      $use = $term_storage->create([
        'vid' => 'islandora_media_use',
        'name' => 'Extracted Text',
        'field_external_uri' => ['uri' => 'http://pcdm.org/use#ExtractedText'],
      ]);
      $use->save();
    }
    foreach ($valid as [$node, $pid, $text]) {
      $uri = $destination . '/' . hash('sha256', $pid) . '.txt';
      $name = 'Gunn transcription: ' . $pid;
      $existing = $storage->loadByProperties([
        'bundle' => 'extracted_text',
        'field_media_of' => $node->id(),
        'name' => $name,
      ]);
      $media = reset($existing);
      if ($media && is_file($uri) && file_get_contents($uri) === $text) {
        continue;
      }
      $file = $this->fileRepository->writeData($text, $uri, FileExists::Replace);
      $file->setPermanent();
      $file->save();
      $media = $media ?: $storage->create(['bundle' => 'extracted_text', 'name' => $name, 'status' => 1]);
      $media->set('field_media_of', $node->id());
      $media->set('field_media_use', $use->id());
      $media->set('field_media_file', $file->id());
      $media->save();
      ++$report['written'];
    }
    return $report;
  }

  /**
   * Imports CSV transcripts using exact node IDs and cached text.
   */
  public function importTranscriptCsv(NodeInterface $source, string $path, bool $apply = FALSE): array {
    $rows = $this->readCsv($path, ['node_id', 'field_pid', 'Transcript URL']);
    $valid = [];
    $errors = [];
    $seen = [];
    $skipped = 0;
    $node_storage = $this->entityTypeManager->getStorage('node');
    foreach ($rows as $row) {
      if (in_array($row['Transcript URL'], ['', '[volume-level]'], TRUE)) {
        ++$skipped;
        continue;
      }
      $nid = $row['node_id'];
      $pid = $row['field_pid'];
      $pointer = preg_match('/^digitalcollections:gunn_(\d+)$/D', $pid, $matches) ? $matches[1] : '';
      $url = 'https://pfaffs-web.lib.lehigh.edu/gunn_transcripts/' . $pointer . '.txt';
      $node = ctype_digit($nid) ? $node_storage->load($nid) : NULL;
      if ($pointer === '' || $row['Transcript URL'] !== $url || isset($seen[$nid]) || !$node instanceof NodeInterface
        || !$node->hasField('field_pid') || $node->get('field_pid')->value !== $pid
        || !$this->repository->isDescendantOf($node, $source)) {
        $errors[] = 'Invalid node, PID, URL, duplicate, or source membership: ' . $nid;
        continue;
      }
      $seen[$nid] = TRUE;
      $valid[] = [$node, $pointer, $url];
    }
    $report = $this->report(count($rows), count($valid), $errors) + [
      'skipped' => $skipped,
      'downloaded' => 0,
      'cached' => 0,
      'unchanged' => 0,
    ];
    if (!$apply) {
      return $report;
    }
    $this->assertValid($errors);
    $cache_directory = 'private://source-browser/downloads/gunn';
    $this->prepareDirectory($cache_directory);
    foreach ($valid as [$node, $pointer, $url]) {
      try {
        $cache_uri = $cache_directory . '/' . $pointer . '.txt';
        if (is_file($cache_uri)) {
          if (filesize($cache_uri) > 10000000) {
            throw new \RuntimeException('Oversized cached transcript.');
          }
          $text = file_get_contents($cache_uri);
          ++$report['cached'];
        }
        else {
          $response = $this->httpClient->request('GET', $url, [
            'allow_redirects' => FALSE,
            'timeout' => 30,
            'connect_timeout' => 10,
            'stream' => TRUE,
          ]);
          if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Transcript server did not return HTTP 200.');
          }
          $body = $response->getBody();
          try {
            $text = '';
            while (!$body->eof() && strlen($text) <= 10000000) {
              $chunk = $body->read(8192);
              if ($chunk === '') {
                throw new \RuntimeException('Incomplete transcript response.');
              }
              $text .= $chunk;
            }
          }
          finally {
            $body->close();
          }
          $this->validateTranscript($text);
          $temporary = $cache_uri . '.part';
          $this->fileSystem->saveData($text, $temporary, FileExists::Replace);
          $this->fileSystem->move($temporary, $cache_uri, FileExists::Replace);
          ++$report['downloaded'];
        }
        $this->validateTranscript($text);
        if ($this->replaceTranscript($node, $text)) {
          ++$report['written'];
        }
        else {
          ++$report['unchanged'];
        }
      }
      catch (\Exception $error) {
        ++$report['invalid'];
        if (count($report['errors']) < 10) {
          $report['errors'][] = 'Node ' . $node->id() . ': ' . $error->getMessage();
        }
      }
    }
    return $report;
  }

  /**
   * Rejects failed web responses before caching or replacing existing media.
   */
  protected function validateTranscript(mixed $text): void {
    if (!is_string($text) || trim($text) === '' || strlen($text) > 10000000
      || !mb_check_encoding($text, 'UTF-8')
      || preg_match('/^\s*(?:<!doctype\s+html|<html\b)/i', ltrim($text, "\xEF\xBB\xBF"))) {
      throw new \RuntimeException('Empty, oversized, non-UTF-8, or HTML transcript.');
    }
  }

  /**
   * Prepares a private directory before changing existing media.
   */
  protected function prepareDirectory(string $directory): void {
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new \RuntimeException('Unable to prepare private transcript storage.');
    }
  }

  /**
   * Replaces extracted-text media after the new transcript saves.
   */
  protected function replaceTranscript(NodeInterface $node, string $text): bool {
    $action = $this->entityTypeManager->getStorage('action')->load('get_ocr_from_image');
    $configuration = $action ? $action->get('configuration') : [];
    $path = str_replace('[node:nid]', (string) $node->id(), $configuration['path'] ?? '');
    if (($configuration['scheme'] ?? '') !== 'private' || !$path || str_contains($path, '[')
      || str_contains($path, '..') || str_contains($path, ':') || str_contains($path, '\\') || str_starts_with($path, '/')) {
      throw new \RuntimeException('OCR action requires a private path using only [node:nid] tokens.');
    }
    $uri = 'private://' . $path;
    $directory = dirname($uri);
    $this->prepareDirectory($directory);
    $storage = $this->entityTypeManager->getStorage('media');
    $existing = $storage->loadByProperties([
      'bundle' => 'extracted_text',
      'field_media_of' => $node->id(),
    ]);
    if (count($existing) === 1) {
      $current = reset($existing);
      $file = $current->get('field_media_file')->entity;
      if ($current->isPublished() && $file && $file->getFileUri() === $uri
        && is_file($uri) && file_get_contents($uri) === $text) {
        return FALSE;
      }
    }
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $uses = $term_storage->loadByProperties([
      'vid' => 'islandora_media_use',
      'field_external_uri' => 'http://pcdm.org/use#ExtractedText',
    ]);
    $use = reset($uses);
    if (!$use) {
      $use = $term_storage->create([
        'vid' => 'islandora_media_use',
        'name' => 'Extracted Text',
        'field_external_uri' => ['uri' => 'http://pcdm.org/use#ExtractedText'],
      ]);
      $use->save();
    }
    // Stage a separate file so media deletion cannot remove the new text.
    $temporary = $directory . '/gunn-import-' . $node->id() . '-' . bin2hex(random_bytes(8)) . '.txt';
    $file = $this->fileRepository->writeData($text, $temporary, FileExists::Error);
    $file->setPermanent();
    $file->save();
    $media = $storage->create([
      'bundle' => 'extracted_text',
      'status' => 1,
      'name' => 'Gunn transcription: ' . $node->get('field_pid')->value,
      'field_media_of' => $node->id(),
      'field_media_use' => $use->id(),
      'field_media_file' => $file->id(),
    ]);
    $media->save();
    foreach ($existing as $old) {
      $old->delete();
    }
    $file = $this->fileRepository->move($file, $uri, FileExists::Replace);
    $media->set('field_media_file', $file->id());
    $media->save();
    return TRUE;
  }

  /**
   * Maps only existing, accessible nodes belonging to this source.
   */
  protected function nodesByPid(NodeInterface $source): array {
    $nodes = [];
    foreach ([$source] + $this->repository->getDescendants($source) as $node) {
      if ($node->hasField('field_pid') && !$node->get('field_pid')->isEmpty()) {
        $pid = (string) $node->get('field_pid')->value;
        if (isset($nodes[$pid])) {
          throw new \InvalidArgumentException('Ambiguous duplicate PID: ' . $pid);
        }
        $nodes[$pid] = $node;
      }
    }
    return $nodes;
  }

  /**
   * Accepts integral spreadsheet numbers without silently truncating values.
   */
  protected function integer(string $value): int {
    if (!preg_match('/^\d+(?:\.0)?$/', $value)) {
      throw new \InvalidArgumentException('Expected a nonnegative integer, got: ' . $value);
    }
    return (int) $value;
  }

  /**
   * Summarizes the complete preflight, retaining a bounded sample of errors.
   */
  protected function report(int $rows, int $matched, array $errors): array {
    return [
      'rows' => $rows,
      'matched' => $matched,
      'invalid' => count($errors),
      'errors' => array_slice(array_values(array_unique($errors)), 0, 10),
      'written' => 0,
    ];
  }

  /**
   * Rejects the entire import before writing when any mapping is invalid.
   */
  protected function assertValid(array $errors): void {
    if ($errors) {
      throw new \InvalidArgumentException('Import preflight failed: ' . implode('; ', array_slice(array_unique($errors), 0, 10)));
    }
  }

}
