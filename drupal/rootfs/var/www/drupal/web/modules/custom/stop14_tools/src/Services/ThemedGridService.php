<?php

namespace Drupal\stop14_tools\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManager;
use Drupal\Core\Theme\ThemeManagerInterface;

/**
 * Wrapper methods for Themed Grid API methods.
 *
 * @ingroup themed_grid
 */
class ThemedGridService {

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The theme manager service.
   *
   * @var \Drupal\Core\Theme\ThemeManagerInterface
   */
  protected $themeManager;

  /**
   * The language manager service.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a ThemedGridService object.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Theme\ThemeManagerInterface $theme_manager
   *   The theme manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ModuleHandlerInterface $module_handler, ThemeManagerInterface $theme_manager, LanguageManager $language_manager, ConfigFactoryInterface $config_factory) {
    $this->moduleHandler = $module_handler;
    $this->themeManager = $theme_manager;
    $this->languageManager = $language_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * Get default options.
   *
   * @return array
   *   An associative array of default options for Themed Grid.
   */
  public function getThemedGridDefaultOptions() {
    $options = [
      'sml' => 1,
      'med' => 2,
      'lrg' => 3,
      'xlrg' => 3,
    ];

    return $options;
  }

  /**
   * Apply Themed Grid to a container.
   *
   * @param array $form
   *   The form to which the JS will be attached.
   * @param string $container
   *   The CSS selector of the container element to apply Themed Grid to.
   * @param string $item_selector
   *   The CSS selector of the items within the container.
   * @param array $options
   *   An associative array of Themed Grid options.
   * @param string[] $viewer_ids
   *   Viewer ids.
   */
  public function applyThemedGridDisplay(&$form, $container, $item_selector, $options = [], $viewer_ids = ['themed_grid_default']) {
    if (!empty($container)) {
      // For any options not specified, use default options.
      $options += $this->getThemedGridDefaultOptions();
      if (!isset($item_selector)) {
        $item_selector = '';
      }

      // Setup  component.
      $themed_grid = [
        'themed_grid' => [
          $container => [
            'viewer_ids' => $viewer_ids,
            'sml' => (int) $options['sml'],
            'med' => (int) $options['med'],
            'lrg' => (int) $options['lrg'],
            'xlrg' => (int) $options['xlrg'],
          ],
        ],
      ];

      // Allow other modules and themes to alter the settings.
      $context = [
        'container' => $container,
        'item_selector' => $item_selector,
        'options' => $options,
      ];
      $this->moduleHandler->alter('themed_grid_component', $themed_grid, $context);
      $this->themeManager->alter('themed_grid_component', $themed_grid, $context);

      $form['#attached']['library'][] = 'themed_grid/stop14_tools.themed_grid';
      if (isset($form['#attached']['drupalSettings'])) {
        $form['#attached']['drupalSettings'] += $themed_grid;
      }
      else {
        $form['#attached']['drupalSettings'] = $themed_grid;
      }
    }
  }

  /**
   * Build the settings configuration form.
   *
   * @param array $default_values
   *   The default values for the form.
   *
   * @return array
   *   The form
   */
  public function buildSettingsForm($default_values = []) {
    // Load module default values if empty.
    if (empty($default_values)) {
      $default_values = $this->getThemedGridDefaultOptions();
    }

    $form['breakpoints'] = [
      '#type' => 'fieldset',
      '#title' => t('Breakpoint columns'),
    ];

    $form['breakpoints']['sml'] = [
      '#type' => 'textfield',
      '#title' => t('Small'),
      '#description' => t("Number of grid columns for handheld mobile devices ('sml' breakpoint)."),
      '#default_value' => $default_values['sml'],
      '#size' => 2,
      '#maxlength' => 2,
    ];

    $form['breakpoints']['med'] = [
      '#type' => 'textfield',
      '#title' => t('Medium'),
      '#description' => t("Number of grid columns for tablet and narrow-viewport devices ('med' breakpoint)."),
      '#default_value' => $default_values['med'],
      '#size' => 2,
      '#maxlength' => 2,
    ];

    $form['breakpoints']['lrg'] = [
      '#type' => 'textfield',
      '#title' => t('Large'),
      '#description' => t("Number of grid columns for desktop devices ('lrg' breakpoint)."),
      '#default_value' => $default_values['lrg'],
      '#size' => 2,
      '#maxlength' => 2,
    ];

    $form['breakpoints']['xlrg'] = [
      '#type' => 'textfield',
      '#title' => t('Extra-Large'),
      '#description' => t("Number of grid columns for wide viewports ('xlrg' breakpoint)."),
      '#default_value' => $default_values['xlrg'],
      '#size' => 2,
      '#maxlength' => 2,
    ];

    // Allow other modules and themes to alter the form.
    $this->moduleHandler->alter('themed_grid_options_form', $form, $default_values);
    $this->themeManager->alter('themed_grid_options_form', $form, $default_values);

    return $form;
  }

  /**
   * Check that prerequisites are installed.
   *
   * STUB FUNCTION.
   */
  public function flightCheck(): bool {
    return TRUE;
  }

}
