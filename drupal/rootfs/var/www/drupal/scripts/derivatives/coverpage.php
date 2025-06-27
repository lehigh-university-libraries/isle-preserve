<?php

$action_name = 'digital_document_add_coverpage';

// digital document items
// that have the "add coverpage" field set to true
// that do not have a service file
$sql = "SELECT nmo.entity_id
  FROM node__field_member_of nmo
  INNER JOIN node__field_add_coverpage c ON c.entity_id = nmo.entity_id
  INNER JOIN media__field_media_of mmo ON field_media_of_target_id = nmo.entity_id
  WHERE field_add_coverpage_value = 1
    AND nmo.entity_id NOT IN (
      SELECT field_media_of_target_id FROM media__field_media_use mu
      INNER JOIN media__field_media_of mo ON mo.entity_id = mu.entity_id
      WHERE field_media_use_target_id = 18
    )
  GROUP BY nmo.entity_id";

require_once __DIR__ . "/action.php";
