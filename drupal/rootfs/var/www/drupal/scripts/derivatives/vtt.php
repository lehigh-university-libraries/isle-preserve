<?php

$action_name = 'get_vtt_from_audio';
$sql = "SELECT field_media_of_target_id
  FROM media__field_media_of media_of
  INNER JOIN media__field_media_use u ON u.entity_id = media_of.entity_id
  INNER JOIN node_field_data n ON n.nid = field_media_of_target_id
  WHERE n.status = 1
    AND media_of.bundle IN ('audio', 'video')
    AND field_media_use_target_id = 16
    AND field_media_of_target_id NOT IN (
      SELECT mo.field_media_of_target_id FROM media__field_media_of mo
      INNER JOIN media__field_media_use mu ON mo.entity_id = mu.entity_id
      WHERE mu.field_media_use_target_id = 14
    )
  GROUP BY n.nid";

require_once __DIR__ . "/action.php";
