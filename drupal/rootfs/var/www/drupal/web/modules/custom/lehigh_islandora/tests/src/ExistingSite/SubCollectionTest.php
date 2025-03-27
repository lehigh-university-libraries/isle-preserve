<?php

namespace Drupal\Tests\lehigh_islandora\ExistingSite;

use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests around Islandora sub collections.
 */
class SubCollectionTest extends ExistingSiteBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Cause tests to fail if an error is sent to Drupal logs.
    $this->failOnLoggedErrors();
  }

  /**
   * Make sure we can create and view sub collections.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Drupal\Core\Entity\EntityMalformedException
   * @throws \Behat\Mink\Exception\ExpectationException
   */
  public function testSubCollection() {
    $node = $this->createNode(
          [
            'title' => 'Very Important Sub Collection',
            'type' => 'islandora_object',
            'uid' => 1,
            'field_model' => lehigh_islandora_get_tid_by_name('Sub-Collection', 'islandora_models'),
          ]
      );
    $node->setPublished()->save();
    $this->assertEquals(1, $node->getOwnerId());

    $this->drupalGet($node->toUrl());
    $this->assertSession()->statusCodeEquals(200);
  }

}
