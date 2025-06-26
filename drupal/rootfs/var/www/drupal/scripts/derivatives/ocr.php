<?php

$action_name = 'get_ocr_from_image';
$sql = "SELECT mo.field_media_of_target_id
  FROM media_field_data m
  INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
  INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
  WHERE m.mid > 1008649
    AND mu.field_media_use_target_id = 16
    AND m.bundle IN ('document')
    AND mo.field_media_of_target_id NOT IN (
      SELECT mo.field_media_of_target_id FROM media_field_data m
      INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
      INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
      WHERE mu.field_media_use_target_id = 14
    )
  GROUP BY mo.field_media_of_target_id";

require_once __DIR__ . "/action.php";
