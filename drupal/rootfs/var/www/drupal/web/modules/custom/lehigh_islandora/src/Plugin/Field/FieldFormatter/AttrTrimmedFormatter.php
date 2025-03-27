<?php

namespace Drupal\lehigh_islandora\Plugin\Field\FieldFormatter;

use Drupal\text\Plugin\Field\FieldFormatter\TextTrimmedFormatter;

/**
 * Plugin implementation of the 'attr_trimmed' formatter.
 *
 * @FieldFormatter(
 *   id = "attr_trimmed",
 *   label = @Translation("Trimmed"),
 *   field_types = {
 *     "textarea_attr"
 *   }
 * )
 */
class AttrTrimmedFormatter extends TextTrimmedFormatter {}
