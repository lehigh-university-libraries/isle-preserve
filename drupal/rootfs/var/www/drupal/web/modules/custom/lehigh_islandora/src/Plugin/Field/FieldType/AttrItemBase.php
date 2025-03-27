<?php

declare(strict_types=1);

namespace Drupal\lehigh_islandora\Plugin\Field\FieldType;

use Drupal\Component\Utility\Random;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin base class inherited by the attr field types.
 */
abstract class AttrItemBase extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    $value = $this->get('value')->getValue();
    return empty($value);
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings() {
    return [
      'attr0_name' => '',
      'attr0_values' => [],
      'attr1_name' => '',
      'attr1_values' => [],
    ] + parent::defaultFieldSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSettingsForm(array $form, FormStateInterface $form_state) {
    $element = parent::fieldSettingsForm($form, $form_state);

    for ($key = 0; $key < 2; $key++) {
      $element['attr' . $key . '_name'] = [
        '#type' => 'textfield',
        '#title' => 'Attribute ' . ($key + 1) . ' label',
        '#required' => $key == 0,
        '#default_value' => $this->getSetting('attr' . $key . '_name'),
      ];
      $element['attr' . $key . '_values'] = [
        '#type' => 'checkboxes',
        '#title' => 'Attribute ' . ($key + 1) . ' allowed attribute',
        '#multiple' => TRUE,
        '#default_value' => $this->getSetting('attr' . $key . '_values'),
        // @todo make this list editable in the UI
        '#options' => self::possibleValues(),
        '#required' => $key == 0,
        '#states' => [
          'invisible' => [
            '#edit-settings-attr' . $key . '-name' => ['value' => ''],
          ],
          'required' => [
            '#edit-settings-attr' . $key . '-name' => ['!value' => ''],
          ],
        ],
      ];
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function getConstraints(): array {
    $constraints = parent::getConstraints();

    return $constraints;
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition): array {

    $random = new Random();

    $values['attr0'] = array_rand(self::allowedAttributeOneValues());

    $values['attr1'] = array_rand(self::allowedAttributeTwoValues());

    $values['value'] = $random->paragraphs(5);

    $values['format'] = array_rand(self::allowedFormatValues());

    return $values;
  }

  /**
   * Returns allowed values for 'attr0' sub-field.
   */
  public function allowedAttributeOneValues(): array {
    return array_filter($this->getSetting('attr0_values'));
  }

  /**
   * Returns allowed values for 'attr1' sub-field.
   */
  public function allowedAttributeTwoValues(): array {
    return array_filter($this->getSetting('attr1_values'));
  }

  /**
   * Returns allowed values for 'attr0' sub-field.
   */
  public function attributeOneName(): string {
    return $this->getSetting('attr0_name');
  }

  /**
   * Returns possible values for 'attr' sub-fields.
   */
  public function possibleValues(): array {
    return [
      "abstract" => "Abstract",
      "accompanying-material" => "Accompanying Material",
      "activity" => "Activity",
      "annotation" => "Annotation",
      "arxiv" => "arXiv ID",
      "audio-file" => "Audio File",
      "awards" => "Awards",
      "barcode" => "Barcode",
      "bepress-identifier" => "bepress-identifier",
      "beyond-steel-category" => "beyond-steel-category",
      "box" => "box",
      "call-number" => "Call Number",
      "caption" => "Caption",
      "capture-device" => "Capture Device",
      "category" => "Category",
      "coach" => "Coach",
      "code" => "Code",
      "codes" => "Codes",
      "comments" => "Comments",
      "date" => "Date",
      "description" => "Description",
      "digital-publisher" => "Digital Publisher",
      "doi" => "DOI",
      "email" => "Email",
      "file-name" => "File Name",
      "folder" => "Folder",
      "gender" => "Gender",
      "hierarchy" => "Hierarchy",
      "illustration" => "Illustration",
      "institution" => "Institution",
      "islandora" => "islandora",
      "item-number" => "Item Number",
      "l-issn" => "ISSN",
      "local" => "Local",
      "musical-works" => "Musical Works",
      "note" => "Note",
      "oclc" => "OCLC",
      "orcid" => "ORCiD",
      "original-coloration-size-support" => "Original Coloration, size, and support",
      "performance-date" => "Performance Date",
      "permission-type" => "Permission Type (staff or submitted)",
      "ppi" => "ppi",
      "preferred-citation" => "Preferred Citation",
      "printed" => "Printed",
      "program" => "Program",
      "project-source" => "Project Source",
      "reference" => "Reference",
      "report-number" => "Report Number",
      "season" => "Season",
      "series" => "Series",
      "staff" => "Staff",
      "subfolder" => "Subfolder",
      "subject-type" => "Subject Type",
      "table" => "Table",
      "text" => "Text",
      "transcription-file" => "Transcription File",
      "uri" => "URI",
      "use-and-reproduction" => "Use and reproduction",
      "year" => "Year",
      "start" => "Start",
      "end" => "End",
      "bytes" => "Bytes",
      "dimensions" => "Dimensions",
      "minutes" => "Minutes",
      "page" => "Page",
    ];
  }

  /**
   * Returns allowed values for 'attr1' sub-field.
   */
  public function attributeTwoName(): string {
    return $this->getSetting('attr1_name');
  }

  /**
   * Returns allowed values for 'format' sub-field.
   */
  public static function allowedFormatValues(): array {
    // @todo set dynamically
    return [
      'basic_html' => t('Basic HTML'),
      'mathjax' => t('MathJAX'),
      'full_html' => t('Full HTML'),
      'restricted_html' => t('Restricted HTML'),
    ];
  }

}
