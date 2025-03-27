<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Defines the 'textarea_attr' field widget.
 *
 * @FieldWidget(
 *   id = "attr_default",
 *   label = @Translation("Textarea with attributes"),
 *   field_types = {
 *     "textarea_attr",
 *     "textfield_attr"
 *   },
 * )
 */
final class AttrWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $field_item = $items[$delta];
    $element['attr0'] = [
      '#type' => 'select',
      '#title' => $field_item->attributeOneName(),
      '#options' => ['' => $this->t('- Select a value -')] + $field_item->allowedAttributeOneValues(),
      '#default_value' => $items[$delta]->attr0 ?? NULL,
    ];

    $attr1_options = $field_item->allowedAttributeTwoValues();
    if (count($attr1_options)) {
      $element['attr1'] = [
        '#type' => 'select',
        '#title' => $this->t('Attribute Two'),
        '#options' => ['' => $this->t('- Select a value -')] + $attr1_options,
        '#default_value' => $items[$delta]->attr1 ?? NULL,
      ];
    }

    $element['value'] = [
      '#type' => get_class($field_item) == 'Drupal\lehigh_islandora\Plugin\Field\FieldType\TextareaAttrItem' ? 'text_format' : 'textfield',
      '#title' => $this->t('Value'),
      '#default_value' => $items[$delta]->value ?? NULL,
    ];
    if (!empty($items[$delta]->format)) {
      $element['value']['#format'] = $items[$delta]->format;
    }

    $element['#theme_wrappers'] = ['container', 'form_element'];
    $element['#attributes']['class'][] = 'attr-elements';
    $element['#attached']['library'][] = 'lehigh_islandora/attr';

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
      if (isset($value['value']['format'])) {
        $values[$delta]['format'] = $value['value']['format'];
        $values[$delta]['value'] = $value['value']['value'];
      }

      if (empty($value['attr0'])) {
        $values[$delta]['attr0'] = NULL;
      }
      if (empty($value['attr1'])) {
        $values[$delta]['attr1'] = NULL;
      }
      if (empty($value['value'])) {
        $values[$delta]['value'] = NULL;
      }
      if (empty($value['format'])) {
        $values[$delta]['format'] = NULL;
      }
    }
    return $values;
  }

}
