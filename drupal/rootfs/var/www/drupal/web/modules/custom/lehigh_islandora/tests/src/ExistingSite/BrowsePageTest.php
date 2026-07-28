<?php

declare(strict_types=1);

namespace Drupal\Tests\lehigh_islandora\ExistingSite;

use Drupal\views\Entity\View;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests around the browse page.
 */
class BrowsePageTest extends ExistingSiteBase {

  /**
   * Make sure /browse renders.
   */
  public function testBrowse() {
    $web_assert = $this->assertSession();
    $this->drupalGet('/browse?cache-warmer=1');
    $web_assert->pageTextContains('Browse All Digital Items');
    $web_assert->elementsCount('css', '.browse-results', 1);
    $web_assert->elementsCount('css', '.browse-results > .themed-grid.rows', 1);
    $web_assert->elementExists('css', '.browse-results[data-default-view="card"][data-browse-view="card"]');
    $web_assert->elementNotExists('css', '.view-attachment-tab');
    $web_assert->elementNotExists('css', '.views-attachment-tabs');
  }

  /**
   * Ensure Browse renders its rows without a Views attachment.
   */
  public function testBrowseUsesSingleResultDisplay() {
    $view = View::load('browse');
    $this->assertNotNull($view);

    $displays = $view->get('display');
    $this->assertArrayNotHasKey('card_view', $displays);
    $this->assertArrayHasKey('data_export', $displays);
    $this->assertSame('browse-items/%node', $displays['main']['display_options']['path']);
    $this->assertSame('themed_grid', $displays['main']['display_options']['style']['type']);
    $this->assertSame('fields', $displays['main']['display_options']['row']['type']);
    $this->assertSame([
      'sml' => '1',
      'med' => '2',
      'lrg' => '3',
      'xlrg' => '3',
    ], $displays['main']['display_options']['style']['options']['breakpoints']);

    foreach ($displays as $display) {
      $this->assertNotSame('attachment', $display['display_plugin']);
      $this->assertNotSame('attachment_parent', $display['display_options']['style']['type'] ?? NULL);
    }
  }

  /**
   * Ensure compound object children default to the list presentation.
   */
  public function testCompoundObjectBrowseDefaultsToList() {
    $node = $this->createNode([
      'title' => 'Compound Object Browse Default',
      'type' => 'islandora_object',
      'uid' => 1,
      'status' => 1,
      'field_model' => lehigh_islandora_get_tid_by_name('Compound Object', 'islandora_models'),
    ]);

    $this->drupalGet('/browse-items/' . $node->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '.browse-results[data-default-view="list"][data-browse-view="list"]');
  }

}
