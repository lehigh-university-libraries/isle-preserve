<?php

use Drupal\field\Entity\FieldConfig;
use Drupal\node\Entity\Node;

$csvFile = '/var/www/drupal/scripts/import/update.csv';
$file = fopen($csvFile, 'r');

$fieldNames = fgetcsv($file);
array_shift($fieldNames);

$fieldDefinitions = [];
foreach ($fieldNames as $fieldName) {
  $fieldDefinitions[$fieldName] = FieldConfig::loadByName('node', 'islandora_object', $fieldName);
}

$vocabularies = [];
while (($row = fgetcsv($file)) !== false) {
  $nid = array_shift($row);
  $node = Node::load($nid);

  foreach ($row as $index => $value) {
    if ($value == "") {
      continue;
    }
    $fieldName = $fieldNames[$index];
    if (isset($fieldDefinitions[$fieldName])) {
      $newValue = [];
      foreach (explode('|', $value) as $v) {
        $field_type = $fieldDefinitions[$fieldName]->getType();
        switch ($field_type) {
          case "entity_reference":
            if (!is_integer($v)) {
              $entity_reference = $fieldDefinitions[$fieldName]->getFieldStorageDefinition()->getSettings()['target_type'];
              if ($entity_reference == "taxonomy_term") {
                if (!isset($vocabularies[$fieldName])) {
                  $vocabularies[$fieldName] = array_shift($fieldDefinitions[$fieldName]->getSettings()['handler_settings']['target_bundles']);
                }
                $newValue[] = lehigh_islandora_get_tid_by_name($v, $vocabularies[$fieldName], TRUE);
              }
              else {
                continue 2;
              }
            }
            break;
          case "textarea_attr":
          case "textfield_attr":
            $pattern = '/attr0:([a-z\-]+):(.+)/';
            if (preg_match($pattern, $v, $matches)) {
              $newValue[] = [
                'attr0' => $matches[1],
                'value' => $matches[2],
              ];
            }
            else {
                $newValue[] = $v;
            }
            break;
          default:
            $newValue[] = $v;
        }
      }
      $node->{$fieldName}->setValue($newValue);
    }
  }
  $node->save();
  echo date("Y-m-d\TH:i:s"), " updated ", $node->id(), "\n";
}

fclose($file);
