<?php

declare(strict_types=1);

namespace Drupal\lehigh_iiip\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\islandora_collection_tabs\Plugin\Field\FieldFormatter\CollectionTabsDefaultFormatter;
use Drupal\Core\Form\FormBuilderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\HttpFoundation\RequestStack;

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

  public function __construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings, FormBuilderInterface $form_builder, RequestStack $request_stack) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings, $request_stack);
    $this->formBuilder = $form_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('form_builder'),
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $element = parent::viewElements($items, $langcode);
    $content = &$element[0]['content'];
    $tabs = &$element[0]['tabs'];
    $about_id = NULL;
    $contribute_id = NULL;

    foreach ($items as $delta => $item) {
      if (!is_string($item->label) || trim($item->label) === '') {
        continue;
      }

      $label = strtolower(trim($item->label));
      $id = 'tab-' . $delta;
      if ($label === 'about this collection') {
        $about_id = $id;
        $tabs[$id]['anchor']['#value'] = 'About this Collection';
      }
      elseif ($label === 'contribute to this collection') {
        $contribute_id = $id;
      }

      if ($label !== 'contribute to this collection') {
        continue;
      }

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
    }

    $tabs['tab-view-collection']['anchor']['#value'] = 'View this Collection';
    $this->reorderTabs($tabs, $content, array_filter([
      $about_id,
      'tab-view-collection',
      $contribute_id,
    ]));

    if ($about_id !== NULL) {
      $this->resetActiveState($tabs, $content);
      $this->setDefault($about_id, $tabs, $content);
    }

    if ($contribute_id !== NULL && !empty(\Drupal::messenger()->messagesByType('error'))) {
      $this->resetActiveState($tabs, $content);
      $this->setDefault($contribute_id, $tabs, $content);
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  protected function getCollectionViewItem(): object {
    return (object) [
      'default' => FALSE,
      'label' => 'View this Collection',
      'value' => '&nbsp;',
    ];
  }

  /**
   * Reorder the rendered tabs while keeping render metadata intact.
   */
  private function reorderTabs(array &$tabs, array &$content, array $priority_ids): void {
    $this->reorderRenderArray($tabs, $priority_ids);
    $this->reorderRenderArray($content, $priority_ids);
  }

  /**
   * Reorder child elements in a render array.
   */
  private function reorderRenderArray(array &$elements, array $priority_ids): void {
    $metadata = [];
    $children = [];

    foreach ($elements as $key => $value) {
      if (str_starts_with((string) $key, '#')) {
        $metadata[$key] = $value;
      }
      else {
        $children[$key] = $value;
      }
    }

    $ordered = [];
    foreach ($priority_ids as $id) {
      if (isset($children[$id])) {
        $ordered[$id] = $children[$id];
        unset($children[$id]);
      }
    }

    $elements = $metadata + $ordered + $children;
  }

  /**
   * Remove active state so a different tab can become the default.
   */
  private function resetActiveState(array &$tabs, array &$content): void {
    foreach ($tabs as $tab_id => &$tab) {
      if (str_starts_with((string) $tab_id, '#') || empty($tab['anchor']['#attributes']['class'])) {
        continue;
      }

      $tab['anchor']['#attributes']['class'] = array_values(array_diff($tab['anchor']['#attributes']['class'], ['active']));
      $tab['anchor']['#attributes']['aria-selected'] = "false";
    }

    foreach ($content as $content_id => &$pane) {
      if (str_starts_with((string) $content_id, '#') || empty($pane['#attributes']['class'])) {
        continue;
      }

      $pane['#attributes']['class'] = array_values(array_diff($pane['#attributes']['class'], ['show', 'active']));
    }
  }

}
