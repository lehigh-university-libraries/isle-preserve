<?php

use Drupal\node\Entity\Node;

$entity_type_manager = \Drupal::entityTypeManager();
$node_storage   = $entity_type_manager->getStorage('node');
$sql = "SELECT nid, f.uri FROM node_field_data n
  INNER JOIN node__field_model m ON n.nid = m.entity_id
  INNER JOIN media__field_media_of mo ON field_media_of_target_id = n.nid
  INNER JOIN media__field_media_use mu ON mo.entity_id = mu.entity_id AND field_media_use_target_id = 14
  INNER JOIN media__field_media_file mf ON mf.entity_id = mu.entity_id
  INNER JOIN file_managed f ON f.fid = field_media_file_target_id
  WHERE field_model_target_id <> :pc
    AND nid IN (select COALESCE(gggc.entity_id, ggc.entity_id, gc.entity_id, c.entity_id) from node__field_member_of p
      left join node__field_member_of c ON c.field_member_of_target_id = p.entity_id
      left join node__field_member_of gc ON gc.field_member_of_target_id = c.entity_id
      left join node__field_member_of ggc ON ggc.field_member_of_target_id = gc.entity_id
      left join node__field_member_of gggc ON gggc.field_member_of_target_id = ggc.entity_id
      where p.field_member_of_target_id IN (116,128,445975,74,42,150,99))
    AND nid NOT IN (SELECT entity_id from node__embeddings)";
$d_args = [
  ':pc' => lehigh_islandora_get_tid_by_name("Paged Content", "islandora_models")
];
$nids = \Drupal::database()->query($sql, $d_args)->fetchAllKeyed();
if (count($nids) == 0) {
  exit(0);
}

$fs = \Drupal::service('file_system');
$db = \Drupal::database();
foreach ($nids as $nid => $uri) {
  $node = Node::load($nid);
  if (!$node) continue;

  $sentences = [
    $node->label(),
  ];

  if (!$node->field_abstract->isEmpty()) {
    $sentences = array_merge($sentences, explode("\n", wordwrap($node->field_abstract->value, 255)));
  }

  foreach ($node->field_member_of as $parent) {
    $sentences[] = $parent->entity->label();
  }
  $term_fields = [
    'field_linked_agent',
    'field_geographic_subject',
    'field_subject_general',
    'field_subject_lcsh',
    'field_subjects_name'
  ];
  foreach ($term_fields as $field) {
    foreach ($node->get($field) as $term) {
      if (empty($term->entity)) continue;
      $sentences[] = $term->entity->label();
    }
  }

  if (!is_null($uri)) {
    $ocr = $fs->realpath($uri);
    if (file_exists($ocr)) {
      $content = file_get_contents($ocr);
      // get rid of new lines
      $content = preg_replace('/[\r\n]+/', ' ', $content);
      // get rid of junk characters
      $content = preg_replace('/[^\w ]+/', '', $content);
      $sentences = array_merge($sentences, explode("\n", wordwrap($content, 255)));
    }
  }

  $sentence = implode(" ", $sentences);
  $vectorData = lehigh_islandora_get_vector_data($sentence);
  $db->query("INSERT INTO node__embeddings(entity_id, changed, embedding)
    VALUES (:nid, :time, vec_fromtext(:vector))", [
      ':nid' => $nid,
      ':time' => time(),
      ':vector' => $vectorData,
    ]
  );

  if ($db->query("SELECT entity_id from node__embeddings_chunked WHERE entity_id = :nid LIMIT 1", [':nid' => $nid])->fetchField()) {
    continue;
  }
  foreach ($sentences as $delta => $sentence) {
    if (trim($sentence) == "") continue;
    $vectorData = lehigh_islandora_get_vector_data($sentence);
    $db->query("INSERT INTO node__embeddings_chunked(entity_id, delta, changed, sentence, embedding)
      VALUES (:nid, :delta, :time, :sentence, vec_fromtext(:vector))", [
        ':nid' => $nid,
        ':delta' => $delta,
        ':time' => time(),
        ':sentence' => $sentence,
        ':vector' => $vectorData,
      ]
    );
  }

}
