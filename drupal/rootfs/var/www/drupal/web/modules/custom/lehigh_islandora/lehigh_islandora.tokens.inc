<?php

/**
 * @file
 * Contains lehigh_islandora.tokens.inc.
 */

use Drupal\controlled_access_terms\EDTFUtils;
use Drupal\Core\Render\BubbleableMetadata;

/**
 * Implements hook_token_info_alter().
 */
function lehigh_islandora_token_info_alter(&$data) {
  $data['tokens']['node']['parent-url'] = [
    'name' => t("Parent URL"),
    'description' => t("This node's parent's path alias."),
  ];
  $data['tokens']['node']['lehigh_islandora_issued_ymd'] = [
    'name' => t("Issued Date YYYY/m/d"),
    'description' => t("This node's issue date in Google Scholar's desired format."),
  ];
  $data['tokens']['node']['lehigh_islandora_authors'] = [
    'name' => t("Author(s)"),
    'description' => t("The linked agent author(s)."),
  ];
  $data['tokens']['node']['lehigh_dissertation_institution'] = [
    'name' => 'Dissertation institution',
    'description' => 'Show Lehigh for ETDs',
  ];
  $data['tokens']['node']['lehigh_technical_report_institution'] = [
    'name' => 'Technical Report institution',
    'description' => 'Show Lehigh for Technical Reports',
  ];
  $data['tokens']['node']['lehigh_technical_report_number'] = [
    'name' => 'Technical Report Number',
    'description' => 'Show Lehigh for Technical Reports',
  ];
  $data['tokens']['node']['lehigh_pdf_url'] = [
    'name' => 'PDF URL',
    'description' => 'PDF',
  ];
}

/**
 * Implements hook_tokens().
 */
function lehigh_islandora_tokens($type, $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {

  if (isset($options['langcode'])) {
    $url_options['language'] = \Drupal::languageManager()
      ->getLanguage($options['langcode']);
  }

  $replacements = [];
  if ($type != 'node' || empty($data['node'])) {
    return;
  }
  /**
   * @var \Drupal\node\NodeInterface $node
   */
  $node = $data['node'];
  foreach ($tokens as $name => $original) {
    switch ($name) {
      case 'lehigh_dissertation_institution':
        if ($node->hasField('field_genre')
          && !$node->field_genre->isEmpty()
          && !is_null($node->field_genre->entity)
          && in_array($node->field_genre->entity->label(), ['theses', 'dissertations'])) {
          $replacements[$original] = $node->field_publisher->value;
        }
        break;

      case 'lehigh_technical_report_institution':
        if ($node->hasField('field_genre')
          && !$node->field_genre->isEmpty()
          && !is_null($node->field_genre->entity)
          && $node->field_genre->entity->label() === 'technical reports') {
          $replacements[$original] = $node->field_publisher->value;
        }
        break;

      case 'lehigh_technical_report_number':
        if ($node->hasField('field_identifier')) {
          foreach ($node->field_identifier as $identifier) {
            if ($identifier->attr0 == 'report-number') {
              $replacements[$original] = $identifier->value;
              break;
            }
          }
        }
        break;

      case 'lehigh_islandora_issued_ymd':
        if ($node->hasField('field_edtf_date_issued')
              && !$node->field_edtf_date_issued->isEmpty()
          ) {
          $date = $node->field_edtf_date_issued->value;
          $components = explode('-', $date);
          if (count($components) != 3) {
            $replacements[$original] = $components[0];
            break;
          }
          $date = EDTFUtils::iso8601Value($date);
          $date = strtotime($date);
          $replacements[$original] = date('Y/n/j', $date);
        }
        break;

      case 'lehigh_islandora_authors':
        if ($node->hasField('field_linked_agent')
              && !$node->field_linked_agent->isEmpty()
          ) {

          $authors = [];
          foreach ($node->field_linked_agent as $linked_agent) {
            // If this is an author/creator.
            if (in_array(
                  $linked_agent->rel_type, [
                  // Creator.
                    'relators:cre',
                  // Author.
                    'relators:aut',
                  ]
              )
              ) {
              $author = $linked_agent->entity->label();
              // If the linked agent is of the format "LAST, FIRST"
              // transform to "FIRST LAST"
              // to match Google Scholar's conventions.
              if (strpos($author, ',')) {
                $components = explode(',', $author);
                $author = array_pop($components);
                $author .= ' ' . implode(' ', $components);
              }
              $authors[] = $author;
            }
          }
          $replacements[$original] = implode(',', $authors);
        }
        break;

      case 'lehigh_pdf_url':
        $uri = \Drupal::database()->query('SELECT uri FROM media__field_media_document d
          INNER JOIN media__field_media_use u ON u.entity_id = d.entity_id
          INNER JOIN media__field_media_of mo ON mo.entity_id = d.entity_id
          INNER JOIN file_managed f ON f.fid = field_media_document_target_id
          WHERE field_media_of_target_id = :nid
            AND field_media_use_target_id IN (16, 18)
          ORDER BY field_media_use_target_id DESC
          LIMIT 1', [':nid' => $node->id()])->fetchField();
        if (!$uri) {
          break;
        }
        $replacements[$original] = \Drupal::service('file_url_generator')->generateAbsoluteString($uri);
        break;

      case 'parent-url':
        if ($node->hasField('field_member_of')
              && !$node->field_member_of->isEmpty()
          ) {
          $paths = [
            '/node/' . $node->field_member_of->target_id,
            '/browse-items/' . $node->field_member_of->target_id,
          ];
          foreach ($paths as $path) {
            $alias = \Drupal::database()->query(
                  'SELECT alias FROM {path_alias}
              WHERE path = :path
              AND status = 1', [
                ':path' => $path,
              ]
              )->fetchField();
            if ($alias) {
              $replacements[$original] = $alias;
              break;
            }
          }
        }
        break;
    }

    return $replacements;
  }
}
