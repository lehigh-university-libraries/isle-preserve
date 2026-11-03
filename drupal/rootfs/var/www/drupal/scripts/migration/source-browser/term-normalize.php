<?php

/**
 * @file
 * Normalizes subject labels for the source-browser term report.
 */

declare(strict_types=1);

/**
 * Separates literal delimiters while preserving semicolons in HTML entities.
 *
 * @return array{label: string, parts: string[], flags: string[]}
 *   Decoded text, proposed parts, and data-quality flags.
 */
function source_browser_normalize_term(string $label): array {
  $decode = static fn(string $value): string => html_entity_decode($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
  // Protect entity terminators before treating remaining semicolons as separators.
  $raw_parts = preg_split('/&(?:#[xX][0-9a-fA-F]+|#[0-9]+|[a-zA-Z][a-zA-Z0-9]+);(*SKIP)(*F)|;/', $label);
  $parts = array_map(static fn(string $part): string => trim($decode($part)), $raw_parts);
  $normalized = $decode($label);
  $flags = [];
  if (str_contains($normalized, "\u{FFFD}")) {
    $flags[] = 'replacement character; review source text';
  }
  if (count($parts) > 1 && in_array('', $parts, TRUE)) {
    $flags[] = 'empty split part';
  }
  return ['label' => $normalized, 'parts' => $parts, 'flags' => $flags];
}
