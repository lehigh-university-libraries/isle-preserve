<?php

namespace Drupal\Tests\lehigh_islandora\ExistingSite;

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
    $this->drupalGet('/browse');
    $web_assert->pageTextContains('Browse All Digital Items');
    $web_assert->elementExists('css', '.advanced-search-form-area');
    $web_assert->elementExists('css', '.islandora-search-results-toolbar');
    $web_assert->elementExists('css', '#block-lehigh-exposedformbrowsemain');
    $web_assert->elementExists('css', 'form.islandora-search-filters');
    $web_assert->elementNotExists('css', '.block-facets');
    $web_assert->fieldNotExists('recursive');
    $web_assert->pageTextNotContains('Items in this collection');
  }

  /**
   * Make sure /collections uses the integrated search interface.
   */
  public function testCollections() {
    $web_assert = $this->assertSession();
    $this->drupalGet('/collections');
    $web_assert->pageTextContains('Browse Digital Collections');
    $web_assert->elementExists('css', '.advanced-search-form-area');
    $web_assert->elementExists('css', '#block-lehigh-exposedformbrowsemain');
    $web_assert->elementExists('css', 'form.islandora-search-filters');
    $web_assert->elementNotExists('css', '.block-facets');
    $web_assert->fieldNotExists('recursive');
    $web_assert->pageTextNotContains('Items in this collection');
  }

  /**
   * Collection-like objects retain contextual and recursive search controls.
   */
  public function testContextualBrowseInterfaces(): void {
    foreach (['Collection', 'Compound Object'] as $model) {
      $node = $this->createNode([
        'title' => "$model search test",
        'type' => 'islandora_object',
        'uid' => 1,
        'field_model' => lehigh_islandora_get_tid_by_name(
          $model,
          'islandora_models',
        ),
      ]);
      $node->setPublished()->save();

      $this->drupalGet('/browse-items/' . $node->id());
      $web_assert = $this->assertSession();
      $web_assert->statusCodeEquals(200);
      $web_assert->elementExists('css', '.advanced-search-form-area');
      $web_assert->elementExists('css', '#block-lehigh-exposedformbrowsemain');
      $web_assert->elementExists('css', 'form.islandora-search-filters');
      $web_assert->fieldExists('recursive');
      $web_assert->pageTextContains('Items in this collection');
    }
  }

}
