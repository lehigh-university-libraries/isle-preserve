<?php

$action_name = 'generate_a_technical_metadata_derivative';
$sql = "SELECT mo.field_media_of_target_id
  FROM media__field_media_of mo
  INNER JOIN media__field_media_use mu ON mo.entity_id = mu.entity_id
  INNER JOIN node_field_data n ON n.nid = field_media_of_target_id
  WHERE mu.field_media_use_target_id = 16
    AND n.status = 1
    AND mo.field_media_of_target_id NOT IN (
      SELECT mo.field_media_of_target_id FROM media_field_data m
      INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
      INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
      WHERE mu.field_media_use_target_id = 32
    )
  GROUP BY n.nid";

require_once __DIR__ . "/action.php";
