<?php

namespace Drupal\views_attachment_tabs\Plugin\views\style;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\style\StylePluginBase;

/**
 * Style plugin to support the Views Attachment Tabs.
 *
 * @ingroup views_style_plugins
 *
 * @ViewsStyle(
 *   id = "attachment_parent",
 *   title = @Translation("Attachment Parent"),
 *   help = @Translation("Creates a master view to enable the attachment tab switcher UI."),
 *   theme = "views_view_attachment_parent",
 *   display_types = {"normal"}
 * )
 */
class AttachmentParent extends StylePluginBase {

  /**
   * Does the style plugin allows to use style plugins.
   *
   * @var bool
   */
  protected $usesRowPlugin = TRUE;

  /**
   * Does the style plugin support custom css class for the rows.
   *
   * @var bool
   */
  protected $usesRowClass = TRUE;

  /**
   * Does the style plugin support grouping of rows.
   *
   * @var bool
   */
  protected $usesGrouping = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();

    // Get default options from the Attachment Parent.
    $default_options = \Drupal::service('views_attachment_tabs.attachment_parent.service')
      ->getAttachmentParentDefaultOptions($this->view);

    // Set default values for the Attachment Parent.
    foreach ($default_options as $option => $default_value) {
      $options[$option] = [
        'default' => $default_value,
      ];
      if (is_bool($default_value)) {
        $options[$option]['bool'] = TRUE;
      }
    }

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    if (\Drupal::service('views_attachment_tabs.attachment_parent.service')->flightCheck()) {
      $form += \Drupal::service('views_attachment_tabs.attachment_parent.service')
        ->buildSettingsForm($this->options, $this->view);
    }
    else {
      // Disable Attachment Parent as plugin is not installed.
      $form['attachment_parent_disable'] = [
        '#markup' => $this->t('Drupal is missing one or more supporting libraries. The Attachment Parent has been disabled.'),
      ];
    }
  }

}
