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
  }

}
