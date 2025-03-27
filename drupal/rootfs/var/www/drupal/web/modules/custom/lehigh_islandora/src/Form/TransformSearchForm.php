<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;

/**
 * Provides a Lehigh Islandora form.
 */
final class TransformSearchForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'lehigh_islandora_transform_search';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {

    $form['sentence'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Ask a question'),
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Search'),
      ],
    ];
    $transform = $form_state->get('transform');
    if ($transform !== NULL) {
      $form['results'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['row', 'w-100'],
        ],
        '#title' => $this->t('Results'),
      ];
      $results = \Drupal::database()->query("SELECT *, VEC_DISTANCE_COSINE(vec_fromtext(:vector), embedding) AS distance
        FROM node__embeddings
        ORDER BY VEC_DISTANCE_COSINE(embedding, vec_fromtext(:vector))
        LIMIT 10", [':vector' => $transform]);
      foreach ($results as $row) {
        $nid = $row->entity_id;
        $node = Node::load($nid);
        $form['results'][$nid]['#markup'] = '<div class="card w-50"><h3>' . $node->label() . '</h3>';
        $sentence = \Drupal::database()->query("SELECT sentence
          FROM node__embeddings_chunked
          WHERE entity_id = :nid
          ORDER BY VEC_DISTANCE_COSINE(embedding, vec_fromtext(:vector))
          LIMIT 1", [
            ':nid' => $nid,
            ':vector' => $transform,
          ])->fetchField();
        $form['results'][$nid]['#markup'] .= "<p>" . strip_tags($sentence) . "</p>";
        $form['results'][$nid]['#markup'] .= "<p>Distance: $row->distance</p>";
        $form['results'][$nid]['#markup'] .= '<p><a class="btn btn-primary" href="/node/' . $nid . '">View Item</a></p></div>';
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $sentence = $form_state->getValue('sentence');
    $vectorData = lehigh_islandora_get_vector_data($sentence);
    $form_state->set('transform', $vectorData);
    $form_state->setRebuild();
  }

}
