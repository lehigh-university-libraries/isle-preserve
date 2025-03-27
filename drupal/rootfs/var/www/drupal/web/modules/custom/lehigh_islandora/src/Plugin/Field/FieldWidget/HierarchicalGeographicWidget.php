<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Defines the 'hierarchical_geographic' field widget.
 *
 * @FieldWidget(
 *   id = "hierarchical_geographic",
 *   label = @Translation("Hierarchical Geographic"),
 *   field_types = {"hierarchical_geographic"},
 * )
 */
final class HierarchicalGeographicWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {

    $element['continent'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Continent'),
      '#default_value' => $items[$delta]->continent ?? NULL,
      '#size' => 20,
    ];

    $element['country'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Country'),
      '#default_value' => $items[$delta]->country ?? NULL,
      '#size' => 20,
    ];

    $element['region'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Region'),
      '#default_value' => $items[$delta]->region ?? NULL,
      '#size' => 20,
    ];

    $element['state'] = [
      '#type' => 'textfield',
      '#title' => $this->t('State'),
      '#default_value' => $items[$delta]->state ?? NULL,
      '#size' => 20,
    ];

    $element['territory'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Territory'),
      '#default_value' => $items[$delta]->territory ?? NULL,
      '#size' => 20,
    ];

    $element['county'] = [
      '#type' => 'textfield',
      '#title' => $this->t('County'),
      '#default_value' => $items[$delta]->county ?? NULL,
      '#size' => 20,
    ];

    $element['city'] = [
      '#type' => 'textfield',
      '#title' => $this->t('City'),
      '#default_value' => $items[$delta]->city ?? NULL,
      '#size' => 20,
    ];

    $element['city_section'] = [
      '#type' => 'textfield',
      '#title' => $this->t('City Section'),
      '#default_value' => $items[$delta]->city_section ?? NULL,
      '#size' => 20,
    ];

    $element['island'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Island'),
      '#default_value' => $items[$delta]->island ?? NULL,
      '#size' => 20,
    ];

    $element['area'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Area'),
      '#default_value' => $items[$delta]->area ?? NULL,
      '#size' => 20,
    ];

    $element['extraterrestrial_area'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Extraterrestrial Area'),
      '#default_value' => $items[$delta]->extraterrestrial_area ?? NULL,
      '#size' => 20,
    ];

    $element['#theme_wrappers'] = ['container', 'form_element'];
    $element['#attributes']['class'][] = 'container-inline';
    $element['#attributes']['class'][] = 'hierarchical-geographic-elements';
    $element['#attached']['library'][] = 'lehigh_islandora/hierarchical_geographic';

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
      if ($value['continent'] === '') {
        $values[$delta]['continent'] = NULL;
      }
      if ($value['country'] === '') {
        $values[$delta]['country'] = NULL;
      }
      if ($value['region'] === '') {
        $values[$delta]['region'] = NULL;
      }
      if ($value['state'] === '') {
        $values[$delta]['state'] = NULL;
      }
      if ($value['territory'] === '') {
        $values[$delta]['territory'] = NULL;
      }
      if ($value['county'] === '') {
        $values[$delta]['county'] = NULL;
      }
      if ($value['city'] === '') {
        $values[$delta]['city'] = NULL;
      }
      if ($value['city_section'] === '') {
        $values[$delta]['city_section'] = NULL;
      }
      if ($value['island'] === '') {
        $values[$delta]['island'] = NULL;
      }
      if ($value['area'] === '') {
        $values[$delta]['area'] = NULL;
      }
      if ($value['extraterrestrial_area'] === '') {
        $values[$delta]['extraterrestrial_area'] = NULL;
      }
    }
    return $values;
  }

}
