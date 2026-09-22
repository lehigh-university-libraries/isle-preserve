<?php

namespace Drupal\lehigh_analytics\Plugin\Block;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lehigh_analytics\ReportDisplay;
use Drupal\lehigh_analytics\UsageReport;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Embeds an aggregate report with saved filters or current-collection context.
 */
#[Block(
  id: 'lehigh_analytics_usage',
  admin_label: new TranslatableMarkup('The Preserve usage report'),
  category: new TranslatableMarkup('Lehigh Analytics'),
)]
final class UsageBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly UsageReport $reports,
    private readonly ReportDisplay $display,
    private readonly RouteMatchInterface $routeMatch,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration, $plugin_id, $plugin_definition,
      $container->get('lehigh_analytics.reports'),
      $container->get('lehigh_analytics.display'),
      $container->get('current_route_match'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'report' => 'views',
      'scope' => 'filters',
      'criteria' => ['period' => 'fiscal'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $options = UsageReport::TITLES;
    unset($options['counter'], $options['acrl']);
    $form['report'] = [
      '#type' => 'select',
      '#title' => $this->t('Report'),
      '#options' => $options,
      '#default_value' => $this->configuration['report'],
    ];
    $form['scope'] = [
      '#type' => 'select',
      '#title' => $this->t('Collection scope'),
      '#options' => [
        'filters' => $this->t('Use saved metadata filters'),
        'current_collection' => $this->t('Current collection'),
      ],
      '#default_value' => $this->configuration['scope'],
      '#description' => $this->t('Current collection uses the collection node on the current page instead of the saved parent-collection filter. Other metadata filters still apply. The block is hidden on pages without a Collection-model node.'),
    ];
    return $form + $this->display->controls($this->configuration['criteria']);
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['report'] = $form_state->getValue('report');
    $this->configuration['scope'] = $form_state->getValue('scope');
    $this->configuration['criteria'] = $this->display->submitted($form_state->getValues());
  }

  /**
   * Resolves only actual collection nodes; missing context never means all data.
   */
  private function currentCollection(): ?NodeInterface {
    $node = $this->routeMatch->getParameter('node');
    if (is_scalar($node) && ctype_digit((string) $node)) {
      $node = $this->entityTypeManager->getStorage('node')->load($node);
    }
    if (!$node instanceof NodeInterface || !$node->isPublished() || !$node->hasField('field_model')) {
      return NULL;
    }
    foreach ($node->get('field_model')->referencedEntities() as $term) {
      if ($term->hasField('field_external_uri') && $term->get('field_external_uri')->uri === 'http://purl.org/dc/dcmitype/Collection') {
        return $node;
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    $access = $this->configuration['report'] === 'anomalies'
      ? AccessResult::allowedIfHasPermission($account, 'view lehigh analytics')
      : AccessResult::allowed();
    if ($this->configuration['scope'] === 'current_collection') {
      $access = $access->andIf(AccessResult::allowedIf($this->currentCollection() !== NULL)->addCacheContexts(['route'])->setCacheMaxAge(0));
    }
    return $access;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $criteria = $this->reports->criteria($this->configuration['criteria']);
    if ($this->configuration['scope'] === 'current_collection') {
      $collection = $this->currentCollection();
      if (!$collection) {
        return ['#cache' => ['max-age' => 0, 'contexts' => ['route', 'user.permissions']]];
      }
      $criteria['filters']['field_member_of'] = [(int) $collection->id()];
    }
    try {
      $build = $this->display->build($this->configuration['report'], $criteria);
    }
    catch (ServiceUnavailableHttpException $exception) {
      return ['#plain_text' => $this->t('Usage data is being prepared.'), '#cache' => ['max-age' => 0]];
    }
    $build['standards'] = $this->display->downloads($criteria);
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return array_unique(array_merge(parent::getCacheContexts(), ['route', 'user.permissions']));
  }

}
