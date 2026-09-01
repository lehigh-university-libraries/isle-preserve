<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceLabelFormatter;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Links entity-reference labels to a Facets 3 or Advanced Search filter.
 */
#[FieldFormatter(
  id: 'lehigh_browse_filter_link',
  label: new TranslatableMarkup('Browse filter link'),
  description: new TranslatableMarkup('Link referenced entities to repository search filters.'),
  field_types: [
    'entity_reference',
  ],
)]
final class BrowseFilterLinkFormatter extends EntityReferenceLabelFormatter {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings(): array {
    return [
      'exposed_filter' => '',
      'search_field' => '',
      'value_source' => 'id',
      'link' => TRUE,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $form = parent::settingsForm($form, $form_state);
    $form['link']['#access'] = FALSE;
    $form['exposed_filter'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Facets 3 exposed filter identifier'),
      '#description' => $this->t('Use this when the browse View exposes the field as a facet.'),
      '#default_value' => $this->getSetting('exposed_filter'),
    ];
    $form['search_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search API field identifier'),
      '#description' => $this->t('Fallback field for an Advanced Search query when no exposed filter is configured.'),
      '#default_value' => $this->getSetting('search_field'),
    ];
    $form['value_source'] = [
      '#type' => 'select',
      '#title' => $this->t('Filter value'),
      '#options' => [
        'id' => $this->t('Referenced entity ID'),
        'label' => $this->t('Referenced entity label'),
      ],
      '#default_value' => $this->getSetting('value_source'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $identifier = $this->getSetting('exposed_filter')
      ?: $this->getSetting('search_field');
    return [
      $this->t('Links to the browse filter %identifier using the entity %source.', [
        '%identifier' => $identifier,
        '%source' => $this->getSetting('value_source'),
      ]),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = parent::viewElements($items, $langcode);
    $exposed_filter = (string) $this->getSetting('exposed_filter');
    $search_field = (string) $this->getSetting('search_field');

    foreach ($elements as $delta => &$element) {
      $entity = $items[$delta]->entity;
      if (!$entity || ($exposed_filter === '' && $search_field === '')) {
        continue;
      }

      $label = $entity->label() ?? (string) $entity->id();
      $value = $this->getSetting('value_source') === 'label'
        ? $label
        : (string) $entity->id();
      $query = $exposed_filter !== ''
        ? [$exposed_filter => [$value]]
        : ['a' => [['f' => $search_field, 'v' => $value]]];

      $element['#type'] = 'link';
      $element['#title'] = $label;
      $element['#url'] = Url::fromUri('internal:/browse', [
        'query' => $query,
        'attributes' => ['rel' => 'nofollow'],
      ]);
      unset($element['#plain_text'], $element['#options']);
    }
    unset($element);

    return $elements;
  }

}
