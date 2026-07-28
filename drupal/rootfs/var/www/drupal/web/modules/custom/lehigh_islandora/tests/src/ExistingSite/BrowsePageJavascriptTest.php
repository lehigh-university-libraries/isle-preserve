<?php

declare(strict_types=1);

namespace Drupal\Tests\lehigh_islandora\ExistingSite;

use Drupal\user\Entity\User;
use weitzman\DrupalTestTraits\ExistingSiteSelenium2DriverTestBase;

/**
 * Tests the client-side Browse presentation switch.
 */
class BrowsePageJavascriptTest extends ExistingSiteSelenium2DriverTestBase {

  /**
   * Ensure card and list presentations share one result DOM.
   */
  public function testBrowseDisplayToggle() {
    $admin = User::load(1);
    $this->assertNotNull($admin);
    $this->drupalLogin($admin);
    $this->drupalGet('/browse');

    $web_assert = $this->assertSession();
    $card_button = $web_assert->waitForElementVisible('css', '.browse-view-toggle__button--card');
    $list_button = $web_assert->waitForElementVisible('css', '.browse-view-toggle__button--list');
    $this->assertNotNull($card_button);
    $this->assertNotNull($list_button);

    $page = $this->getCurrentPage();
    $browse = $page->find('css', '.browse-results');
    $pager = $page->find('css', '.browse-results > nav.pager[aria-labelledby="pagination-heading"]');
    $this->assertNotNull($browse);
    $this->assertNotNull($pager);
    $this->assertTrue($pager->isVisible());
    $web_assert->elementsCount('css', '.browse-results > .themed-grid.rows', 1);
    $web_assert->elementExists('css', '.browse-results .views-data-export-feed');

    $this->assertSame('card', $browse->getAttribute('data-browse-view'));
    $this->assertSame('true', $card_button->getAttribute('aria-pressed'));
    $this->assertSame('false', $list_button->getAttribute('aria-pressed'));

    $list_button->click();
    $this->assertSame('list', $browse->getAttribute('data-browse-view'));
    $this->assertSame('false', $card_button->getAttribute('aria-pressed'));
    $this->assertSame('true', $list_button->getAttribute('aria-pressed'));

    $card_button->click();
    $this->assertSame('card', $browse->getAttribute('data-browse-view'));
    $this->assertSame('true', $card_button->getAttribute('aria-pressed'));
    $this->assertSame('false', $list_button->getAttribute('aria-pressed'));
  }

}
