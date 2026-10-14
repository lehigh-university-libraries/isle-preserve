<?php

namespace Drupal\islandora_scribe\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\islandora\Plugin\Action\EmitNodeEvent;
use Drupal\islandora_scribe\Integration;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Emits node requests through the same broker workflow as mergepdf.
 */
abstract class BookAction extends EmitNodeEvent {

  protected const MODE = 'transcribe';

  /**
   * The correlation service; it does not submit work to Scribe.
   */
  private Integration $integration;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $action = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $action->integration = $container->get('islandora_scribe.integration');
    return $action;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['queue' => 'islandora-scribe-book', 'event' => 'Generate Derivative', 'context_id' => 0];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {}

  /**
   * {@inheritdoc}
   */
  protected function generateData(EntityInterface $entity) {
    $context = static::MODE === 'pdf' ? 0 : (int) ($this->configuration['context_id'] ?? 0);
    $operation = $this->integration->reserveBook($entity, static::MODE, $context);
    return [
      'queue' => 'islandora-scribe-book',
      'event' => 'Generate Derivative',
      'args' => static::MODE . ' ' . $operation . ' ' . $context,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $access = $object->access('update', $account, TRUE)->andIf(AccessResult::allowedIf($this->integration->isPagedContent($object)))->addCacheableDependency($object);
    return $return_as_object ? $access : $access->isAllowed();
  }

}
