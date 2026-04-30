<?php

/**
 * Drush script to set field_degree_name on nodes.
 *
 * Usage:
 *   drush scr update_degree_names.php
 */

use Drupal\node\Entity\Node;

$rows = [
  [485200, 'Population Health'],
  [485205, 'Supply Chain Management'],
  [485210, 'Financial Engineering'],
  [485215, 'Computer Science'],
  [485220, 'Population Health'],
  [485225, 'Environmental Engineering'],
  [485230, 'Biochemistry'],
  [485235, 'Bioengineering'],
  [485240, 'Environmental Engineering'],
  [485245, 'Psychology'],
  [485250, 'Computer Science'],
  [485255, 'Architecture'],
  [485260, 'Biochemistry'],
  [485265, 'Biology'],
  [485270, 'Molecular Biology'],
  [485275, 'IDEAS: Civil Engineering and Global Studies'],
  [485280, 'Bioengineering'],
  [485285, 'International Relations and Economics'],
  [485290, 'Chemical Engineering'],
  [485295, 'Population Health'],
  [485300, 'Computer Science'],
  [485305, 'Undeclared'],
  [485310, 'Psychology and HMS'],
  [485315, 'Biochemistry'],
  [485320, 'Industrial Engineering and IBE Finance'],
  [485325, 'Finance'],
  [485330, 'Sociology and Anthropology'],
  [485335, 'Civil Engineering'],
  [485340, 'Political Science'],
  [485345, 'Psychology and Sociology'],
  [485349, 'Bioengineering'],
  [485354, 'Population Health'],
  [485359, 'Chemical Engineering'],
  [485364, 'Computer Engineering'],
  [485369, 'Computer Science & Engineering'],
  [485374, 'Marketing and Psychology'],
  [485384, 'Majors: Global Studies and Political Science'],
  [485389, 'IDEAS (Computer Science, Graphic Design), Global Studies'],
  [485399, 'Computer Science and Business'],
  [485404, 'Behavioral Neuroscience'],
  [485409, 'Anthropology & Psychology'],
  [485414, 'Cognitive Science'],
  [485419, 'English and Theatre'],
  [485424, 'Political Science and Sociology'],
  [485429, 'Business Management'],
  [485434, 'Political Science & Africana Studies'],
  [485439, 'IDEAS'],
  [485444, 'Architecture'],
  [485449, 'Design Major'],
  [485454, 'Biology and Health, Medicine & Society'],
  [485459, 'Chemical Engineering'],
  [485464, 'Major: Bioengineering'],
  [485469, 'Psychology'],
  [485474, 'Molecular Biology'],
  [485479, 'Health, Medicine, & Society & Biology'],
  [485484, 'Supply Chain'],
  [485489, 'Electrical Engineering'],
  [485494, 'Mechanical Engineering'],
];

foreach ($rows as $row) {
  [$nid, $degree_name] = $row;

  $node = Node::load($nid);
  if (!$node) {
    continue;
  }

  try {
    $tid = lehigh_islandora_get_tid_by_name($degree_name, 'degree_name', TRUE);

    if (empty($tid)) {
      continue;
    }

    $node->set('field_degree_name', ['target_id' => $tid]);
    $node->save();
  }
  catch (\Exception $e) {
  }
}
