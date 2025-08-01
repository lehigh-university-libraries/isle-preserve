<?php

declare(strict_types=1);

namespace Drupal\islandora_collection_tabs\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;

/**
 * Plugin implementation of the 'islandora_collection_tabs_default' formatter.
 *
 * @FieldFormatter(
 *   id = "islandora_collection_tabs_default",
 *   label = @Translation("Default"),
 *   field_types = {"islandora_collection_tabs"},
 * )
 */
class CollectionTabsDefaultFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $element = [];
    $element['#attached']['library'][] = 'islandora_collection_tabs/tabs';
    $element[0]['tabs'] = [
      '#type' => 'html_tag',
      '#tag' => 'ul',
      '#attributes' => [
        'class' => [
          'nav', 'nav-tabs',
          'justify-content-end',
          'nav-fill',
          'bg-light',
          'opacity-75',
        ],
        'role' => 'tablist',
      ],
    ];
    $element[0]['content'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => [
          'tab-content',
          'mb-5',
          'col-9',
          'm-auto',
        ],
        'id' => 'collectionTabContent',
      ],
    ];
    $tabs = &$element[0]['tabs'];
    $content = &$element[0]['content'];

    $collectionId = 'tab-view-collection';
    $item = (object) [
      'default' => TRUE,
      'label' => 'View this collection',
      'value' => '&nbsp;',
    ];
    $tabs[$collectionId] = $this->addTab($collectionId, $item);
    $content[$collectionId] = $this->addContent($collectionId, $item);
    $this->setDefault($collectionId, $tabs, $content);

    $request = \Drupal::request();
    $s = $request->query->get('search_api_fulltext');
    $f = $request->query->all('f');
    $filters = is_array($f) && count($f) > 0;
    $filters |= is_string($s) && strlen($s) > 0;
    foreach ($items as $delta => $item) {
      $id = 'tab-' . $delta;
      $tabs[$id] = $this->addTab($id, $item);
      $content[$id] = $this->addContent($id, $item);
      if (!$filters && $item->default) {
        $this->setDefault($id, $tabs, $content);
        // Remove default form hard coded default.
        $tabs[$collectionId]['anchor']['#attributes']['class'] = ['nav-link', 'fs-5', 'pt-3', 'fw-medium', 'text-dark'];
        $tabs[$collectionId]['anchor']['#attributes']['aria-selected'] = "false";
        $content[$collectionId]['#attributes']['class'] = ['tab-pane', 'fade'];
      }
    }

    return $element;
  }

  /**
   * Add a tab to the render element.
   */
  private function addTab(string $id, mixed $item): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'li',
      '#attributes' => [
        'class' => ['nav-item', 'm-0', 'p-0', 'border', 'border-black', 'border-1'],
        'role' => 'presentation',
      ],
      'anchor' => [
        '#type' => 'html_tag',
        '#tag' => 'a',
        '#attributes' => [
          'href' => "#" . $id,
          'id' => $id . '-tab',
          'class' => ['nav-link', 'fs-5', 'pt-3', 'fw-medium', 'text-dark'],
          'data-bs-toggle' => "tab",
          'data-bs-target' => "#" . $id,
          'type' => 'button',
          'role' => "tab",
          'aria-controls' => $id,
          'aria-selected' => "false",
        ],
        '#value' => $item->label,
      ],
    ];
  }

  /**
   * Add content to the render element.
   */
  private function addContent(string $id, mixed $item): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'id' => $id,
        'class' => ['tab-pane', 'fade'],
        'role' => 'tabpanel',
        'aria-labelledby' => $id . '-tab',
        'tabindex' => -1,
      ],
      '#value' => $item->value,
    ];
  }

  /**
   * Set a tab/content pair as the default on page load.
   */
  private function setDefault($id, &$tabs, &$content) {
    $tabs[$id]['anchor']['#attributes']['class'][] = 'active';
    $tabs[$id]['anchor']['#attributes']['aria-selected'] = "true";
    $content[$id]['#attributes']['class'][] = 'show active';
  }

}
