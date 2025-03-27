<?php

namespace Drupal\lehigh_site_support\Plugin\Block;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Block\BlockBase;

/**
 * Provides an example block.
 *
 * @Block(
 *   id = "lehigh_site_cta_block",
 *   admin_label = @Translation("Lehigh Site CTA Block"),
 *   category = @Translation("Lehigh")
 * )
 */
class SiteCTABlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = \Drupal::config('lehigh_site_support.settings');
    $about_text_value = $config->get('about_text');

    $build['content'] = [
      '#theme' => 'lehigh_site_cta_block',
      '#about_text' => Xss::filter($about_text_value['value']),
    ];

    $build['#attributes']['class'][] = 'col-sm-12 col-md-10';

    return $build;
  }

}
