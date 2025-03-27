<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lehigh_islandora\Plugin\Field\FieldType\PartDetailItem;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Defines the 'part_detail' field widget.
 *
 * @FieldWidget(
 *   id = "part_detail",
 *   label = @Translation("PartDetail"),
 *   field_types = {"part_detail"},
 * )
 */
final class PartDetailWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {

    $element['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type'),
      '#options' => ['' => $this->t('- None -')] + PartDetailItem::allowedTypeValues(),
      '#default_value' => $items[$delta]->type ?? NULL,
    ];

    $element['caption'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Caption'),
      '#default_value' => $items[$delta]->caption ?? NULL,
      '#size' => 20,
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
    $element['#attributes']['class'][] = 'part-detail-elements';
    $element['#attached']['library'][] = 'lehigh_islandora/part_detail';

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
      if ($value['type'] === '') {
        $values[$delta]['type'] = NULL;
      }
      if ($value['caption'] === '') {
        $values[$delta]['caption'] = NULL;
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
