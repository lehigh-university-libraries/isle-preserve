<?php

declare(strict_types=1);

namespace Drupal\Tests\lehigh_islandora\ExistingSite;

use Drupal\lehigh_islandora\JournalBrowser\SourceBrowserImporter;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Request;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Verifies the collection metadata switch and browser data flow.
 */
final class SourceBrowserTest extends ExistingSiteBase {

  use \weitzman\DrupalTestTraits\Entity\MediaCreationTrait;

  /**
   * Creates a tracked term in an existing Islandora vocabulary.
   */
  private function browserTerm(array $values) {
    $vocabulary = \Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary')->load($values['vid']);
    return $this->createTerm($vocabulary, $values);
  }

  /**
   * Enabling and removing metadata switches the collection view.
   */
  public function testCollectionDisplayHint(): void {
    $model = $this->browserTerm([
      'vid' => 'islandora_models',
      'name' => 'Collection',
      'field_external_uri' => ['uri' => 'http://purl.org/dc/dcmitype/Collection'],
    ]);
    $hint = $this->browserTerm(['vid' => 'islandora_display', 'name' => 'Journal Browser']);
    $source = $this->createNode([
      'type' => 'islandora_object',
      'title' => 'Browser test collection',
      'status' => 1,
      'field_model' => $model->id(),
    ]);
    $repository = \Drupal::service('lehigh_islandora.journal_browser.repository');
    $this->assertFalse($repository->isBrowserSource($source));
    $source->set('field_display_hints', $hint->id())->save();
    $this->assertTrue($repository->isBrowserSource($source));
    $this->assertArrayHasKey($source->id(), $repository->getSources($source));

    $issue_model = $this->browserTerm(['vid' => 'islandora_models', 'name' => 'Publication Issue']);
    $subject = $this->browserTerm(['vid' => 'subject', 'name' => 'Picayune Office']);
    $z_subject = $this->browserTerm(['vid' => 'subject', 'name' => 'Zebra index test']);
    $issue = $this->createNode([
      'type' => 'islandora_object',
      'title' => 'Test diary volume',
      'status' => 1,
      'field_model' => $issue_model->id(),
      'field_member_of' => $source->id(),
      'field_edtf_date_created' => '1850-06-12',
      'field_subject' => [$subject->id(), $z_subject->id()],
    ]);
    $this->assertSame('1850-06-12', $repository->getDate($issue));
    $this->assertArrayHasKey($issue->id(), $repository->getIssues($source));
    $index = $repository->buildAlphabeticalIndex([$issue]);
    $this->assertSame('Picayune Office', $index['P'][0]['title']);
    $this->assertSame($issue->label(), $index['P'][0]['hits'][0]['title']);

    $this->drupalGet('/browse-items/' . $source->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '[data-source-browser]');
    $this->assertSession()->pageTextContains('1850-06-12');
    $this->drupalGet('/browse-source', ['query' => ['source' => $source->id(), 'tab' => 'index']]);
    $this->assertSession()->pageTextContains('Picayune Office');
    $this->assertSession()->pageTextNotContains('Zebra index test');
    $this->assertSession()->elementNotExists('css', '.source-browser__tabs a[href*="tab=entries"]');
    $this->assertSession()->elementNotExists('css', '.source-browser__tabs a[href*="tab=transcription"]');
    $this->assertSession()->elementNotExists('css', '.source-browser__tabs a[href*="tab=pages"]');
    $this->assertSession()->elementNotExists('css', '.source-browser__index-term li');
    $this->drupalGet('/browse-source', ['query' => ['source' => $source->id(), 'tab' => 'index', 'letter' => 'Z']]);
    $this->assertSession()->pageTextContains('Zebra index test');
    $this->assertSession()->pageTextNotContains('Picayune Office');
    $this->drupalGet('/api/v1/source-browser/' . $source->id() . '/index/' . $z_subject->id());
    $this->assertSession()->statusCodeEquals(200);
    $term_data = json_decode($this->getSession()->getPage()->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('Zebra index test', $term_data['title']);
    $this->assertSame($issue->label(), $term_data['hits'][0]['title']);

    $source->set('field_display_hints', [])->save();
    $this->assertFalse($repository->isBrowserSource($source));
    $this->assertArrayNotHasKey($source->id(), $repository->getSources($source));
    $this->drupalGet('/browse-items/' . $source->id());
    $this->assertSession()->elementNotExists('css', '[data-source-browser]');
    $this->drupalGet('/api/v1/source-browser/' . $source->id());
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * Verifies nested page navigation, source/issue search, and cache contexts.
   */
  public function testNavigationSearchAndCache(): void {
    $source = $this->collection('Research source');
    $other = $this->collection('Other research source');
    $model = $this->browserTerm(['vid' => 'islandora_models', 'name' => 'Publication Issue']);
    $page_model = $this->browserTerm(['vid' => 'islandora_models', 'name' => 'Page']);
    $first = $this->createNode([
      'type' => 'islandora_object',
      'title' => 'First volume',
      'status' => 1,
      'field_model' => $model->id(),
      'field_member_of' => $source->id(),
      'field_edtf_date_issued' => '1850-06-12',
    ]);
    $second = $this->createNode([
      'type' => 'islandora_object',
      'title' => 'Second volume',
      'status' => 1,
      'field_model' => $model->id(),
      'field_member_of' => $source->id(),
      'field_edtf_date_issued' => '1851-06-12',
    ]);
    $article = $this->createNode([
      'type' => 'islandora_object',
      'title' => 'Diary entry',
      'status' => 1,
      'field_member_of' => $first->id(),
    ]);
    $page = $this->createNode([
      'type' => 'islandora_object',
      'title' => 'Unique needle page',
      'status' => 1,
      'field_model' => $page_model->id(),
      'field_member_of' => $article->id(),
      'field_part_detail' => ['type' => 'page', 'number' => '1'],
      'field_weight' => 1,
    ]);
    $next_page = $this->createNode([
      'type' => 'islandora_object',
      'title' => 'Second needle page',
      'status' => 1,
      'field_model' => $page_model->id(),
      'field_member_of' => $first->id(),
      'field_part_detail' => ['type' => 'page', 'number' => '2'],
      'field_weight' => 2,
    ]);
    $this->createNode([
      'type' => 'islandora_object',
      'title' => 'Outside needle page',
      'status' => 1,
      'field_model' => $page_model->id(),
      'field_member_of' => $other->id(),
    ]);
    $config = \Drupal::configFactory()->getEditable('lehigh_islandora.journal_browser');
    $original = $config->getRawData();
    $config->set('search_index', 'missing_index_for_browser_test')->set('page_size', 1)->save();
    try {
      $this->drupalGet('/api/v1/source-browser/' . $source->id(), [
        'query' => [
          'issue' => $first->id(),
          'page' => $page->id(),
        ],
      ]);
      $data = json_decode($this->getSession()->getPage()->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
      $this->assertSame((int) $next_page->id(), $data['selected_issue']['next_page']['nid']);
      $this->assertNull($data['selected_issue']['previous_page']);
      $this->assertSame((int) $second->id(), $data['selected_issue']['next_issue']['nid']);
      $this->assertSame((int) $article->id(), $data['selected_issue']['entries'][0]['nid']);
      $this->assertArrayNotHasKey('transcription', $data['tabs']);
      $this->assertStringContainsString('page=' . $page->id(), $data['selected_issue']['pages'][0]['transcription_url']);
      $this->assertStringContainsString('page=' . $page->id(), $data['selected_issue']['entries'][0]['browser_url']);
      $this->drupalGet('/api/v1/source-browser/' . $source->id() . '/search', ['query' => ['q' => 'needle']]);
      $data = json_decode($this->getSession()->getPage()->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
      $this->assertCount(1, $data['results']);
      $this->assertNotEmpty($data['next_url']);
      $this->assertStringNotContainsString('Outside', $data['results'][0]['title']);
      $this->drupalGet('/api/v1/source-browser/' . $source->id() . '/search', [
        'query' => [
          'q' => 'needle',
          'issue' => $second->id(),
          'scope' => 'issue',
        ],
      ]);
      $data = json_decode($this->getSession()->getPage()->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
      $this->assertSame([], $data['results']);
      $stack = \Drupal::service('request_stack');
      $request = Request::create('/browse-source?issue=' . $first->id());
      $request->setSession(new Session(new MockArraySessionStorage()));
      $stack->push($request);
      try {
        $browser = \Drupal::service('lehigh_islandora.journal_browser.builder')->build($source);
        $cache = $browser['#cacheability'];
        $this->assertGreaterThan(0, $cache->getCacheMaxAge());
        $this->assertContains('on-campus', $cache->getCacheContexts());
        $this->assertContains('user', $cache->getCacheContexts());
        $this->assertContains('url.query_args', $cache->getCacheContexts());
        $this->assertContains('node:' . $page->id(), $cache->getCacheTags());
      }
      finally {
        $stack->pop();
      }
    }
    finally {
      $config->setData($original)->save();
    }
  }

  /**
   * Creates a collection with the metadata switch enabled.
   */
  private function collection(string $title) {
    $model = $this->browserTerm([
      'vid' => 'islandora_models',
      'name' => 'Collection',
      'field_external_uri' => ['uri' => 'http://purl.org/dc/dcmitype/Collection'],
    ]);
    $hint = $this->browserTerm(['vid' => 'islandora_display', 'name' => 'Journal Browser']);
    return $this->createNode([
      'type' => 'islandora_object',
      'title' => $title,
      'status' => 1,
      'field_model' => $model->id(),
      'field_display_hints' => $hint->id(),
    ]);
  }

  /**
   * Checks import idempotency, ranges, private transcripts, and PDF ordering.
   */
  public function testImportsDownloadsAndRestrictions(): void {
    $source = $this->collection('Gunn import test');
    $model = $this->browserTerm(['vid' => 'islandora_models', 'name' => 'Paged Content']);
    $page_model = $this->browserTerm(['vid' => 'islandora_models', 'name' => 'Page']);
    $volume = $this->createNode([
      'type' => 'islandora_object',
      'title' => 'Gunn Volume 1',
      'status' => 1,
      'field_model' => $model->id(),
      'field_member_of' => $source->id(),
      'field_pid' => 'digitalcollections:gunn_183',
    ]);
    $page = $this->createNode([
      'type' => 'islandora_object',
      'title' => 'Gunn page 116',
      'status' => 1,
      'field_model' => $page_model->id(),
      'field_member_of' => $volume->id(),
      'field_pid' => 'digitalcollections:gunn_115',
      'field_part_detail' => ['type' => 'page', 'number' => '116'],
    ]);
    $directory = sys_get_temp_dir() . '/source-browser-test-' . $source->id();
    mkdir($directory);
    file_put_contents($directory . '/gunn_subjects.csv', "id,subject\n2,Abbots Jacob\n");
    file_put_contents($directory . '/gunn_volumes.csv', "id,ptr\n1,183\n");
    file_put_contents($directory . '/gunn_index.csv', "id,subj,spage,epage,ptr,vol\n2,2,116,118,115,1\n");
    file_put_contents($directory . '/115.txt', "A café diary transcription <with literal brackets>.\n");
    file_put_contents($directory . '/dates.csv', "collection,target_pid,date_issued,volume,issue\ngunn,digitalcollections:gunn_183,1850-06-12,1,01\n");
    $importer = \Drupal::service('lehigh_islandora.source_browser.importer');
    try {
      $preflight = $importer->importIndex($source, $directory);
      $this->assertSame(1, $preflight['matched']);
      $this->assertSame(0, $preflight['written']);
      $this->assertSame(1, $importer->importIndex($source, $directory, TRUE)['written']);
      $this->assertSame(0, $importer->importIndex($source, $directory, TRUE)['written']);
      foreach (\Drupal::entityTypeManager()->getStorage('lehigh_source_index_entry')->loadByProperties(['source' => $source->id()]) as $entry) {
        $this->markEntityForCleanup($entry);
        $this->markEntityForCleanup($entry->get('subject')->entity);
      }
      $this->drupalGet('/api/v1/source-browser/' . $source->id() . '/index');
      $index = json_decode($this->getSession()->getPage()->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
      $this->assertSame([], $index['A'][0]['hits']);
      $tid = $index['A'][0]['tid'];
      $this->drupalGet('/api/v1/source-browser/' . $source->id() . '/index/' . $tid);
      $this->assertSession()->statusCodeEquals(200);
      $term = json_decode($this->getSession()->getPage()->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
      $this->assertSame('Vol. 1, pp. 116–118', $term['hits'][0]['title']);
      $this->assertStringContainsString('page=' . $page->id(), $term['hits'][0]['browser_url']);
      $this->assertSame(1, $importer->importDates($source, $directory . '/dates.csv', 'gunn', TRUE)['written']);
      $this->assertSame(0, $importer->importDates($source, $directory . '/dates.csv', 'gunn', TRUE)['written']);
      $this->assertSame(1, $importer->importTranscriptions($source, $directory, TRUE)['written']);
      $this->assertSame(0, $importer->importTranscriptions($source, $directory, TRUE)['written']);
      $csv = $directory . '/gunn.csv';
      file_put_contents($csv, "node_id,field_pid,Transcript URL\n"
        . $source->id() . ",digitalcollections:gunn,\n"
        . $volume->id() . ",digitalcollections:gunn_183,[volume-level]\n"
        . $page->id() . ",digitalcollections:gunn_115,https://pfaffs-web.lib.lehigh.edu/gunn_transcripts/115.txt\n");
      $http = $this->createMock(ClientInterface::class);
      $http->expects($this->exactly(2))->method('request')->with(
        'GET', $this->callback(static fn(string $url): bool => in_array($url, [
          'https://pfaffs-web.lib.lehigh.edu/gunn_transcripts/115.txt',
          'https://pfaffs-web.lib.lehigh.edu/gunn_transcripts/0.txt',
        ], TRUE)),
        $this->callback(static fn(array $options): bool => $options['allow_redirects'] === FALSE),
      )->willReturnCallback(static fn() => new Response(200, [], file_get_contents($directory . '/115.txt')));
      $csv_importer = new SourceBrowserImporter(
        \Drupal::entityTypeManager(),
        \Drupal::service('lehigh_islandora.journal_browser.repository'),
        \Drupal::database(), \Drupal::service('file.repository'),
        \Drupal::service('file_system'), $http,
      );
      $this->assertSame(2, $csv_importer->importTranscriptCsv($source, $csv)['skipped']);
      $old_media = \Drupal::entityTypeManager()->getStorage('media')->loadByProperties([
        'bundle' => 'extracted_text',
        'field_media_of' => $page->id(),
      ]);
      foreach ($old_media as $media) {
        $this->markEntityForCleanup($media->get('field_media_file')->entity);
      }
      $report = $csv_importer->importTranscriptCsv($source, $csv, TRUE);
      $this->assertSame(1, $report['downloaded']);
      $this->assertSame(1, $report['written']);
      $this->assertSame(0, $report['invalid']);
      $new_media = \Drupal::entityTypeManager()->getStorage('media')->loadByProperties([
        'bundle' => 'extracted_text',
        'field_media_of' => $page->id(),
      ]);
      $this->assertCount(1, $new_media);
      $this->assertNotSame(array_keys($old_media), array_keys($new_media));
      $this->assertSame('private://derivatives/ocr/' . $page->id() . '.txt', reset($new_media)->get('field_media_file')->entity->getFileUri());
      $report = $csv_importer->importTranscriptCsv($source, $csv, TRUE);
      $this->assertSame(1, $report['cached']);
      $this->assertSame(1, $report['unchanged']);
      $this->assertSame(0, $report['written']);
      $this->assertSame(array_keys($new_media), array_keys(\Drupal::entityTypeManager()->getStorage('media')->loadByProperties([
        'bundle' => 'extracted_text',
        'field_media_of' => $page->id(),
      ])));

      $zero_page = $this->createNode([
        'type' => 'islandora_object',
        'title' => 'Gunn legacy pointer zero',
        'status' => 1,
        'field_model' => $page_model->id(),
        'field_member_of' => $volume->id(),
        'field_pid' => 'digitalcollections:gunn_0',
      ]);
      $zero_csv = $directory . '/zero.csv';
      file_put_contents($zero_csv, "node_id,field_pid,Transcript URL\n"
        . $zero_page->id() . ",digitalcollections:gunn_0,https://pfaffs-web.lib.lehigh.edu/gunn_transcripts/0.txt\n");
      $this->assertSame(0, $csv_importer->importTranscriptCsv($source, $zero_csv)['invalid']);
      $zero_report = $csv_importer->importTranscriptCsv($source, $zero_csv, TRUE);
      $this->assertSame(0, $zero_report['invalid']);
      $this->assertSame(1, $zero_report['written']);
      foreach (\Drupal::entityTypeManager()->getStorage('media')->loadByProperties(['field_media_of' => $zero_page->id()]) as $zero_media) {
        $this->markEntityForCleanup($zero_media);
        $this->markEntityForCleanup($zero_media->get('field_media_file')->entity);
      }

      $cache_uri = 'private://source-browser/downloads/gunn/115.txt';
      file_put_contents($cache_uri, '<!doctype html><html>Error</html>');
      $report = $csv_importer->importTranscriptCsv($source, $csv, TRUE);
      $this->assertSame(1, $report['invalid']);
      $this->assertSame(0, $report['written']);
      $this->assertSame(array_keys($new_media), array_keys(\Drupal::entityTypeManager()->getStorage('media')->loadByProperties([
        'bundle' => 'extracted_text',
        'field_media_of' => $page->id(),
      ])));
      file_put_contents($cache_uri, file_get_contents($directory . '/115.txt'));
      file_put_contents($csv, str_replace('digitalcollections:gunn_115', 'digitalcollections:gunn_999', file_get_contents($csv)));
      $this->assertSame(1, $csv_importer->importTranscriptCsv($source, $csv)['invalid']);

      foreach (\Drupal::entityTypeManager()->getStorage('media')->loadByProperties(['field_media_of' => $page->id()]) as $media) {
        $this->markEntityForCleanup($media);
        $this->markEntityForCleanup($media->get('field_media_file')->entity);
      }
      $this->drupalGet('/browse-source', [
        'query' => [
          'source' => $source->id(),
          'issue' => $volume->id(),
          'page' => $page->id(),
          'tab' => 'transcription',
        ],
      ]);
      $this->assertSession()->pageTextContains('A café diary transcription <with literal brackets>.');
      $this->assertSession()->pageTextContains('Page-level description');

      foreach (['OriginalFile', 'ServiceFile'] as $kind) {
        $use = $this->browserTerm([
          'vid' => 'islandora_media_use',
          'name' => $kind,
          'field_external_uri' => ['uri' => 'http://pcdm.org/use#' . $kind],
        ]);
        $file = \Drupal::service('file.repository')->writeData('%PDF-1.4 test', 'private://source-browser-' . $source->id() . '-' . $kind . '.pdf');
        $this->markEntityForCleanup($file);
        $this->createMedia([
          'bundle' => 'document',
          'name' => $kind,
          'status' => 1,
          'field_media_of' => $volume->id(),
          'field_media_use' => $use->id(),
          'field_media_document' => $file->id(),
        ]);
      }
      $downloads = \Drupal::service('lehigh_islandora.download_resolver')->resolve($volume);
      $this->assertSame('Download original PDF', $downloads[0]['label']);
      $this->assertSame('Download generated page PDF', $downloads[1]['label']);
      $volume->set('field_edtf_date_embargo', '2999-12-31')->save();
      $this->assertSame([], \Drupal::service('lehigh_islandora.download_resolver')->resolve($volume));
      $this->assertSame([], \Drupal::service('lehigh_islandora.journal_browser.repository')->getTranscriptions([$page]));
      $this->drupalGet('/browse-source', [
        'query' => [
          'source' => $source->id(),
          'issue' => $volume->id(),
          'page' => $page->id(),
          'tab' => 'transcription',
        ],
      ]);
      $this->assertSession()->pageTextNotContains('A café diary transcription');

      file_put_contents($directory . '/gunn_index.csv', "id,subj,spage,epage,ptr,vol\n3,2,116,118,999999,1\n");
      $this->assertSame(1, $importer->importIndex($source, $directory)['invalid']);
      $this->expectException(\InvalidArgumentException::class);
      $importer->importIndex($source, $directory, TRUE);
    }
    finally {
      if (is_file('private://source-browser/downloads/gunn/0.txt')) {
        unlink('private://source-browser/downloads/gunn/0.txt');
      }
      if (is_file('private://source-browser/downloads/gunn/115.txt')) {
        unlink('private://source-browser/downloads/gunn/115.txt');
      }
      foreach (glob($directory . '/*') as $path) {
        unlink($path);
      }
      rmdir($directory);
    }
  }

}
