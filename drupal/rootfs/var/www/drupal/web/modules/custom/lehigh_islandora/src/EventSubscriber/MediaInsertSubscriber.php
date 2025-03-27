<?php

namespace Drupal\lehigh_islandora\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\lehigh_islandora\Event\MediaInsertEvent;

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
        if (lehigh_islandora_media_is_ms_document($media)) {
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

          // See if all service files have been created
          // for the parent paged content item.
          $readyToAggregate = \Drupal::database()->query('SELECT m.entity_id
            FROM node__field_model m
            INNER JOIN node__field_member_of c ON field_member_of_target_id = m.entity_id
            INNER JOIN media__field_media_of cm ON cm.field_media_of_target_id = c.entity_id
            INNER JOIN media__field_media_use cmu ON cmu.entity_id = cm.entity_id
            WHERE m.entity_id = :nid
              AND cmu.field_media_use_target_id = :service
              AND field_model_target_id = :paged
            GROUP BY m.entity_id HAVING COUNT(*) = (
              SELECT COUNT(*)
                FROM node__field_model m
                INNER JOIN node__field_member_of c ON field_member_of_target_id = m.entity_id
                INNER JOIN media__field_media_of cm ON cm.field_media_of_target_id = c.entity_id
                INNER JOIN media__field_media_use cmu ON cmu.entity_id = cm.entity_id
                WHERE m.entity_id = :nid
                  AND cmu.field_media_use_target_id = :original
                  AND field_model_target_id = :paged
            )
            LIMIT 1', [
              ':nid' => $parent->entity->id(),
              ':original' => lehigh_islandora_get_tid_by_name('Original File', 'islandora_media_use'),
              ':service' => lehigh_islandora_get_tid_by_name('Service File', 'islandora_media_use'),
              ':paged' => lehigh_islandora_get_tid_by_name('Paged Content', 'islandora_models'),
            ])->fetchField();
          if ($readyToAggregate) {
            lehigh_islandora_clear_disk_cache($parent->entity);
            $action_storage = \Drupal::entityTypeManager()->getStorage('action');
            $action = $action_storage->load('paged_content_created_aggregated_pdf');
            $action->execute([$parent->entity]);
          }
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

}
