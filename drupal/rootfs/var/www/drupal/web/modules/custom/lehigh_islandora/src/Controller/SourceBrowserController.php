<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\lehigh_islandora\JournalBrowser\JournalBrowserBuilder;
use Drupal\lehigh_islandora\JournalBrowser\JournalBrowserRepository;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the Islandora source browser.
 */
final class SourceBrowserController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected JournalBrowserBuilder $builder,
    protected JournalBrowserRepository $repository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('lehigh_islandora.journal_browser.builder'),
      $container->get('lehigh_islandora.journal_browser.repository'),
    );
  }

  /**
   * Route title callback.
   */
  public function title(NodeInterface $node): string {
    return $node->label();
  }

  /**
   * Landing route title callback.
   */
  public function landingTitle(): string {
    return $this->t('Source Browser')->render();
  }

  /**
   * Redirects old source-specific browser URLs to the canonical browser page.
   */
  public function legacy(NodeInterface $node, Request $request): RedirectResponse {
    $query = $request->query->all();
    $query['source'] = (int) $node->id();
    return $this->redirect('lehigh_islandora.source_browser_landing', [], [
      'query' => $query,
    ]);
  }

  /**
   * Renders the canonical source browser landing page.
   */
  public function landing(Request $request): array {
    $node = $this->resolveSource($request);
    if (!$node) {
      return [
        '#markup' => $this->t('No browsable sources were found.'),
        '#cache' => [
          'contexts' => ['url.query_args', 'user.permissions'],
          'tags' => ['node_list'],
          'max-age' => 300,
        ],
      ];
    }

    return $this->view($node);
  }

  /**
   * Renders the source browser.
   */
  public function view(NodeInterface $node): array {
    $browser = $this->builder->build($node);
    $selected_issue_viewer = [];
    if (!empty($browser['selected_issue']['nid'])) {
      $issue = $this->entityTypeManager()->getStorage('node')->load((int) $browser['selected_issue']['nid']);
      if ($issue instanceof NodeInterface && $issue->access('view') && $this->canDisplayViewer($issue)) {
        $selected_issue_viewer = [
          '#theme' => 'mirador',
          '#mirador_view_id' => 'mirador_' . $issue->id(),
          '#iiif_manifest_url' => Url::fromUserInput('/node/' . $issue->id() . '/book-manifest', [
            'absolute' => TRUE,
          ])->toString(),
          '#settings' => [],
          '#cache' => [
            'contexts' => ['url.site', 'user.permissions'],
            'tags' => ['node:' . $issue->id(), 'media_list'],
            'max-age' => 300,
          ],
        ];
      }
    }
    $build = [
      '#theme' => 'lehigh_source_browser',
      '#browser' => $browser,
      '#selected_issue_viewer' => $selected_issue_viewer,
      '#attached' => [
        'library' => [
          'lehigh_islandora/source-browser',
        ],
      ],
    ];
    if (!empty($browser['#cacheability'])) {
      $browser['#cacheability']->applyTo($build);
    }
    return $build;
  }

  /**
   * Checks whether the selected issue's viewer can be displayed.
   */
  protected function canDisplayViewer(NodeInterface $node): bool {
    if (function_exists('lehigh_embargo_node_is_embargoed') && lehigh_embargo_node_is_embargoed($node)) {
      return FALSE;
    }
    if (function_exists('lehigh_islandora_node_is_locally_restricted') &&
      function_exists('lehigh_islandora_on_campus') &&
      lehigh_islandora_node_is_locally_restricted($node) &&
      !lehigh_islandora_on_campus()) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Resolves the selected source from the root source list.
   */
  protected function resolveSource(Request $request): ?NodeInterface {
    $storage = $this->entityTypeManager()->getStorage('node');
    $requested = (int) $request->query->get('source');
    if ($requested > 0) {
      $node = $storage->load($requested);
      if ($node instanceof NodeInterface && $node->access('view')) {
        return $node;
      }
    }

    $root = $storage->load(1);
    if (!$root instanceof NodeInterface) {
      return NULL;
    }

    $sources = $this->repository->getSources($root);
    return reset($sources) ?: NULL;
  }

  /**
   * Returns the normalized browser model as JSON.
   */
  public function json(NodeInterface $node): CacheableJsonResponse {
    $browser = $this->builder->build($node);
    $cacheability = $browser['#cacheability'] ?? NULL;
    unset($browser['#cacheability']);
    $response = new CacheableJsonResponse($browser);
    if ($cacheability) {
      $response->addCacheableDependency($cacheability);
    }
    return $response;
  }

  /**
   * Returns scoped search results.
   */
  public function search(NodeInterface $node, Request $request): JsonResponse {
    $query = trim((string) $request->query->get('q', ''));
    $results = [];
    foreach ($this->repository->searchWithinSource($node, $query) as $result) {
      $results[] = [
        'nid' => $result['nid'],
        'title' => $result['title'],
        'model' => $result['model'],
        'description' => $result['description'],
        'snippet' => $result['snippet'],
        'match_source' => $result['match_source'],
        'url' => $result['url'],
      ];
    }
    return new JsonResponse([
      'query' => $query,
      'results' => $results,
    ]);
  }

  /**
   * Returns available index entries.
   */
  public function index(NodeInterface $node): JsonResponse {
    return new JsonResponse($this->builder->buildIndex($node));
  }

  /**
   * Returns source-level download choices.
   */
  public function downloads(NodeInterface $node): JsonResponse {
    return new JsonResponse($this->repository->getDownloads($node));
  }

}
