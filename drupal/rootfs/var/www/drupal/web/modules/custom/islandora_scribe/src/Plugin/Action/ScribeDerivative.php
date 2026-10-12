<?php

namespace Drupal\islandora_scribe\Plugin\Action;

use Drupal\Core\Url;
use Drupal\islandora_scribe\Integration;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\islandora_hocr\Plugin\Action\HocrDerivative;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Emits a correlated Scribe hOCR derivative request.
 *
 * @Action(
 *   id = "generate_scribe_hocr_derivative",
 *   label = @Translation("Generate hOCR using Scribe"),
 *   type = "node"
 * )
 */
final class ScribeDerivative extends HocrDerivative {
  /**
   * The Scribe integration service.
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
    return array_replace(parent::defaultConfiguration(), [
      'queue' => 'islandora-scribe-hocr',
      'derivative_term_uri' => 'https://discoverygarden.ca/use#hocr',
      'args' => '',
      'context_id' => 0,
      'path' => 'derivatives/hocr/[node:nid]/[node:nid]-scribe.hocr',
      'scheme' => 'private',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['context_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Scribe processing context ID'),
      '#description' => $this->t('Select a Scribe context containing the desired segmentor and transcription model. Enter 0 to use Scribe’s automatic context selection.'),
      '#default_value' => $this->configuration['context_id'] ?? 0,
      '#min' => 0,
      '#max' => PHP_INT_MAX,
      '#step' => 1,
      '#required' => TRUE,
    ];
    // Correlation and context arguments are generated from the persisted operation.
    unset($form['args']);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    if (filter_var($form_state->getValue('context_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === FALSE) {
      $form_state->setErrorByName('context_id', $this->t('Enter a nonnegative integer context ID.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $this->configuration['context_id'] = (int) $form_state->getValue('context_id');
    $this->configuration['args'] = '';
  }

  /**
   * {@inheritdoc}
   */
  protected function generateData(EntityInterface $entity) {
    $context = filter_var($this->configuration['context_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($context === FALSE) {
      throw new \InvalidArgumentException('Invalid Scribe processing context ID.');
    }
    $data = parent::generateData($entity);
    unset($data['context_id']);
    $source = $this->utils->getMediaWithTerm($entity, $this->utils->getTermForUri($this->configuration['source_term_uri']));
    $term = $this->utils->getTermForUri($this->configuration['derivative_term_uri']);
    $mapping = $this->integration->reserve($source, $data['file_upload_uri'], (int) $entity->id(), $this->configuration['destination_media_type'], (int) $term->id(), $context);
    $data['destination_uri'] = Url::fromRoute('islandora_scribe.derivative', [
      'operation' => $mapping['operation_id'],
      'node' => $entity->id(),
      'media_type' => $this->configuration['destination_media_type'],
      'taxonomy_term' => $term->id(),
    ])->setAbsolute()->toString();
    // Strict hexadecimal operation IDs and UUIDs are safe command arguments.
    if (!preg_match('/^[a-f0-9-]{36}$/D', $source->uuid())) {
      throw new \RuntimeException('Invalid source UUID.');
    }
    $data['args'] = $mapping['operation_id'] . ' ' . $source->uuid() . ' ' . $mapping['context_id'];
    return $data;
  }

}
