<?php

$action_name = 'paged_content_created_aggregated_pdf';

// paged content items
// that have one child
// created more than one day ago (to give children time to process)
// that do not have original files
$sql = "SELECT m.entity_id
  FROM node__field_model m
  INNER JOIN node__field_member_of mo ON field_member_of_target_id = m.entity_id
  INNER JOIN node_field_data n ON n.nid = m.entity_id
  WHERE field_model_target_id = 27
    AND n.created < UNIX_TIMESTAMP() - 3600
    AND m.entity_id NOT IN (
      SELECT field_media_of_target_id FROM media__field_media_of mo
      INNER JOIN media__field_media_use mu ON mu.entity_id = mo.entity_id
      WHERE field_media_use_target_id = 16
    )
  GROUP BY m.entity_id";

require_once __DIR__ . "/action.php";
