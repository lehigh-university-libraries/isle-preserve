<?php

declare(strict_types=1);

namespace Drupal\lehigh_iiip\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\islandora_collection_tabs\Plugin\Field\FieldFormatter\CollectionTabsDefaultFormatter;
use Drupal\Core\Form\FormBuilderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\node\Entity\Node;

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
        $node = Node::create([
          'type' => 'islandora_object',
          'field_member_of' => 453222,
        ]);

        $form_object = \Drupal::entityTypeManager()
          ->getFormObject('node', 'iiip_submission')
          ->setEntity($node);

        $content[$id]['node_form'] = \Drupal::formBuilder()->getForm($form_object);

      }
    }

    return $element;
  }

}
