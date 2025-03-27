<?php

$action_name = 'paged_content_created_aggregated_pdf';
$sql = "SELECT m.entity_id
  FROM node__field_model m
  INNER JOIN node__field_member_of p ON field_member_of_target_id = m.entity_id
  LEFT JOIN media__field_media_of cm ON cm.field_media_of_target_id = p.entity_id
  LEFT JOIN media__field_media_use cmu ON cmu.entity_id = p.entity_id
  LEFT JOIN media__field_media_file i ON i.entity_id = cm.entity_id
  INNER JOIN node_field_data n ON n.nid = field_media_of_target_id
  WHERE n.status = 1
    AND cmu.field_media_use_target_id = 18
    AND field_model_target_id = 27
    AND m.entity_id NOT IN (
      SELECT field_media_of_target_id FROM media__field_media_of mo
      INNER JOIN media__field_media_use mu ON mu.entity_id = mo.entity_id
      WHERE field_media_use_target_id = 16
    )
  GROUP BY m.entity_id";

require_once __DIR__ . "/action.php";
