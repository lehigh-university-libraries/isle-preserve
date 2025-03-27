<?php

namespace Drupal\lehigh_site_support\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Component\Utility\UrlHelper;
//use Drupal\Core\Extension\ExtensionPathResolver;
/**
 * Provides a simple select form
 */
class BrowseDestinationForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'lehigh_site_support_browse_designation_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $lehigh_config = \Drupal::config('lehigh_site_support.settings');
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

    $form['destination'] = [
      '#type' => 'select',
      '#options' => [
        '' => 'Browse',
        'collections' => 'Browse collections',
        'browse' => 'Browse items'
      ],
      '#default_value' => $this->getRequest()->query->get('browser') ?? null,
      '#ajax' => [
        'callback' => '::autoSubmit',
        'event' => 'change',
        'progress' => [
          'message' => '' // Hide progress spinner as per https://drupal.stackexchange.com/questions/11032/remove-the-please-wait-text-on-ajax-call
        ]
      ],
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

  }

  /**
   * {@inheritdoc}
   */

  public function submitForm(array &$form, FormStateInterface $form_state) {
    //$term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($form_state->getValue('collection_tid'));
    //$slug = $term->get('field_slug')->value;
    $uri = 'internal:/' . $form_state->getValue('destination');
    $form_state->setRedirectUrl(Url::fromUri($uri));
  }

  public function autoSubmit(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $url = \Drupal::request()->getSchemeAndHttpHost() . '/' . $form_state->getValue('destination');
    $response->addCommand(new RedirectCommand($url));
    return $response;

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
