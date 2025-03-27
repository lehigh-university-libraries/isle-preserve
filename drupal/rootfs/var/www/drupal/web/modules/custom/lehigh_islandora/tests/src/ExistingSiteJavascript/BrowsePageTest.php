<?php

namespace Drupal\Tests\lehigh_islandora\ExistingSiteJavascript;

use weitzman\DrupalTestTraits\ExistingSiteSelenium2DriverTestBase;

/**
 * Tests around the browse page.
 */
class BrowsePageTest extends ExistingSiteSelenium2DriverTestBase {

  /**
   * Make sure /browse renders.
   */
  public function testBrowse() {
    $web_assert = $this->assertSession();
    $this->drupalGet('/browse');
    $web_assert->pageTextContains('Browse All Digital Items');
  }

}
