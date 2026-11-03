<?php

declare(strict_types=1);

namespace Drupal\Tests\lehigh_islandora\Unit;

use Drupal\file\FileInterface;
use Drupal\lehigh_islandora\Plugin\search_api\processor\OcrText;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests reading transcription files for the search index.
 *
 * @group lehigh_islandora
 */
final class OcrTextTest extends UnitTestCase {

  /**
   * Transcript content takes priority, with fallback to readable OCR.
   */
  public function testTranscriptPrecedenceAndFallback(): void {
    $node = $this->createMock(NodeInterface::class);
    $transcript = $this->createMock(FileInterface::class);
    $ocr = $this->createMock(FileInterface::class);
    $processor = $this->getMockBuilder(OcrText::class)->disableOriginalConstructor()
      ->onlyMethods(['getFiles', 'readFile'])->getMock();
    $processor->method('getFiles')->willReturnMap([
      [$node, TRUE, [$transcript]],
      [$node, FALSE, [$ocr]],
    ]);
    $processor->method('readFile')->willReturnMap([
      [$transcript, 'Reviewed transcript'],
      [$ocr, 'OCR'],
    ]);
    $select = new \ReflectionMethod(OcrText::class, 'getIndexContent');
    $this->assertSame([$transcript, 'Reviewed transcript'], $select->invoke($processor, $node));

    $fallback = $this->getMockBuilder(OcrText::class)->disableOriginalConstructor()
      ->onlyMethods(['getFiles', 'readFile'])->getMock();
    $fallback->method('getFiles')->willReturnMap([
      [$node, TRUE, [$transcript]],
      [$node, FALSE, [$ocr]],
    ]);
    $fallback->method('readFile')->willReturnMap([
      [$transcript, NULL],
      [$ocr, 'OCR'],
    ]);
    $this->assertSame([$ocr, 'OCR'], $select->invoke($fallback, $node));
  }

  /**
   * A PDF is converted to text, not indexed as binary data.
   */
  public function testPdfTranscription(): void {
    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn(dirname(__DIR__, 2) . '/assets/pdf/test.pdf');
    $file->method('getMimeType')->willReturn('application/pdf');
    $processor = (new \ReflectionClass(OcrText::class))->newInstanceWithoutConstructor();
    $read = new \ReflectionMethod(OcrText::class, 'readFile');
    $this->assertStringContainsString('123', $read->invoke($processor, $file));
  }

  /**
   * Text transcripts remain readable without conversion.
   */
  public function testTextTranscription(): void {
    $temporary = tempnam(sys_get_temp_dir(), 'transcript-');
    $path = $temporary . '.txt';
    rename($temporary, $path);
    file_put_contents($path, 'A searchable transcription');
    try {
      $file = $this->createMock(FileInterface::class);
      $file->method('getFileUri')->willReturn($path);
      $file->method('getMimeType')->willReturn('text/plain');
      $processor = (new \ReflectionClass(OcrText::class))->newInstanceWithoutConstructor();
      $read = new \ReflectionMethod(OcrText::class, 'readFile');
      $this->assertSame('A searchable transcription', $read->invoke($processor, $file));
    }
    finally {
      unlink($path);
    }
  }

}
