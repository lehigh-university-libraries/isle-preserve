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

  public function execute($entity = NULL) { $this->integration->attachPdf($entity); }

  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) { return $object->access('update', $account, $return_as_object); }

}
