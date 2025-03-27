<?php

namespace Drupal\lehigh_site_support\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Component\Utility\UrlHelper;
//use Drupal\Core\Extension\ExtensionPathResolver;
/**
 * Provides the CTA form for the digital library homepage
 */
class SiteCTAForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'lehigh_site_support_site_cta_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $lehigh_config = \Drupal::config('lehigh_site_support.settings');
    $theme = \Drupal::theme()->getActiveTheme();
    $theme_path = $theme->getPath();
    /*
     *
     * $vid = $lehigh_config->get('collections_vocabulary');

    if (!$vid) {
      \Drupal::messenger()->addWarning('Collection vocabulary must be set on site settings page before the collection search bar will work.');
      return $form;
    }

    $terms =\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree($vid);

    $collections = [];
    foreach ($terms as $term) {
      $collections[$term->tid] = $term->name;
    }

    asort($collections); */

    // GET forms are not processed through the default validation and submit
    // handlers. Many thanks to https://drupal.stackexchange.com/questions/291752/how-can-i-create-a-get-form-with-the-form-api
    // for the solution.

    // GET forms must not be cached, so that the page output responds without
    // caching.

    $form['#cache'] = [
      'max-age' => 0,
    ];

    // The after_build removes elements from GET parameters. See
    // TestForm::afterBuild().
   // $form['#after_build'] = ['::afterBuild'];


    $form['search_api_fulltext'] = [
      '#type' => 'textfield',
      '#attributes' => [
        'placeholder' => $lehigh_config->get('collection_searchfield_placeholder') ?? "Search the digital library",
      ],
      '#default_value' => $this->getRequest()->query->get('search_api_fulltext') ?? ''
    ];

    $form['submit'] = [
      '#type' => 'image_button',
      '#value' => $this->t('Search'),
      '#limit_validation_errors' => FALSE,
      '#executes_submit_callback' => TRUE,
      '#return_value' => TRUE,
      '#attributes' => ['alt' => 'Search'],
      '#has_garbage_value' => TRUE,
      '#src' => $theme_path . '/assets/img/svg/icons/search-grey.svg'
    ];

    $form['#attributes']['class'][] = 'site-cta-form';
    $form['#attached'] =  [
      'library' => ['lehigh_site_support/site_cta']
    ];

    return $form;
  }
/*
 * Remove the form_token, form_build_id and form_id from the GET parameters.
 */
  public function afterBuild(array $element, FormStateInterface $form_state) {
    unset($element['form_token']);
    unset($element['form_build_id']);
    unset($element['form_id']);

    return $element;

  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (empty($form_state->getValue('search_api_fulltext'))) {
      $form_state->setErrorByName('search_api_fulltext', $this->t('Please provide a search term.'));
    }

    /*if (!$form_state->getValue('collection_tid')) {
      $form_state->setErrorByName('collection_tid', $this->t('Please select a collection to search.'));
    }*/

  }

  /**
   * {@inheritdoc}
   */

  public function submitForm(array &$form, FormStateInterface $form_state) {
    //$term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($form_state->getValue('collection_tid'));
    $searchKeyword = $form_state->getValue('search_api_fulltext');
    //$slug = $term->get('field_slug')->value;
    $uri = 'internal:/browse/';
    if (!empty($searchKeyword)) {
      $uri .= '?search_api_fulltext=' . UrlHelper::encodePath(UrlHelper::stripDangerousProtocols($searchKeyword));
    }
    $form_state->setRedirectUrl(Url::fromUri($uri));
  }

  /**
   * Used to set the current collection. Collection tid will be
   * returned in this order:
   *   - A set collection_tid parameter, if set
   *   - A tid based on the current route
   *   - The default tid from the Vassar Settings Form
   *   - null
   *
   * @return array|mixed|string|null
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */

  /*
  protected function getCurrentCollection() {
    $current_path = \Drupal::service('path.current')->getPath();
    $path_array = explode('/',  \Drupal::service('path_alias.manager')->getAliasByPath($current_path));
    $lehigh_config = \Drupal::config('lehigh_site_support.settings');

    $set_parameter = $this->getRequest()->query->get('collection_tid');
    $arg_parameter = '';
    $default = $lehigh_config->get('default_collection');


    $cvid = $lehigh_config->get('collections_vocabulary');
    if ($cvid && count($path_array) > 2) {
      $collections = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree($cvid);

      foreach($collections as $term) {
        $cterm = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($term->tid);
        if ($cterm->get('field_slug')->value == $path_array[2]) {
          $arg_parameter = $term->tid;
        }
      }
    }

    if (!empty($set_parameter)) {
      return $set_parameter;
    } else if (!empty($arg_parameter)) {
      return $arg_parameter;
    } else if (!empty($default)) {
      return $default;
    } else {
      return null;
    }
  }
  */

}
