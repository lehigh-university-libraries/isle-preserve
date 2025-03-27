<?php

namespace Drupal\lehigh_site_support\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\Component\Utility\UrlHelper;

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
    $suggestions = [];

    $vid = 'suggested_terms';
    $suggestion_path = '/browse?search_api_fulltext=';

    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree($vid);
    $url_helper = new UrlHelper();
    $config = \Drupal::config('lehigh_site_support.settings');
    $about_text_value = $config->get('about_text');

    foreach ($terms as $term) {
      $suggestions[] = [
        'label' => $term->name,
        'href' => $suggestion_path . urlencode(trim($term->name))
      ];
    }

    $build['content'] = [
      '#theme' => 'lehigh_site_cta_block',
      '#search_form' => \Drupal::formBuilder()->getForm('Drupal\lehigh_site_support\Form\SiteCTAForm'),
      '#browse_form' => \Drupal::formBuilder()->getForm('Drupal\lehigh_site_support\Form\BrowseDestinationForm'),
      '#suggestions' => $suggestions,
      '#about_text' => check_markup($about_text_value['value'],$about_text_value['format'])
    ];
    return $build;
  }

}
