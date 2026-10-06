<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\lehigh_islandora\JournalBrowser\BrowserAccess;
use Drupal\lehigh_islandora\JournalBrowser\JournalBrowserBuilder;
use Drupal\lehigh_islandora\JournalBrowser\JournalBrowserRepository;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the same accessible source-browser state as HTML and JSON.
 */
final class SourceBrowserController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected JournalBrowserBuilder $builder,
    protected JournalBrowserRepository $repository,
    protected BrowserAccess $browserAccess,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('lehigh_islandora.journal_browser.builder'),
      $container->get('lehigh_islandora.journal_browser.repository'),
      $container->get('lehigh_islandora.source_browser.access'),
    );
  }

  /**
   * Supplies the source-specific route title.
   */
  public function title(NodeInterface $node): string {
    return $node->label();
  }

  /**
   * Supplies the landing route title.
   */
  public function landingTitle(): string {
    return (string) $this->t('Source Browser');
  }

  /**
   * Preserves shared query state on the source-specific entry point.
   */
  public function legacy(NodeInterface $node, Request $request): RedirectResponse {
    $this->assertSource($node);
    return $this->redirect('lehigh_islandora.source_browser_landing', [], [
      'query' => ['source' => (int) $node->id()] + $request->query->all(),
    ]);
  }

  /**
   * Resolves an explicitly requested or the first metadata-enabled source.
   */
  public function landing(Request $request): array {
    $requested = (int) $request->query->get('source');
    if ($requested > 0) {
      $node = $this->entityTypeManager()->getStorage('node')->load($requested);
      if (!$node instanceof NodeInterface) {
        throw new NotFoundHttpException();
      }
      return $this->view($node);
    }
    $sources = $this->repository->getSources();
    if ($node = reset($sources)) {
      return $this->view($node);
    }
    $build = ['#markup' => $this->t('No browsable sources were found.')];
    $this->browserAccess->getCacheability()->applyTo($build);
    return $build;
  }

  /**
   * Renders the browser and current Islandora viewer.
   */
  public function view(NodeInterface $node): array {
    $this->assertSource($node);
    $browser = $this->builder->build($node);
    $selected = $browser['selected_issue'];
    $viewer = [];
    if ($selected && $selected['can_view']) {
      $viewer = [
        '#theme' => 'mirador',
        '#mirador_view_id' => 'mirador_' . $selected['nid'],
        '#iiif_manifest_url' => $selected['manifest_url'],
        '#thumbnail_navigation_position' => 'far-right',
        '#window_config' => [
          'canvasId' => $selected['selected_page']['canvas'] ?? '',
          'sideBarOpen' => FALSE,
          'textOverlay' => [
            'enabled' => FALSE,
            'selectable' => FALSE,
            'visible' => FALSE,
          ],
        ],
      ];
    }
    $build = [
      '#theme' => 'lehigh_source_browser',
      '#browser' => $browser,
      '#selected_issue_viewer' => $viewer,
      '#attached' => [
        'library' => ['lehigh_islandora/source-browser'],
        // Loading these on every tab lets AJAX enter the viewer without reload.
        'drupalSettings' => ['mirador' => ['viewers' => []]],
      ],
    ];
    $browser['#cacheability']->applyTo($build);
    return $build;
  }

  /**
   * Returns the browser model with complete aggregate cache metadata.
   */
  public function json(NodeInterface $node): CacheableJsonResponse {
    $this->assertSource($node);
    $browser = $this->builder->build($node);
    $cacheability = $browser['#cacheability'];
    unset($browser['#cacheability']);
    return (new CacheableJsonResponse($browser))->addCacheableDependency($cacheability);
  }

  /**
   * Returns scoped search results and stable pagination URLs.
   */
  public function search(NodeInterface $node): CacheableJsonResponse {
    $this->assertSource($node);
    $browser = $this->builder->build($node);
    return (new CacheableJsonResponse([
      'query' => $browser['query'],
      'scope' => $browser['scope'],
      'offset' => $browser['offset'],
      'results' => $browser['search_results'],
      'previous_url' => $browser['previous_results_url'],
      'next_url' => $browser['next_results_url'],
    ]))->addCacheableDependency($browser['#cacheability']);
  }

  /**
   * Returns source-local index facets or one term's page/range hits.
   */
  public function index(NodeInterface $node, ?int $term_id = NULL): CacheableJsonResponse {
    $this->assertSource($node);
    $index = $this->builder->buildIndex($node, NULL, $term_id);
    if ($term_id !== NULL) {
      foreach ($index as $terms) {
        foreach ($terms as $term) {
          if ($term['tid'] === $term_id) {
            return (new CacheableJsonResponse($term))->addCacheableDependency($this->browserAccess->getCacheability());
          }
        }
      }
      throw new NotFoundHttpException();
    }
    return (new CacheableJsonResponse($index))->addCacheableDependency($this->browserAccess->getCacheability());
  }

  /**
   * Returns accessible PDFs for the selected issue, or the source itself.
   */
  public function downloads(NodeInterface $node): CacheableJsonResponse {
    $this->assertSource($node);
    $browser = $this->builder->build($node);
    $downloads = $browser['selected_issue']['downloads'] ?? $this->repository->getDownloads($node);
    return (new CacheableJsonResponse($downloads))->addCacheableDependency($this->browserAccess->getCacheability());
  }

  /**
   * Every entry point uses the collection metadata switch and view access.
   */
  protected function assertSource(NodeInterface $node): void {
    if (!$this->repository->isBrowserSource($node)) {
      throw new NotFoundHttpException();
    }
  }

}
