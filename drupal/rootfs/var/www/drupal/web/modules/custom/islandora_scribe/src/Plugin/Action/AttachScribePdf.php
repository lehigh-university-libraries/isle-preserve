<?php

namespace Drupal\islandora_scribe\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\islandora_scribe\Integration;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Explicitly replaces a book PDF with its current Scribe export.
 *
 * @Action(
 *   id = "attach_scribe_pdf",
 *   label = @Translation("Attach searchable PDF from Scribe"),
 *   type = "node"
 * )
 */
final class AttachScribePdf extends ActionBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, private Integration $integration) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('islandora_scribe.integration'));
  }

  public function execute($entity = NULL) {
    try {
      $this->integration->enqueuePdf($entity);
      $this->messenger()->addStatus($this->t('PDF export queued.'));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Select paged content and check the Scribe configuration before trying again.'));
    }
  }

  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) { $access = $object->access('update', $account, TRUE)->andIf(\Drupal\Core\Access\AccessResult::allowedIf($this->integration->isPagedContent($object)))->addCacheableDependency($object);
    return $return_as_object ? $access : $access->isAllowed(); }

}
