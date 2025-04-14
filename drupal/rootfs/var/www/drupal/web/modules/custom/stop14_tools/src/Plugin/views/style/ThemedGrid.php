<?php

namespace Drupal\stop14_tools\Plugin\views\style;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\style\StylePluginBase;

/**
 * Style plugin to render each item in a Themed Grid Layout.
 *
 * @ingroup views_style_plugins
 *
 * @ViewsStyle(
 *   id = "themed_grid",
 *   title = @Translation("Themed Grid"),
 *   help = @Translation("Create a theme-managed grid to maintain stylistic consistency. Requires a supporting theme."),
 *   theme = "views_view_themed_grid",
 *   display_types = {"normal"}
 * )
 */
class ThemedGrid extends StylePluginBase {

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

    // Get default options from the Themed Grid.
    $default_options = \Drupal::service('stop14_tools.themed_grid.service')
      ->getThemedGridDefaultOptions();

    // Set default values for the Themed Grid.
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

    // Add viewer options to views form.
    $form['themed_grid'] = [
      '#type' => 'details',
      '#title' => $this->t('Themed Grid'),
      '#open' => TRUE,
    ];

    if (\Drupal::service('stop14_tools.themed_grid.service')->flightCheck()) {
      $form += \Drupal::service('stop14_tools.themed_grid.service')
        ->buildSettingsForm($this->options);

      // Display each option within the viewer fieldset.
      foreach (\Drupal::service('stop14_tools.themed_grid.service')
        ->getThemedGridDefaultOptions() as $option => $default_value) {
        $form[$option]['#fieldset'] = 'themed_grid';
      }
    }
    else {
      // Disable Themed Grid as plugin is not installed.
      $form['themed_grid_disabled'] = [
        '#markup' => $this->t('Drupal is missing one or more supporting libraries. The Themed Grid has been disabled.'),
        '#fieldset' => 'themed_grid',
      ];
    }
  }

}
