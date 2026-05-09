<?php

$action_name = 'generate_a_jp2_service_file';

// images that do not have a service file
$sql = "SELECT DISTINCT mo.field_media_of_target_id
  FROM media__field_media_use mu
  INNER JOIN media__field_media_of mo
    ON mo.entity_id = mu.entity_id
  LEFT JOIN media__field_media_image mfi
    ON mfi.entity_id = mu.entity_id
  LEFT JOIN media__field_media_file mff
    ON mff.entity_id = mu.entity_id
  LEFT JOIN file_managed f
    ON f.fid = mff.field_media_file_target_id
  WHERE mu.field_media_use_target_id = 16
    AND (
      mfi.field_media_image_target_id IS NOT NULL
      OR (
        mff.field_media_file_target_id IS NOT NULL
        AND (
          f.filemime LIKE 'image/%'
          OR f.uri LIKE '%.tif'
          OR f.uri LIKE '%.tiff'
          OR f.uri LIKE '%.jp2'
        )
      )
    )
    AND NOT EXISTS (
      SELECT 1
      FROM media__field_media_of mo18
      INNER JOIN media__field_media_use mu18
        ON mu18.entity_id = mo18.entity_id
      WHERE mo18.field_media_of_target_id = mo.field_media_of_target_id
        AND mu18.field_media_use_target_id = 18
    )";

require_once __DIR__ . "/action.php";
