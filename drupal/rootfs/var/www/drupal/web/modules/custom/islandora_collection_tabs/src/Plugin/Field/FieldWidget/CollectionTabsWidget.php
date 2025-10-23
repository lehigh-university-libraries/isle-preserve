<?php

declare(strict_types=1);

namespace Drupal\islandora_collection_tabs\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Defines the 'islandora_collection_tabs' field widget.
 *
 * @FieldWidget(
 *   id = "islandora_collection_tabs",
 *   label = @Translation("Collection Tabs"),
 *   field_types = {"islandora_collection_tabs"},
 * )
 */
final class CollectionTabsWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $element['default'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Default tab'),
      '#default_value' => $items[$delta]->default ?? NULL,
      '#description' => $this->t('If no default tab is selected, the default collection tab will be displayed'),
    ];

    $element['map'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show map'),
      '#default_value' => $items[$delta]->map ?? NULL,
      '#description' => $this->t('If children items have geo coordinates, show them all in a map display on this tab'),
    ];

    $element['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tab Label'),
      '#default_value' => $items[$delta]->label ?? NULL,
    ];

    $element['value'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Tab Content'),
      '#default_value' => $items[$delta]->value ?? NULL,
      '#format' => 'full_html',
    ];

    $element['#theme_wrappers'] = ['container', 'form_element'];
    $element['#attributes']['class'][] = 'islandora-collection-tabs-elements';
    $element['#attached']['library'][] = 'islandora_collection_tabs/islandora_collection_tabs';

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function errorElement(array $element, ConstraintViolationInterface $error, array $form, FormStateInterface $form_state): array|bool {
    $element = parent::errorElement($element, $error, $form, $form_state);
    if ($element === FALSE) {
      return FALSE;
    }
    $error_property = explode('.', $error->getPropertyPath())[1];
    return $element[$error_property];
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state): array {
    foreach ($values as $delta => $value) {
      // Get the value from the text format form element.
      $values[$delta]['value'] = $value['value']['value'];

      if ($value['label'] === '') {
        $values[$delta]['label'] = NULL;
      }
      if ($value['map'] === '') {
        $values[$delta]['map'] = NULL;
      }
      if ($value['value'] === '') {
        $values[$delta]['value'] = NULL;
      }
      if ($value['default'] === '') {
        $values[$delta]['default'] = NULL;
      }
    }
    return $values;
  }

}
