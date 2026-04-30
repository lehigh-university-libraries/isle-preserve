<?php

declare(strict_types=1);

namespace Drupal\islandora_collection_tabs\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\islandora_collection_tabs\Plugin\Field\FieldType\CollectionTabsItem;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Plugin implementation of the 'islandora_collection_tabs_default' formatter.
 *
 * @FieldFormatter(
 *   id = "islandora_collection_tabs_default",
 *   label = @Translation("Default"),
 *   field_types = {"islandora_collection_tabs"},
 * )
 */
class CollectionTabsDefaultFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  /**
   * The request stack.
   */
  protected RequestStack $requestStack;

  /**
   * Constructs a CollectionTabsDefaultFormatter object.
   */
  public function __construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings, RequestStack $request_stack) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
    $this->requestStack = $request_stack;
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
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    if ($this->getDisplayMode($items) === CollectionTabsItem::DISPLAY_INLINE) {
      return $this->buildInlineElements($items);
    }

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
    $item = $this->getCollectionViewItem();
    $tabs[$collectionId] = $this->addTab($collectionId, $item);
    $content[$collectionId] = $this->addContent($collectionId, $item);
    $this->setDefault($collectionId, $tabs, $content);

    $request = $this->requestStack->getCurrentRequest();
    $s = $request->query->get('search_api_fulltext');
    $f = $request->query->all('f');
    $filters = is_array($f) && count($f) > 0;
    $filters |= is_string($s) && strlen($s) > 0;
    foreach ($items as $delta => $item) {
      if (!$this->shouldRenderItem($item)) {
        continue;
      }

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

      if ($item->map) {
        $map = $this->buildMapView();
        if ($map !== NULL) {
          $content[$id]['view'] = $map;
        }
      }
    }

    return $element;
  }

  /**
   * Builds the inline section display for the field items.
   */
  protected function buildInlineElements(FieldItemListInterface $items): array {
    $element = [
      0 => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['collection-tab-sections'],
        ],
      ],
    ];

    foreach ($items as $delta => $item) {
      if (!$this->shouldRenderItem($item)) {
        continue;
      }

      $has_visible_content = FALSE;
      $section = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['collection-tab-section', 'mb-5'],
        ],
      ];

      if (!empty($item->label)) {
        $has_visible_content = TRUE;
        $section['label'] = [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $item->label,
          '#attributes' => [
            'class' => ['h3', 'mb-3'],
          ],
        ];
      }

      if (!empty($item->value)) {
        $has_visible_content = TRUE;
        $section['content'] = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => $item->value,
          '#attributes' => [
            'class' => ['collection-tab-section__content'],
          ],
        ];
      }

      if ($item->map) {
        $map = $this->buildMapView();
        if ($map !== NULL) {
          $has_visible_content = TRUE;
          $section['map'] = $map;
        }
      }

      if ($has_visible_content) {
        $element[0]['section_' . $delta] = $section;
      }
    }

    return $element;
  }

  /**
   * Gets the display mode for this field.
   */
  protected function getDisplayMode(FieldItemListInterface $items): string {
    $first_item = $items->first();
    if ($first_item === NULL || empty($first_item->display)) {
      return CollectionTabsItem::DISPLAY_TABS;
    }

    return $first_item->display;
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
   * Builds the default collection view pseudo-tab item.
   */
  protected function getCollectionViewItem(): object {
    return (object) [
      'default' => TRUE,
      'label' => 'View this collection',
      'value' => '&nbsp;',
    ];
  }

  /**
   * Builds the shared map view render array when results exist.
   */
  protected function buildMapView(): ?array {
    $view = Views::getView('map');
    if (!$view) {
      return NULL;
    }

    $view->setDisplay('default');
    $view->execute();

    if ($view->total_rows <= 0) {
      return NULL;
    }

    return $view->render();
  }

  /**
   * Set a tab/content pair as the default on page load.
   */
  protected function setDefault($id, &$tabs, &$content) {
    $tabs[$id]['anchor']['#attributes']['class'][] = 'active';
    $tabs[$id]['anchor']['#attributes']['aria-selected'] = "true";
    $content[$id]['#attributes']['class'][] = 'show active';
  }

  /**
   * Determine whether a field item has anything worth rendering.
   */
  protected function shouldRenderItem(mixed $item): bool {
    $label = is_string($item->label ?? NULL) ? trim($item->label) : '';
    $value = is_string($item->value ?? NULL) ? trim(strip_tags($item->value)) : '';
    $has_map = !empty($item->map);

    return $label !== '' || $value !== '' || $has_map;
  }

}
