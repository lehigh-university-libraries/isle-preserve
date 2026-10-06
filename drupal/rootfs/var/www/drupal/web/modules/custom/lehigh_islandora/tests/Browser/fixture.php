<?php

/**
 * @file
 * Creates isolated local fixtures for source-browser browser checks.
 *
 * Run with `drush scr path/to/fixture.php` on the local disposable site only.
 * The JSON output lists exactly which entities to delete after verification.
 */

$entity_manager = \Drupal::entityTypeManager();
$created = [];
$create = function (string $type, array $values) use ($entity_manager, &$created) {
  $entity = $entity_manager->getStorage($type)->create($values);
  $entity->save();
  $created[$type][] = (int) $entity->id();
  return $entity;
};
$collection_model = $create('taxonomy_term', [
  'vid' => 'islandora_models', 'name' => 'Collection',
  'field_external_uri' => ['uri' => 'http://purl.org/dc/dcmitype/Collection'],
]);
$issue_model = $create('taxonomy_term', ['vid' => 'islandora_models', 'name' => 'Paged Content']);
$page_model = $create('taxonomy_term', ['vid' => 'islandora_models', 'name' => 'Page']);
$hint = $create('taxonomy_term', ['vid' => 'islandora_display', 'name' => 'Journal Browser']);
$use = $create('taxonomy_term', [
  'vid' => 'islandora_media_use', 'name' => 'Service File',
  'field_external_uri' => ['uri' => 'http://pcdm.org/use#ServiceFile'],
]);
$subject = $create('taxonomy_term', ['vid' => 'subject', 'name' => 'New York picayune.']);
$source = $create('node', [
  'type' => 'islandora_object', 'title' => 'Gunn Diaries — browser test fixture', 'status' => 1,
  'field_model' => $collection_model->id(), 'field_display_hints' => $hint->id(),
]);
$issues = [];
$pages = [];
$directory = 'private://source-browser-test-' . $source->id();
\Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
foreach ([1, 2] as $volume_number) {
  $issue = $create('node', [
    'type' => 'islandora_object', 'title' => 'Gunn diary volume ' . $volume_number, 'status' => 1,
    'field_member_of' => $source->id(), 'field_model' => $issue_model->id(),
    'field_edtf_date_issued' => (1849 + $volume_number) . '-06-12',
    'field_part_detail' => ['type' => 'volume', 'number' => (string) $volume_number],
  ]);
  $issues[] = (int) $issue->id();
  foreach ([1, 2] as $page_number) {
    $page = $create('node', [
      'type' => 'islandora_object', 'title' => 'Diary page ' . $page_number, 'status' => 1,
      'field_member_of' => $issue->id(), 'field_model' => $page_model->id(),
      'field_subject_general' => $subject->id(), 'field_weight' => $page_number,
      'field_part_detail' => ['type' => 'page', 'number' => (string) $page_number],
      'field_description' => ['value' => 'Needle: a diary entry about newspaper offices.', 'format' => 'plain_text'],
    ]);
    $pages[] = (int) $page->id();
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
    imagedestroy($image);
    $file = \Drupal::service('file.repository')->writeData($png, $directory . '/' . $page->id() . '.png');
    $created['file'][] = (int) $file->id();
    $media = $create('media', [
      'bundle' => 'image', 'name' => 'Fixture page image', 'status' => 1,
      'field_media_of' => $page->id(), 'field_media_use' => $use->id(),
      'field_media_image' => ['target_id' => $file->id(), 'alt' => 'Browser fixture', 'width' => 500, 'height' => 700],
    ]);
    $page->set('field_thumbnail', $media->id())->save();
    $file = \Drupal::service('file.repository')->writeData("Needle: café diary transcription, volume $volume_number, page $page_number.\nLiteral <brackets> are preserved.", $directory . '/' . $page->id() . '.txt');
    $created['file'][] = (int) $file->id();
    $create('media', [
      'bundle' => 'extracted_text', 'name' => 'Fixture page transcription', 'status' => 1,
      'field_media_of' => $page->id(), 'field_media_file' => $file->id(),
    ]);
  }
}
foreach (['OriginalFile', 'ServiceFile'] as $kind) {
  $pdf_use = $create('taxonomy_term', [
    'vid' => 'islandora_media_use', 'name' => $kind,
    'field_external_uri' => ['uri' => 'http://pcdm.org/use#' . $kind],
  ]);
  $file = \Drupal::service('file.repository')->writeData('%PDF-1.4 fixture', $directory . '/' . $kind . '.pdf');
  $created['file'][] = (int) $file->id();
  $create('media', [
    'bundle' => 'document', 'name' => 'Fixture ' . $kind, 'status' => 1,
    'field_media_of' => $issues[0], 'field_media_use' => $pdf_use->id(), 'field_media_document' => $file->id(),
  ]);
}
print json_encode(['source' => (int) $source->id(), 'issues' => $issues, 'pages' => $pages, 'created' => $created], JSON_PRETTY_PRINT);
