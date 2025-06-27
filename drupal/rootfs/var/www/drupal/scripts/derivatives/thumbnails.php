<?php

$action_name = 'image_generate_a_thumbnail_from_an_original_file';

// Media that are not audio or videos
// i.e. mostly pdfs and images
// that do not have a thumbnail
$sql = "SELECT mo.field_media_of_target_id
  FROM media_field_data m
  INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
  INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
  INNER JOIN node_field_data n ON n.nid = field_media_of_target_id
  WHERE mu.field_media_use_target_id = 16
    AND mo.bundle NOT IN ('audio', 'video')
    AND mo.field_media_of_target_id NOT IN (
      SELECT mo.field_media_of_target_id FROM media_field_data m
      INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
      INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
      WHERE mu.field_media_use_target_id = 19
    )
  GROUP BY mo.field_media_of_target_id";

require_once __DIR__ . "/action.php";
