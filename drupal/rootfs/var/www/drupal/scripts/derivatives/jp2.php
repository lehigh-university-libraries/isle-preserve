<?php

$action_name = 'generate_a_jp2_service_file';
$sql = "SELECT mo.field_media_of_target_id
  FROM media_field_data m
  INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
  INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
  INNER JOIN media__field_mime_type mt ON m.mid = mt.entity_id
  INNER JOIN node_field_data n ON n.nid = field_media_of_target_id
  WHERE n.status = 1
    AND mu.field_media_use_target_id = 16
    AND m.bundle IN ('file')
    AND field_mime_type_value IN ('image/tiff', 'image/jp2')
    AND mo.field_media_of_target_id NOT IN (
      SELECT mo.field_media_of_target_id FROM media_field_data m
      INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
      INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
      WHERE mu.field_media_use_target_id = 18
    )
  GROUP BY n.nid";

require_once __DIR__ . "/action.php";
