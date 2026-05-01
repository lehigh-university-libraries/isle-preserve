<?php

namespace Drupal\lehigh_islandora\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\lehigh_islandora\Event\MediaInsertEvent;
use Drupal\lehigh_islandora\Plugin\QueueWorker\IslandoraEventsCron;

/**
 * Subscribes to custom media insert events.
 */
class MediaInsertSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      MediaInsertEvent::NAME => 'onMediaInsert',
    ];
  }

  /**
   * Responds to media insert events.
   *
   * @param \Drupal\lehigh_islandora\Event\MediaInsertEvent $event
   *   The custom media insert event.
   */
  public function onMediaInsert(MediaInsertEvent $event) {
    $media = $event->getMedia();
    if (!$media->hasField('field_media_of') ||
      $media->field_media_of->isEmpty() ||
      is_null($media->field_media_of->entity)) {
      return;
    }

    if (!$media->hasField('field_media_use') ||
      $media->field_media_use->isEmpty() ||
      is_null($media->field_media_use->entity)) {
      return;
    }

    if (!in_array($media->bundle(), ['document', 'file', 'image'])) {
      return;
    }

    $action_name = '';
    foreach ($media->field_media_of as $field_media_of) {
      if (is_null($field_media_of->entity)) {
        continue;
      }

      $node = $field_media_of->entity;
      if ($media->field_media_use->entity->field_external_uri->uri === 'http://vocab.getty.edu/page/aat/300027363') {
        // @todo if zip, parse directory tree and create manifest
      }
      elseif ($media->field_media_use->entity->field_external_uri->uri === 'http://pcdm.org/use#PreservationMasterFile') {
        if (lehigh_islandora_media_is_ms_document($media) &&
          !lehigh_islandora_node_has_parent_generate_pdf_disabled($node)) {
          $action_name = 'microsoft_document_to_pdf';
        }
      }
      elseif ($media->field_media_use->entity->field_external_uri->uri === 'http://pcdm.org/use#OriginalFile') {
        if ($node->hasField('field_add_coverpage') &&
          !$node->field_add_coverpage->isEmpty() &&
          $node->field_add_coverpage->value &&
          $media->bundle() == 'document') {
          $action_name = 'digital_document_add_coverpage';
        }
        // @todo if zip, parse directory tree and create manifest
      }
      elseif ($media->field_media_use->entity->field_external_uri->uri === 'http://pcdm.org/use#ServiceFile') {
        // Bail if the parent node is not a page.
        if (!$node->hasField('field_model') ||
          $node->field_model->isEmpty() ||
          is_null($node->field_model->entity) ||
          $node->field_model->entity->field_external_uri->uri !== 'http://id.loc.gov/ontologies/bibframe/part') {
          continue;
        }

        foreach ($node->field_member_of as $parent) {
          if (is_null($parent->entity)) {
            continue;
          }
          if (!$this->allChildrenWithOriginalFilesHaveServiceFiles($parent->entity->id(), (int) $node->id())) {
            continue;
          }
          IslandoraEventsCron::insertItem('node', $parent->entity->id(), 'paged_content_created_aggregated_pdf', TRUE);
        }
      }

      if ($action_name !== '') {
        $action_storage = \Drupal::entityTypeManager()->getStorage('action');
        $action = $action_storage->load($action_name);
        $action->execute([$node]);
      }
      $action_name = '';
    }
  }

  /**
   * Checks whether all children with original files have service files.
   */
  protected function allChildrenWithOriginalFilesHaveServiceFiles(int $parent_nid, ?int $current_child_nid = NULL) : bool {
    $original_tid = (int) lehigh_islandora_get_tid_by_name('Original File', 'islandora_media_use');
    $service_tid = (int) lehigh_islandora_get_tid_by_name('Service File', 'islandora_media_use');

    $missing = \Drupal::database()->query(
      'SELECT child.entity_id
       FROM {node__field_member_of} child
       WHERE child.field_member_of_target_id = :parent_nid
         AND EXISTS (
           SELECT 1
           FROM {media__field_media_of} mo
           INNER JOIN {media__field_media_use} mu ON mu.entity_id = mo.entity_id
           WHERE mo.field_media_of_target_id = child.entity_id
             AND mu.field_media_use_target_id = :original_tid
         )
         AND (
           child.entity_id <> :current_child_nid
           OR :current_child_nid IS NULL
         )
         AND NOT EXISTS (
           SELECT 1
           FROM {media__field_media_of} mo
           INNER JOIN {media__field_media_use} mu ON mu.entity_id = mo.entity_id
           WHERE mo.field_media_of_target_id = child.entity_id
             AND mu.field_media_use_target_id = :service_tid
         )
       LIMIT 1',
      [
        ':parent_nid' => $parent_nid,
        ':current_child_nid' => $current_child_nid,
        ':original_tid' => $original_tid,
        ':service_tid' => $service_tid,
      ]
    )->fetchField();

    return $missing === FALSE;
  }

}
