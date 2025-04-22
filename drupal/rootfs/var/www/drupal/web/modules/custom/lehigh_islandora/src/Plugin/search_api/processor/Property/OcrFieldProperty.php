<?php

namespace Drupal\lehigh_islandora\Plugin\search_api\processor\Property;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TypedData\ComplexDataDefinitionBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\search_api\Processor\ProcessorPropertyInterface;

/**
 * HOCR Field property data definition.
 */
class OcrFieldProperty extends ComplexDataDefinitionBase implements ProcessorPropertyInterface {

  use StringTranslationTrait;

  /**
   * {@inheritDoc}
   */
  public function getPropertyDefinitions() {
    if (empty($this->propertyDefinitions)) {
      $this->propertyDefinitions = [
        'content' => new ProcessorProperty([
          'label' => $this->t('OCR Content Field'),
          'description' => $this->t('OCR content from referenced media.'),
          'type' => 'string',
          'processor_id' => $this->getProcessorId(),
          'is_list' => FALSE,
          'computed' => FALSE,
        ]),
      ];
    }

    return $this->propertyDefinitions;
  }

  /**
   * {@inheritdoc}
   */
  public function getProcessorId() {
    return $this->definition['processor_id'];
  }

  /**
   * {@inheritdoc}
   */
  public function isHidden() {
    return !empty($this->definition['hidden']);
  }

  /**
   * {@inheritdoc}
   */
  public function isList() {
    return (bool) ($this->definition['is_list'] ?? parent::isList());
  }

}
