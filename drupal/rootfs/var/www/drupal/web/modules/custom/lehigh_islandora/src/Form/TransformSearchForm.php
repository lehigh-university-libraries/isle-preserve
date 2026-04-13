<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Lehigh Islandora form.
 */
final class TransformSearchForm extends FormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The node storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $nodeStorage;

  /**
   * Constructs the form.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityStorageInterface $node_storage
   *   The node storage.
   */
  public function __construct(Connection $database, EntityStorageInterface $node_storage) {
    $this->database = $database;
    $this->nodeStorage = $node_storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager')->getStorage('node')
    );
  }

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
      $results = $this->database->query("SELECT *, VEC_DISTANCE_COSINE(vec_fromtext(:vector), embedding) AS distance
        FROM node__embeddings
        ORDER BY VEC_DISTANCE_COSINE(embedding, vec_fromtext(:vector))
        LIMIT 10", [':vector' => $transform]);
      foreach ($results as $row) {
        $nid = $row->entity_id;
        $node = $this->nodeStorage->load($nid);
        $form['results'][$nid]['#markup'] = '<div class="card w-50"><h3>' . $node->label() . '</h3>';
        $sentence = $this->database->query("SELECT sentence
          FROM node__embeddings_chunked
          WHERE entity_id = :nid
          ORDER BY VEC_DISTANCE_COSINE(embedding, vec_fromtext(:vector))
          LIMIT 1", [
            ':nid' => $nid,
            ':vector' => $transform,
          ])->fetchField();
        if ($sentence) {
          $form['results'][$nid]['#markup'] .= "<p>" . strip_tags($sentence) . "</p>";
        }
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
