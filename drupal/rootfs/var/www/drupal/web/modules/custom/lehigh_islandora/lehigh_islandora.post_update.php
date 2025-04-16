<?php

/**
 * @file
 * Post updates.
 */

/**
 * Enable lehigh_embargo module.
 */
function lehigh_islandora_post_update_enable_embargo() {
  \Drupal::service('module_installer')->install(['lehigh_embargo']);
}
