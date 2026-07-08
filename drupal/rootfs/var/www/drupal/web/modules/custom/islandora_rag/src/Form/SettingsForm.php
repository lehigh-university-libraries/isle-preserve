<?php

declare(strict_types=1);

namespace Drupal\islandora_rag\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Islandora RAG semantic indexing.
 */
final class SettingsForm extends ConfigFormBase {

  private const SETTINGS = 'islandora_rag.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'islandora_rag_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::SETTINGS];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::SETTINGS);

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable semantic indexing'),
      '#default_value' => $config->get('enabled'),
    ];
    $form['embedding_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Embedding model'),
      '#default_value' => $config->get('embedding_model'),
    ];
    $form['embedding_dimension'] = [
      '#type' => 'number',
      '#title' => $this->t('Embedding dimension'),
      '#description' => $this->t('Must match the DenseVectorField dimension in the vector core schema.'),
      '#default_value' => $config->get('embedding_dimension'),
    ];
    $form['solr_core'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Solr vector core name'),
      '#default_value' => $config->get('solr_core'),
    ];
    $form['max_chunks_per_node'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum chunks per node'),
      '#description' => $this->t('Hard cap to prevent unusually large OCR objects from monopolizing the embedding service. Use 0 for no cap.'),
      '#default_value' => $config->get('max_chunks_per_node') ?? 2000,
      '#min' => 0,
    ];
    $form['indexed_collections'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Indexed collection node IDs'),
      '#description' => $this->t('One node ID per line. Leave empty to index all published Islandora objects.'),
      '#default_value' => implode("\n", (array) $config->get('indexed_collections')),
    ];

    $chunk = (array) $config->get('chunk');
    $form['chunk'] = [
      '#type' => 'details',
      '#title' => $this->t('Chunking'),
      '#open' => FALSE,
      '#tree' => TRUE,
    ];
    $chunk_labels = [
      'target_tokens' => 'Target tokens',
      'overlap_tokens' => 'Overlap tokens',
      'max_tokens' => 'Max tokens',
      'min_tokens' => 'Min tokens',
    ];
    foreach ($chunk_labels as $key => $label) {
      $form['chunk'][$key] = [
        '#type' => 'number',
        '#title' => $this->t('@label', ['@label' => $label]),
        '#default_value' => $chunk[$key] ?? NULL,
      ];
    }

    $form['embedding_service_url'] = [
      '#type' => 'item',
      '#title' => $this->t('Embedding service URL'),
      '#markup' => getenv('EMBEDDING_SERVICE_URL') ?: $this->t('(EMBEDDING_SERVICE_URL not set)'),
      '#description' => $this->t('Configured via the EMBEDDING_SERVICE_URL environment variable.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $collections = array_values(array_filter(array_map(
      static fn(string $line): int => (int) trim($line),
      preg_split('/\R/', (string) $form_state->getValue('indexed_collections')) ?: [],
    )));

    $this->config(self::SETTINGS)
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('embedding_model', (string) $form_state->getValue('embedding_model'))
      ->set('embedding_dimension', (int) $form_state->getValue('embedding_dimension'))
      ->set('solr_core', (string) $form_state->getValue('solr_core'))
      ->set('max_chunks_per_node', (int) $form_state->getValue('max_chunks_per_node'))
      ->set('indexed_collections', $collections)
      ->set('chunk', array_map('intval', (array) $form_state->getValue('chunk')))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
