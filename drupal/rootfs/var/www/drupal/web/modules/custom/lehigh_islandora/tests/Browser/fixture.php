<?php

/**
 * @file
 * Creates isolated local fixtures for source-browser browser checks.
 *
 * Run on the disposable test site only:
 * `drush scr path/to/fixture.php -- /tmp/source-browser-fixture-ID.json`.
 * The JSON output lists exactly which entities to delete after verification.
 */

use Drupal\Core\File\FileSystemInterface;

if (!in_array(getenv('DRUPAL_DEFAULT_SITE_URL'), ['islandora.dev', 'wight.cc.lehigh.edu'], TRUE)) {
  throw new \RuntimeException('Fixtures may only be created on the disposable local or CI site.');
}
$state_path = $extra[0] ?? NULL;
if (!$state_path || !str_starts_with($state_path, '/tmp/source-browser-fixture-')) {
  throw new \InvalidArgumentException('Provide a unique /tmp/source-browser-fixture-*.json state path.');
}
$entity_manager = \Drupal::entityTypeManager();
$created = [];
$record = static function (string $type, int $id) use (&$created, $state_path): void {
  $created[$type][] = $id;
  if (file_put_contents($state_path, json_encode(['created' => $created], JSON_THROW_ON_ERROR), LOCK_EX) === FALSE) {
    throw new \RuntimeException('Unable to record fixture state.');
  }
};
$create = function (string $type, array $values) use ($entity_manager, $record) {
  $entity = $entity_manager->getStorage($type)->create($values);
  $entity->save();
  $record($type, (int) $entity->id());
  return $entity;
};
$account = $create('user', [
  'name' => 'source-browser-test-' . bin2hex(random_bytes(8)),
  'status' => 1,
]);
$collection_model = $create('taxonomy_term', [
  'vid' => 'islandora_models',
  'name' => 'Collection',
  'field_external_uri' => ['uri' => 'http://purl.org/dc/dcmitype/Collection'],
]);
$issue_model = $create('taxonomy_term', ['vid' => 'islandora_models', 'name' => 'Paged Content']);
$page_model = $create('taxonomy_term', ['vid' => 'islandora_models', 'name' => 'Page']);
$hint = $create('taxonomy_term', ['vid' => 'islandora_display', 'name' => 'Journal Browser']);
$use = $create('taxonomy_term', [
  'vid' => 'islandora_media_use',
  'name' => 'Service File',
  'field_external_uri' => ['uri' => 'http://pcdm.org/use#ServiceFile'],
]);
$subject = $create('taxonomy_term', ['vid' => 'subject', 'name' => 'New York picayune.']);
$source = $create('node', [
  'type' => 'islandora_object',
  'title' => 'Gunn Diaries — browser test fixture',
  'status' => 1,
  'field_model' => $collection_model->id(),
  'field_display_hints' => $hint->id(),
]);
$issues = [];
$pages = [];
$directory = 'private://source-browser-test-' . $source->id();
\Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
foreach ([1, 2] as $volume_number) {
  $issue = $create('node', [
    'type' => 'islandora_object',
    'title' => 'Gunn diary volume ' . $volume_number,
    'status' => 1,
    'field_member_of' => $source->id(),
    'field_model' => $issue_model->id(),
    'field_edtf_date_issued' => (1849 + $volume_number) . '-06-12',
    'field_part_detail' => [
      'type' => 'volume',
      'number' => (string) $volume_number,
    ],
  ]);
  $issues[] = (int) $issue->id();
  foreach ($volume_number === 1 ? [1, 2, 3, 4] : [1, 2] as $page_number) {
    $page = $create('node', [
      'type' => 'islandora_object',
      'title' => 'Diary page ' . $page_number,
      'status' => 1,
      'field_member_of' => $issue->id(),
      'field_model' => $page_model->id(),
      'field_subject_general' => $subject->id(),
      'field_weight' => $page_number,
      'field_part_detail' => [
        'type' => 'page',
        'number' => (string) $page_number,
      ],
      'field_description' => [
        'value' => 'Needle: a diary entry about newspaper offices.',
        'format' => 'plain_text',
      ],
    ]);
    $pages[] = (int) $page->id();
    if ($volume_number !== 1 || $page_number !== 4) {
      $image = imagecreatetruecolor(500, 700);
      $paper = imagecolorallocate($image, 250, 245, 232);
      $ink = imagecolorallocate($image, 65, 46, 28);
      imagefill($image, 0, 0, $paper);
      imagestring($image, 5, 45, 65, 'Browser test fixture', $ink);
      imagestring($image, 4, 45, 100, 'Gunn diary volume ' . $volume_number . ', page ' . $page_number, $ink);
      for ($line = 0; $line < 18; ++$line) {
        imagestring($image, 3, 45, 145 + $line * 24, 'Sample diary text for viewer verification.', $ink);
      }
      ob_start();
      imagepng($image);
      $png = ob_get_clean();
      $file = \Drupal::service('file.repository')->writeData($png, $directory . '/' . $page->id() . '.png');
      $record('file', (int) $file->id());
      $media = $create('media', [
        'bundle' => 'image',
        'name' => 'Fixture page image',
        'status' => 1,
        'field_media_of' => $page->id(),
        'field_media_use' => $use->id(),
        'field_media_image' => [
          'target_id' => $file->id(),
          'alt' => 'Browser fixture',
          'width' => 500,
          'height' => 700,
        ],
      ]);
      $page->set('field_thumbnail', $media->id())->save();
    }
    $file = \Drupal::service('file.repository')->writeData("Needle: café diary transcription, volume $volume_number, page $page_number.\nLiteral <brackets> are preserved.", $directory . '/' . $page->id() . '.txt');
    $record('file', (int) $file->id());
    $create('media', [
      'bundle' => 'extracted_text',
      'name' => 'Fixture page transcription',
      'status' => 1,
      'field_media_of' => $page->id(),
      'field_media_file' => $file->id(),
    ]);
  }
}
foreach (['OriginalFile', 'ServiceFile'] as $kind) {
  $pdf_use = $create('taxonomy_term', [
    'vid' => 'islandora_media_use',
    'name' => $kind,
    'field_external_uri' => ['uri' => 'http://pcdm.org/use#' . $kind],
  ]);
  $file = \Drupal::service('file.repository')->writeData('%PDF-1.4 fixture', $directory . '/' . $kind . '.pdf');
  $record('file', (int) $file->id());
  $create('media', [
    'bundle' => 'document',
    'name' => 'Fixture ' . $kind,
    'status' => 1,
    'field_media_of' => $issues[0],
    'field_media_use' => $pdf_use->id(),
    'field_media_document' => $file->id(),
  ]);
  if ($kind === 'ServiceFile') {
    $create('media', [
      'bundle' => 'document',
      'name' => 'Fixture page PDF',
      'status' => 1,
      'field_media_of' => $pages[0],
      'field_media_use' => $pdf_use->id(),
      'field_media_document' => $file->id(),
    ]);
  }
}
$fixture = [
  'user' => (int) $account->id(),
  'source' => (int) $source->id(),
  'issues' => $issues,
  'pages' => $pages,
  'created' => $created,
];
file_put_contents($state_path, json_encode($fixture, JSON_THROW_ON_ERROR), LOCK_EX);
print json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
