<?php

/**
 * @file
 * Contains lehigh_islandora.tokens.inc.
 */

use Drupal\controlled_access_terms\EDTFUtils;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\media\Entity\Media;

/**
 * Implements hook_token_info_alter().
 */
function lehigh_islandora_token_info_alter(&$data) {
  $data['tokens']['node']['lehigh_islandora_issued_ymd'] = [
    'name' => t("Issued Date YYYY/m/d"),
    'description' => t("This node's issue date in Google Scholar's desired format."),
  ];
  $data['tokens']['node']['lehigh_islandora_authors'] = [
    'name' => t("Author(s)"),
    'description' => t("The linked agent author(s)."),
  ];
  $data['tokens']['node']['lehigh_islandora_pdf_url'] = [
    'name' => t("PDF URL"),
    'description' => t("Direct link to Original File's PDF"),
  ];
}

/**
 * Implements hook_tokens().
 */
function lehigh_islandora_tokens($type, $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {

  $token_service = \Drupal::token();

  if (isset($options['langcode'])) {
    $url_options['language'] = \Drupal::languageManager()
      ->getLanguage($options['langcode']);
    $langcode = $options['langcode'];
  }
  else {
    $langcode = NULL;
  }

  $replacements = [];
  if ($type == 'node' && !empty($data['node'])) {

    /** @var \Drupal\node\NodeInterface $node */
    $node = $data['node'];
    foreach ($tokens as $name => $original) {
      switch ($name) {
        case 'lehigh_islandora_issued_ymd':
          if ($node->hasField('field_edtf_date_issued')
            && !$node->field_edtf_date_issued->isEmpty()) {
            $date = $node->field_edtf_date_issued->value;
            $date = EDTFUtils::iso8601Value($date);
            $date = strtotime($date);
            $replacements[$original] = date('Y/n/j', $date);
          }
          break;

        case 'lehigh_islandora_authors':
          if ($node->hasField('field_linked_agent')
            && !$node->field_linked_agent->isEmpty()) {

            $authors = [];
            foreach ($node->field_linked_agent as $linked_agent) {
              // If this is an author/creator.
              if (in_array($linked_agent->rel_type, [
                // Creator.
                'relators:cre',
                // Author.
                'relators:aut',
              ])) {
                $author = $linked_agent->entity->label();
                // If the linked agent is of the format "LAST, FIRST"
                // transform to "FIRST LAST"
                // to match Google Scholar's conventions.
                if (strpos($author, ',')) {
                  $components = explode(',', $author);
                  $author = array_pop($components);
                  $author .= implode(' ', $components);
                }
                $authors[] = $author;
              }
            }
            $replacements[$original] = implode(',', $authors);
          }
          break;

        case 'lehigh_islandora_pdf_url':
          if ($node->hasField('field_pdf_url') &&
            !$node->field_pdf_url->isEmpty()) {
            $replacements[$original] = $node->field_pdf_url->value;
          }
          else {
            $mid = \Drupal::database()->query('SELECT m.entity_id
                FROM {media__field_media_of} m
                INNER JOIN {media__field_media_document} f ON f.entity_id = m.entity_id
                INNER JOIN {media__field_mime_type} mime ON mime.entity_id = m.entity_id
                INNER JOIN {media__field_media_use} u ON u.entity_id = m.entity_id
                INNER JOIN {taxonomy_term_field_data} t ON t.tid = field_media_use_target_id
                WHERE field_media_of_target_id = :nid
                  AND t.name IN (:use[])
                  AND field_mime_type_value = :mime', [
                    ':nid' => $node->id(),
                    ':use[]' => ['Original File'],
                    ':mime' => 'application/pdf',
                  ])->fetchField();
            if ($mid) {
              $media = Media::load($mid);
              if ($media && $media->isPublished()) {
                $replacements[$original] = $media->field_media_document->entity->createFileUrl(0);
              }
            }
          }
          break;
      }
    }

    return $replacements;
  }
}
