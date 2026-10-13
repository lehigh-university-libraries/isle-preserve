<?php

namespace Drupal\islandora_scribe\Plugin\Action;

use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\islandora_scribe\Integration;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Enqueues public paged-content transcription.
 *
 * @Action(
 *   id = "transcribe_scribe_paged_content",
 *   label = @Translation("Transcribe paged content using Scribe"),
 *   type = "node"
 * )
 */
final class TranscribePagedContent extends ConfigurableActionBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, private Integration $integration) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('islandora_scribe.integration'));
  }

  public function defaultConfiguration() { return ['context_id' => 0]; }

  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['context_id'] = ['#type' => 'number', '#title' => $this->t('Scribe context ID'), '#min' => 1, '#step' => 1, '#required' => TRUE, '#default_value' => $this->configuration['context_id']];
    return $form;
  }

  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    if (filter_var($form_state->getValue('context_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === FALSE) { $form_state->setErrorByName('context_id', $this->t('Select a positive Scribe context ID.')); }
  }

  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) { $this->configuration['context_id'] = (int) $form_state->getValue('context_id'); }

  public function execute($entity = NULL) {
    try {
      $this->integration->enqueueBook($entity, (int) $this->configuration['context_id']);
      $this->messenger()->addStatus($this->t('Book transcription queued.'));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Select paged content and check the Scribe configuration before trying again.'));
    }
  }

  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) { $access = $object->access('update', $account, TRUE)->andIf(\Drupal\Core\Access\AccessResult::allowedIf($this->integration->isPagedContent($object)))->addCacheableDependency($object);
    return $return_as_object ? $access : $access->isAllowed(); }

}
