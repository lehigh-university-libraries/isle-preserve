<?php

namespace Drupal\islandora_scribe\Plugin\Action;

use Drupal\Core\Form\FormStateInterface;

/**
 * Enqueues public paged-content transcription.
 *
 * @Action(
 *   id = "transcribe_scribe_paged_content",
 *   label = @Translation("Transcribe paged content using Scribe"),
 *   type = "node"
 * )
 */
final class TranscribePagedContent extends BookAction {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['context_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Scribe context ID'),
      '#min' => 1,
      '#step' => 1,
      '#required' => TRUE,
      '#default_value' => $this->configuration['context_id'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    if (filter_var($form_state->getValue('context_id'), FILTER_VALIDATE_INT, [
      'options' => [
        'min_range' => 1,
      ],
    ]) === FALSE) {
      $form_state->setErrorByName('context_id', $this->t('Select a positive Scribe context ID.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['context_id'] = (int) $form_state->getValue('context_id');
  }

}
