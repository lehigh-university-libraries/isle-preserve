<?php

declare(strict_types=1);

namespace Drupal\lehigh_iiip\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\islandora_collection_tabs\Plugin\Field\FieldFormatter\CollectionTabsDefaultFormatter;
use Drupal\Core\Form\FormBuilderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

/**
 * Plugin implementation of the 'islandora_collection_tabs_default' formatter.
 *
 * @FieldFormatter(
 *   id = "lehigh_iiip_collection_formatter",
 *   label = @Translation("IIIP Formatter"),
 *   field_types = {"islandora_collection_tabs"},
 * )
 */
final class IIIPFormatter extends CollectionTabsDefaultFormatter implements ContainerFactoryPluginInterface {

  /**
   * The form builder service.
   *
   * @var \Drupal\Core\Form\FormBuilder
   */
  protected $formBuilder;

  public function __construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings, FormBuilderInterface $form_builder) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
    $this->formBuilder = $form_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('form_builder')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $element = parent::viewElements($items, $langcode);
    $content = &$element[0]['content'];

    foreach ($items as $delta => $item) {
      $label = strtolower($item->label);
      $label = trim($label);
      if ($label !== 'contribute to this collection') {
        continue;
      }

      $id = 'tab-' . $delta;
      if (\Drupal::currentUser()->isAnonymous()) {
        $loginForm = $this->formBuilder->getForm('Drupal\user\Form\UserLoginForm');
        // Remove the block title.
        unset($loginForm['#prefix']);
        // Have the user login and get sent back to the page.
        $current_path = \Drupal::service('path.current')->getPath();
        $current_path .= "#tab-" . $delta;
        $loginForm['#action'] = '/user/login?destination=' . urlencode($current_path);
        $content[$id]['login'] = $loginForm;
      }
      else {
        $nodeForm = $this->formBuilder->getForm('Drupal\lehigh_iiip\Form\SubmissionForm');
        $content[$id]['node_form'] = $nodeForm;
      }
      if (!empty(\Drupal::messenger()->messagesByType('error'))) {
        $tabs = &$element[0]['tabs'];
        $collectionId = 'tab-0';
        $tabs[$collectionId]['anchor']['#attributes']['class'] = ['nav-link', 'fs-5', 'pt-3', 'fw-medium', 'text-dark'];
        $tabs[$collectionId]['anchor']['#attributes']['aria-selected'] = "false";
        $content[$collectionId]['#attributes']['class'] = ['tab-pane', 'fade'];
        $this->setDefault($id, $tabs, $content);
      }

    }

    return $element;
  }

}
