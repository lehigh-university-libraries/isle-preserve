<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lehigh_islandora\Plugin\Field\FieldType\RelatedItemItem;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Defines the 'related_item' field widget.
 *
 * @FieldWidget(
 *   id = "related_item",
 *   label = @Translation("Related Item"),
 *   field_types = {"related_item"},
 * )
 */
final class RelatedItemWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {

    $element['identifier'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Identifier'),
      '#default_value' => $items[$delta]->identifier ?? NULL,
      '#size' => 20,
    ];

    $element['identifier_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Identifier Type'),
      '#options' => ['' => $this->t('- None -')] + RelatedItemItem::allowedIdentifierTypeValues(),
      '#default_value' => $items[$delta]->identifier_type ?? NULL,
    ];

    $element['number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Number'),
      '#default_value' => $items[$delta]->number ?? NULL,
      '#size' => 20,
    ];

    $element['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $items[$delta]->title ?? NULL,
      '#size' => 20,
    ];

    $element['#theme_wrappers'] = ['container', 'form_element'];
    $element['#attributes']['class'][] = 'container-inline';
    $element['#attributes']['class'][] = 'related-item-elements';
    $element['#attached']['library'][] = 'lehigh_islandora/related_item';

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
      if ($value['identifier'] === '') {
        $values[$delta]['identifier'] = NULL;
      }
      if ($value['identifier_type'] === '') {
        $values[$delta]['identifier_type'] = NULL;
      }
      if ($value['number'] === '') {
        $values[$delta]['number'] = NULL;
      }
      if ($value['title'] === '') {
        $values[$delta]['title'] = NULL;
      }
    }
    return $values;
  }

}
