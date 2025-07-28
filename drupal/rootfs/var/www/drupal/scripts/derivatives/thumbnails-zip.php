<?php

$action_name = 'zip_generate_a_thumbnail_from_an_original_file';

$sql = "SELECT mo.field_media_of_target_id
  FROM media_field_data m
  INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
  INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
  INNER JOIN media__field_mime_type mt ON m.mid = mt.entity_id
  INNER JOIN node_field_data n ON n.nid = field_media_of_target_id
  WHERE mu.field_media_use_target_id = 16
    AND field_mime_type_value IN ('application/zip')
    AND mo.field_media_of_target_id NOT IN (
      SELECT mo.field_media_of_target_id FROM media_field_data m
      INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
      INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
      WHERE mu.field_media_use_target_id = 19
    )
  GROUP BY mo.field_media_of_target_id";

require_once __DIR__ . "/action.php";
