<?php

$action_name = 'digital_document_add_coverpage';
$sql = "SELECT n.nid
  FROM node__field_member_of nmo
  INNER JOIN node__field_add_coverpage c ON c.entity_id = nmo.entity_id
  INNER JOIN media__field_media_of mmo ON field_media_of_target_id = nmo.entity_id
  INNER JOIN node_field_data n ON n.nid = field_media_of_target_id
  WHERE n.status = 1
    AND field_add_coverpage_value = 1
    AND nmo.entity_id NOT IN (
      SELECT field_media_of_target_id FROM media__field_media_use mu
      INNER JOIN media__field_media_of mo ON mo.entity_id = mu.entity_id
      WHERE field_media_use_target_id = 18
    )
  GROUP BY n.nid";

require_once __DIR__ . "/action.php";
