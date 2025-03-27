<?php

namespace Drupal\lehigh_site_support\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Configure lehigh Digital Collections settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'lehigh_site_support_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['lehigh_site_support.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('lehigh_site_support.settings');

    $form['lehigh_site_support_settings'] = [
      '#type' => 'vertical_tabs',
      '#title' => t('Lehigh Digital Collections Site Settings'),
    ];

    $form['general'] = [
      '#type' => 'details',
      '#title' => t('General Settings'),
      '#group' => 'lehigh_site_support_settings',
    ];

    $form['rights'] = [
      '#type' => 'details',
      '#title' => t('Rights and reproductions'),
      '#group' => 'lehigh_site_support_settings',
    ];

    $form['site_copy'] = [
      '#type' => 'fieldset',
      '#title' => t('Site Copy'),
      '#group' => 'general',
    ];

    // Copy settings information defined in lehigh_site_support.module
    // The module provides tokens for all formatted text items.
    foreach (lehigh_site_support_get_site_copy_keys() as $key => $values) {
      $group = array_key_exists('group', $values) ? $values['group'] : 'site_copy';
      $form[$group][$key] = [
        '#type' => 'text_format',
        '#title' => $values['title'],
        '#description' => $values['description'],
        '#rows' => 15,
        '#format' => !empty($config->get($key)['format']) ?
        $config->get($key)['format'] :
        NULL,
        '#default_value' => !empty($config->get($key)['value']) ?
        $config->get($key)['value'] :
        $values['default_value'],
      ];
    }

    foreach (lehigh_site_support_get_tokenized_text_field_keys() as $key => $values) {
      $group = array_key_exists('group', $values) ? $values['group'] : 'site_copy';
      $form[$group][$key] = [
        '#type' => 'textfield',
        '#title' => $values['title'],
        '#description' => $values['description'],
        '#default_value' => !empty($config->get($key)) ?
        $config->get($key) :
        $values['default_value'],
      ];
    }

    $form['collections'] = [
      '#type' => 'fieldset',
      '#title' => t('Collections settings'),
      '#group' => 'general',
    ];

    $vlist = [];
    // To get names:
    foreach (Vocabulary::loadMultiple() as $voc) {
      $vlist[$voc->getOriginalId()] = $voc->label();
    }

    $form['collections']['collections_vocabulary'] = [
      '#type' => 'select',
      '#options' => $vlist,
      '#default_value' => $config->get('collections_vocabulary'),
      '#group' => 'general',
      '#title' => 'Top-level collections vocabulary',
      '#description' => 'Choose the vocabulary that represents the active top-level collections. Used by the site to direct collection search forms.',
    ];

    $form['collections']['collection_searchfield_placeholder'] = [
      '#type' => 'textfield',
      '#default_value' => $config->get('collection_searchfield_placeholder') ?? 'Search for keywords, names, and locations',
      '#title' => 'Collection search form placeholder',
      '#description' => 'Placeholder text to present to a user on collection search forms',
    ];

    $form['collections']['collections_route_path'] = [
      '#type' => 'textfield',
      '#default_value' => $config->get('collections_route_path') ?? 'collections',
      '#title' => 'Collections route path',
      '#description' => 'Path slug that represents base path for all collections.',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('lehigh_site_support.settings');

    // Site copy.
    foreach (array_keys(array_merge(lehigh_site_support_get_site_copy_keys(), lehigh_site_support_get_tokenized_text_field_keys())) as $key) {
      $config
        ->set($key, $form_state->getValue($key))
        ->save();
    }

    // Collections vocabulary.
    $config
      ->set('collections_vocabulary', $form_state->getValue('collections_vocabulary'))
      ->save();

    // Searchfield placeholder.
    $config
      ->set('collection_searchfield_placeholder', $form_state->getValue('collection_searchfield_placeholder'))
      ->save();

    // Collections route path.
    $config
      ->set('collections_route_path', urlencode(HTML::escape(strtolower($form_state->getValue('collections_route_path')))))
      ->save();

    // Searchfield default collection.
    $config
      ->set('default_collection', urlencode(HTML::escape(strtolower($form_state->getValue('default_collection')))))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
