<?php

/**
 * @file
 * Attachment Parent service file.
 *
 */

namespace Drupal\views_attachment_tabs\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManager;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
/**
 * Wrapper methods for the Views Attachment Tabs API.
 *
 *
 * @ingroup views
 */
class AttachmentParentService {

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
   * The language manager service
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The config factory service
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs an AttachmentParent object.
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
   *   An associative array of default options for the Attachment Parent view
   *   display.
   */
  public function getAttachmentParentDefaultOptions(ViewExecutable $view = null): array {

    $options = [];
    $attachments = [];

    if ($view instanceof ViewExecutable) {

      // Copied code from $view->attachDisplays();
      // Attachments haven't been attached at this stage in the build order.
      // We can't call attachDisplays here without duplicating attachments.

      foreach($view->display_handler->getAttachedDisplays() as $id) {
        $display_handler = $view->displayHandlers->get($id);
        if ($display_handler->isEnabled() && $display_handler->access()) {
          $attachments[$id] = $display_handler->getOption('title');
        }
      }
    }

    $options['default_attachment_display_options'] = $attachments;

    return $options;
  }

  /**
   * Apply Attachment Parent to a container.
   *
   * @param array $form
   *   The form to which the JS will be attached.
   * @param string $container
   *   The CSS selector of the container element to apply the Attachment Parent
   *   to.
   * @param string $item_selector
   *   The CSS selector of the items within the container.
   * @param array $options
   *   An associative array of Attachment Parent options.
   * @param string[] $viewer_ids
   */
  public function applyAttachmentParentDisplay(&$form, $container, $item_selector, $options = [], $viewer_ids = ['attachment_parent_default']) {
    if (!empty($container)) {
      // For any options not specified, use default options.
      // In this case if options are not specified they should be empty.
      // $options += $this->getAttachmentParentDefaultOptions();

      if (!isset($item_selector)) {
        $item_selector = '';
      }

      // Setup  component.
      $attachment_parent = [
        'attachment_parent' => [
          $container => [
            'viewer_ids' => $viewer_ids,
          ],
        ],
      ];

      // Allow other modules and themes to alter the settings.
      $context = [
        'container' => $container,
        'item_selector' => $item_selector,
        'options' => $options,
      ];
      $this->moduleHandler->alter('attachment_parent_component', $attachment_parent, $context);
      $this->themeManager->alter('attachment_parent_component', $attachment_parent, $context);

      /* $form['#attached']['library'][] = 'views_attachment_tabs/views_attachment_tabs.attachment_parent';
      if (isset($form['#attached']['drupalSettings'])) {
        $form['#attached']['drupalSettings'] += $attachment_parent;
      }
      else {
        $form['#attached']['drupalSettings'] = $attachment_parent;
      }*/
    }
  }

  /**
   * Build the settings configuration form.
   *
   * @param array (optional)
   *   The default values for the form.
   *
   * @return array
   *   The form
   */
  public function buildSettingsForm($default_values, ViewExecutable $view = null) {
    // Load module default values if empty.
    if (empty($default_values)) {
      $default_values = [];
    }

    $form['default_attachment_display'] = [
      '#type' => 'select',
      '#title' => t('Default Attachment View'),
      '#description' => t("Select a view to be displayed in place of this view will be displayed in place of this one."),
      '#options' => $this->getAttachmentParentDefaultOptions($view)['default_attachment_display_options'],
      '#default_value' => array_key_exists('default_attachment_display',$default_values) ? $default_values['default_attachment_display'] : null,
    ];


    // Allow other modules and themes to alter the form.
    $this->moduleHandler->alter('attachment_parent_options_form', $form, $default_values);
    $this->themeManager->alter('attachment_parent_options_form', $form, $default_values);

    return $form;
  }

  /*
   * Check that prerequisites are installed.
   *
   * STUB FUNCTION
   */

  public function flightCheck(): bool {
    return TRUE;
  }

}
