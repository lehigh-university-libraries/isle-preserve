<?php

$action_name = 'generate_hocr_from_an_image';


// TIFF and JP2s original files
// Less than 100d old
// that do not have an hocr derivative file (tid=44903)
$sql = "SELECT mo.field_media_of_target_id
  FROM media_field_data m
  INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
  INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
  INNER JOIN media__field_media_file mf ON mf.entity_id = mu.entity_id
  INNER JOIN file_managed f ON f.fid = field_media_file_target_id
  WHERE mu.field_media_use_target_id = 16
    AND m.created > UNIX_TIMESTAMP() - 8640000
    AND (uri LIKE '%.tif' OR uri LIKE '%.tiff' OR uri LIKE '%.jp2')
    AND mo.field_media_of_target_id NOT IN (
      SELECT mo.field_media_of_target_id FROM media_field_data m
      INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
      INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
      WHERE mu.field_media_use_target_id = 44903
    )
  GROUP BY mo.field_media_of_target_id";

require_once __DIR__ . "/action.php";
