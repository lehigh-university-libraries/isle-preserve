<?php

namespace Drupal\islandora_scribe;

use Drupal\Core\Site\Settings;

final class RuntimeSettings {
  public function __construct(private Settings $settings) {}

  public function get(): array {
    $values = $this->settings->get('islandora_scribe', []);
    $url = $values['base_url'] ?? '';
    $parts = parse_url($url);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || (!empty($parts['path']) && $parts['path'] !== '/')) {
      throw new \RuntimeException('Configure a Scribe HTTPS origin.');
    }
    if (!is_int($values['workspace_id'] ?? NULL) || $values['workspace_id'] < 1) {
      throw new \RuntimeException('Configure a positive Scribe workspace ID.');
    }
    foreach (['webhook_secret', 'correlation_secret'] as $key) {
      $length = strlen($values[$key] ?? '');
      if ($length < 32 || $length > 1024) {
        throw new \RuntimeException('Configure Scribe signing secrets (32–1024 bytes).');
      }
    }
    $key = $values['api_key'] ?? NULL;
    if (!is_string($key) || $key === '' || strlen($key) > 4096 || preg_match('/[\x00-\x20\x7f]/', $key)) {
      throw new \RuntimeException('Configure a protected Scribe read API key.');
    }
    $values['base_url'] = rtrim($url, '/');
    return $values;
  }
}
