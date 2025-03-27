<?php

namespace Drupal\views_attachment_tabs\Plugin\views\display_extender;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\display_extender\DisplayExtenderPluginBase;

/**
 * Attachment tab display extender plugin.
 *
 * @ingroup views_attachment_tabs_plugins
 *
 * @ViewsDisplayExtender(
 *   id = "attachment_tabs",
 *   title = @Translation("attachment tab display extender"),
 *   help = @Translation("Allows users to tab between attached views."),
 *   no_ui = FALSE
 * )
 */
class AttachmentTabs extends DisplayExtenderPluginBase {

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['attachment_tabs_enabled'] = ['default' => FALSE];
    return $options;
  }

  /**
   * Provide the default summary for options and category in the views UI.
   */
  public function optionsSummary(&$categories, &$options) {
    $options['attachment_tabs'] = [
      'category' => 'other',
      'title' => $this->t('Use attachment tabs'),
      'value' => $this->options['attachment_tabs_enabled'] ? $this->t('Yes') : $this->t('No'),
      'desc' => $this->t('Create a user interface that allows users to switch between attachments.'),
    ];
  }

  /**
   * Provide a form to edit options for this plugin.
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    if ($form_state->get('section') != 'attachment_tabs') {
      return;
    }

    $form['#title'] .= $this->t('Attachment tabs');

    $form['attachment_tabs_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use attachment tabs'),
      '#default_value' => $this->options['attachment_tabs_enabled'],
      '#description' => $this->t('Create a set of tabs that will allow users to switch between a view and its attached views.'),
    ];

    $form['attachment_tabs_default_container_class'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default container class'),
      '#default_value' => $this->options['attachment_tabs_default_container_class'] ?: "rows",
      '#description' => $this->t('DEPRECATED. NO LONGER NEEDED. Set default attachment class in Attachment Parent display format settings. A class to identify the main view’s row container. This class must be on the view row container element of the main/default view for this functionality to work. The class must be assigned in the template or already present.<br />This is a temporary measure until a better method of identifying the default container is found.'),
    ];

  }

  /**
   * Validate the options form.
   */
  public function validateOptionsForm(&$form, FormStateInterface $form_state) {}

  /**
   * Handle any special handling on the validate form.
   */
  public function submitOptionsForm(&$form, FormStateInterface $form_state) {
    if ($form_state->get('section') != 'attachment_tabs') {
      return;
    }

    /** @var array $form_state_values */
    $form_state_values = $form_state->cleanValues()->getValues();
    foreach ($form_state_values as $option => $value) {
      $this->options[$option] = $value;
    }
  }

  /**
   * Set up any variables on the view prior to execution.
   */
  public function preExecute() {}

  /**
   * Inject anything into the query that the attachment_tabs handler needs.
   */
  public function query() {}

  /**
   * Which sections are defaultable and what items each section contains.
   */
  public function defaultableSections(&$sections, $section = NULL) {}

}
