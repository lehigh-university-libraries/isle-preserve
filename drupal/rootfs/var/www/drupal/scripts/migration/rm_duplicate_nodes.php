<?php

use Drupal\Core\Session\UserSession;
use Drupal\user\Entity\User;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;

$result = \Drupal::database()->query('SELECT GROUP_CONCAT(entity_id) AS nids, field_pid_value
  FROM {node__field_pid}
  GROUP BY field_pid_value HAVING COUNT(*) > 1');

$userid = 1;
$account = User::load($userid);
$accountSwitcher = Drupal::service('account_switcher');
$userSession = new UserSession([
  'uid'   => $account->id(),
  'name'  => $account->getDisplayName(),
  'roles' => $account->getRoles(),
]);
$accountSwitcher->switchTo($userSession);

$media_storage = \Drupal::service('entity_type.manager')->getStorage('media');
$node_storage = \Drupal::service('entity_type.manager')->getStorage('node');
foreach ($result as $row) {
  $nids = explode(',', $row->nids);
  foreach ($nids as $nid) {
    // don't delete nodes with children
    if (\Drupal::database()->query('SELECT entity_id FROM node__field_member_of m
                                    WHERE field_member_of_target_id = :nid', [
                                      ':nid' => $nid
                                    ])->fetchField()) {
      continue;
    }

    $node = Node::load($nid);
    if (!$node) {
      continue;
    }
    $mids = \Drupal::database()->query('SELECT entity_id FROM media__field_media_of
                                        WHERE field_media_of_target_id = :nid', [
                                          ':nid' => $nid
                                        ])->fetchCol();
    foreach ($mids as $mid) {
      $media = Media::load($mid);
      if (!$media) {
        continue;
      }
    
      if ($media->bundle() == 'file') {
        if (!$media->field_media_file->isEmpty() && !is_null($media->field_media_file->entity)) {
          $media->field_media_file->entity->delete();
        }
      }
      elseif ($media->bundle() == 'image') {
        if (!$media->field_media_image->isEmpty() && !is_null($media->field_media_image->entity)) {
          $media->field_media_image->entity->delete();
        }
      }

      elseif ($media->bundle() == 'audio') {
        if (!$media->field_media_audio_file->isEmpty() && !is_null($media->field_media_audio_file->entity)) {
          $media->field_media_audio_file->entity->delete();
        }
      }

      elseif ($media->bundle() == 'video') {
        if (!$media->field_media_video_file->isEmpty() && !is_null($media->field_media_video_file->entity)) {
          $media->field_media_video_file->entity->delete();
        }
      }

      elseif ($media->bundle() == 'document') {
        if (!$media->field_media_document->isEmpty() && !is_null($media->field_media_document->entity)) {
          $media->field_media_document->entity->delete();
        }
      }
      $media->delete();
    }

    $node->delete();
    break;
  }
}
