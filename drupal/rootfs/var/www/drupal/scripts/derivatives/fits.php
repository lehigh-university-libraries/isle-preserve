<?php

$action_name = 'generate_a_technical_metadata_derivative';

// any item
// that has original field media
// that does not have a a FITS file
$sql = "SELECT DISTINCT mo.field_media_of_target_id
FROM media__field_media_of mo
JOIN media__field_media_use mu ON mo.entity_id = mu.entity_id
WHERE mu.field_media_use_target_id = 16
  AND NOT EXISTS (
    SELECT 1
    FROM media__field_media_of mo2
    JOIN media__field_media_use mu2 ON mo2.entity_id = mu2.entity_id
    WHERE mo2.field_media_of_target_id = mo.field_media_of_target_id
      AND mu2.field_media_use_target_id = 32
  )";

require_once __DIR__ . "/action.php";
