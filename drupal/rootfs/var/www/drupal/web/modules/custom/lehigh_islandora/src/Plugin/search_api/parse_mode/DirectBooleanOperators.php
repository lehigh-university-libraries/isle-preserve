<?php

namespace Drupal\lehigh_islandora\Plugin\search_api\parse_mode;

use Drupal\Component\Utility\Unicode;
use Drupal\search_api\Plugin\search_api\parse_mode\Direct;

/**
 * Represents a parse mode that handles Boolean operators and grouping.
 *
 * @see https://www.drupal.org/project/search_api_solr/issues/3459227#comment-15674804
 *
 * @SearchApiParseMode(
 *   id = "direct_boolean_operators",
 *   label = @Translation("Direct query boolean operators"),
 *   description = @Translation("A direct query allowing boolean operators and grouping. Might fail if the query contains syntax errors in regard to the specific server's query syntax."),
 * )
 */
class DirectBooleanOperators extends Direct {

  /**
   * {@inheritdoc}
   */
  public function parseInput($keys) {
    // Check if input is an array.
    if (is_array($keys)) {
      // Validate each element in the array.
      foreach ($keys as $key) {
        if (!Unicode::validateUtf8($key)) {
          return '';
        }
      }
      // Convert array to string with spaces between elements.
      $keys = implode(' ', $keys);
    }
    else {
      // Validate the single string input.
      if (!Unicode::validateUtf8($keys)) {
        return '';
      }
    }

    $keys = preg_replace('/\s+/u', ' ', trim($keys));

    // Handle Boolean operators and symbols, remove extra whitespaces.
    $keys = preg_replace('/\s(AND|OR|NOT|!|\|\||&&)\s/', ' $1 ', $keys);

    // Define special characters to escape.
    $escape_special_chars = ['{', '}', '[', ']', '^', '~', '*', '?', ':'];

    // Handle special characters outside of quotes.
    $keys = preg_replace_callback('/("[^"]+")|\S+/', function ($matches) use ($escape_special_chars) {
      if (isset($matches[1])) {
        // This is a quoted phrase, don't modify anything inside.
        return $matches[0];
      }
      else {
        // This is not a quoted phrase
        // escape only the specified special characters.
        $term = $matches[0];
        foreach ($escape_special_chars as $char) {
          $term = str_replace($char, '\\' . $char, $term);
        }
        return $term;
      }
    }, $keys);

    // @todo Handle NegativeQueryProblems: Pure Negative Queries
    // https://cwiki.apache.org/confluence/display/SOLR/NegativeQueryProblems#NegativeQueryProblems-PureNegativeQueries
    return $keys;
  }

}
