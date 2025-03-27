<?php

// from https://github.com/discoverygarden/akubra_adapter/blob/e887885abc4d1f9fd5df47d072105f447e7e67fe/src/Utility/Fedora3/AkubraLowLevelAdapter.php#L37-L58
function dereference($id) : string {
  // Structure like: "the:pid+DSID+DSID.0"
  // Need: "{base_path}/{hash_pattern}/{id}".
  // @see https://github.com/fcrepo3/fcrepo/blob/37df51b9b857fd12c6ab8269820d406c3c4ad774/fcrepo-server/src/main/java/org/fcrepo/server/storage/lowlevel/akubra/HashPathIdMapper.java#L17-L68
  $slashed = str_replace('+', '/', $id);
  $full = "info:fedora/$slashed";
  $hash = md5($full);

  $pattern_offset = 0;
  $hash_offset = 0;
  $subbed = "##";

  while (($pattern_offset = strpos($subbed, '#', $pattern_offset)) !== FALSE) {
    $subbed[$pattern_offset] = $hash[$hash_offset++];
  }

  $encoded = strtr(rawurlencode($full), [
    '_' => '%5F',
  ]);

  return "$subbed/$encoded";
}

$csvFile = 'pids.csv';

$OBJ_DIR="/opt/islandora/fedora-objectStore";
echo "node_id,field_weight\n";
if (($handle = fopen($csvFile, 'r')) !== FALSE) {
  while (($row = fgetcsv($handle, null, "\t")) !== FALSE) {
    $nid = $row[0];
    $pid = $row[1];

    $xml_file = $OBJ_DIR . '/' . dereference($pid);
    if (file_exists($xml_file)) {
      $xml = file_get_contents($xml_file);
      $pattern = '/<islandora:isSequenceNumber>(.*?)<\/islandora:isSequenceNumber>/s';
      preg_match($pattern, $xml, $matches);
      $isSequenceNumber = isset($matches[1]) ? $matches[1] : '';
      if ($isSequenceNumber) {
        echo $nid,",",$isSequenceNumber,"\n";
      }
    }
  }
}
