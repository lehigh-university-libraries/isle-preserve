#!/usr/bin/env php
<?php

declare(strict_types=1);

function stderr(string $message): void {
  fwrite(STDERR, $message . PHP_EOL);
}

function debug_log(bool $enabled, string $message): void {
  if ($enabled) {
    fwrite(STDERR, '[debug] ' . $message . PHP_EOL);
  }
}

function usage(): void {
  $script = basename(__FILE__);
  $message = <<<TXT
Usage: {$script} [--dry-run] [--debug] [--url URL] [--module PATH] [--traefik PATH] [--env PATH]

Sync the Lehigh Guest network allowlist from Nautobot using NAUTOBOT_API_URL and
NAUTOBOT_API_KEY, then update Drupal's on-campus guest IP map and Traefik's
captcha exemption list.
TXT;
  fwrite(STDOUT, $message . PHP_EOL);
}

function parse_args(array $argv): array {
  $root = dirname(__DIR__, 2);
  $args = [
    'url' => '',
    'module' => $root . '/codebase/web/modules/custom/lehigh_islandora/lehigh_islandora.module',
    'traefik' => $root . '/conf/traefik/drupal.yml',
    'env' => $root . '/.env',
    'dry-run' => false,
    'debug' => false,
  ];

  for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if ($arg === '--dry-run') {
      $args['dry-run'] = true;
      continue;
    }
    if ($arg === '--debug') {
      $args['debug'] = true;
      continue;
    }
    if ($arg === '--help' || $arg === '-h') {
      usage();
      exit(0);
    }
    if (in_array($arg, ['--url', '--module', '--traefik', '--env'], true)) {
      if (!isset($argv[$i + 1])) {
        throw new RuntimeException("Missing value for {$arg}.");
      }
      $args[ltrim($arg, '-')] = $argv[++$i];
      continue;
    }
    throw new RuntimeException("Unknown argument: {$arg}");
  }

  return $args;
}

function load_env_file(string $path): array {
  if (!is_file($path)) {
    return [];
  }

  $vars = [];
  $lines = file($path, FILE_IGNORE_NEW_LINES);
  if ($lines === false) {
    return [];
  }

  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
      continue;
    }

    $parts = explode('=', $line, 2);
    if (count($parts) !== 2) {
      continue;
    }

    $key = trim($parts[0]);
    $value = trim($parts[1]);
    $value = trim($value, "\"'");
    $vars[$key] = $value;
  }

  return $vars;
}

function resolve_nautobot_api_url(array $args, bool $debug = false): string {
  if ($args['url'] !== '') {
    debug_log($debug, 'Using Nautobot API URL from --url.');
    return $args['url'];
  }

  $fromEnv = getenv('NAUTOBOT_API_URL');
  if (is_string($fromEnv) && $fromEnv !== '') {
    debug_log($debug, 'Using NAUTOBOT_API_URL from process environment.');
    return $fromEnv;
  }

  $envVars = load_env_file($args['env']);
  if (!empty($envVars['NAUTOBOT_API_URL'])) {
    debug_log($debug, 'Using NAUTOBOT_API_URL from .env file.');
    return $envVars['NAUTOBOT_API_URL'];
  }

  throw new RuntimeException('NAUTOBOT_API_URL is not set in the environment, .env, or --url.');
}

function resolve_nautobot_api_key(string $envPath, bool $debug = false): string {
  $fromEnv = getenv('NAUTOBOT_API_KEY');
  if (is_string($fromEnv) && $fromEnv !== '') {
    debug_log($debug, 'Using NAUTOBOT_API_KEY from process environment.');
    return $fromEnv;
  }

  debug_log($debug, 'Loading .env file: ' . $envPath);
  $envVars = load_env_file($envPath);
  if (!empty($envVars['NAUTOBOT_API_KEY'])) {
    debug_log($debug, 'Using NAUTOBOT_API_KEY from .env file.');
    return $envVars['NAUTOBOT_API_KEY'];
  }

  throw new RuntimeException('NAUTOBOT_API_KEY is not set in the environment or .env.');
}

function fetch_url(string $url, array $headers = [], bool $debug = false): string {
  debug_log($debug, 'Fetching URL: ' . $url);
  if ($headers) {
    debug_log($debug, 'Request headers: ' . implode(' | ', $headers));
  }

  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_FAILONERROR => true,
      CURLOPT_USERAGENT => 'lehigh-guest-network-updater/1.0',
      CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
      $error = curl_error($ch);
      curl_close($ch);
      throw new RuntimeException('cURL request failed: ' . $error);
    }
    curl_close($ch);
    debug_log($debug, 'Fetched bytes via curl: ' . strlen($body));
    debug_log($debug, 'Response preview: ' . substr(preg_replace('/\s+/', ' ', $body), 0, 240));
    return $body;
  }

  $headerText = $headers ? implode("\r\n", $headers) . "\r\n" : '';
  $context = stream_context_create([
    'http' => [
      'method' => 'GET',
      'header' => $headerText,
      'ignore_errors' => false,
    ],
  ]);
  $body = @file_get_contents($url, false, $context);
  if ($body === false) {
    throw new RuntimeException('Unable to fetch URL and ext-curl is not available.');
  }
  debug_log($debug, 'Fetched bytes via file_get_contents: ' . strlen($body));
  debug_log($debug, 'Response preview: ' . substr(preg_replace('/\s+/', ' ', $body), 0, 240));
  return $body;
}

function is_valid_ipv4_cidr(string $cidr): bool {
  if (!preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $cidr, $matches)) {
    return false;
  }
  $ip = $matches[1];
  $mask = (int) $matches[2];
  if ($mask < 0 || $mask > 32) {
    return false;
  }
  return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

function ipv4_to_uint(string $ip): int {
  $long = ip2long($ip);
  if ($long === false) {
    throw new RuntimeException("Invalid IPv4 address: {$ip}");
  }
  return (int) sprintf('%u', $long);
}

function canonicalize_ipv4_cidr(string $cidr): string {
  [$ip, $mask] = explode('/', $cidr, 2);
  $mask = (int) $mask;
  $ipLong = ipv4_to_uint($ip);
  $maskBits = $mask === 0 ? 0 : ((0xFFFFFFFF << (32 - $mask)) & 0xFFFFFFFF);
  $network = $ipLong & $maskBits;
  return long2ip((int) $network) . '/' . $mask;
}

function fetch_cidrs_from_nautobot(string $apiUrl, string $apiKey, bool $debug = false): array {
  debug_log($debug, 'Using Nautobot API URL: ' . $apiUrl);

  $headers = [
    'Accept: application/json',
    'Authorization: Token ' . $apiKey,
  ];
  $body = fetch_url($apiUrl, $headers, $debug);
  $data = json_decode($body, true);
  if (!is_array($data)) {
    throw new RuntimeException('Nautobot API did not return valid JSON.');
  }

  $records = $data['results'] ?? $data;
  if (!is_array($records)) {
    throw new RuntimeException('Nautobot API response did not include a results array.');
  }

  $cidrs = [];
  $seen = [];
  foreach ($records as $record) {
    if (!is_array($record) || empty($record['prefix']) || !is_string($record['prefix'])) {
      continue;
    }

    $value = trim($record['prefix']);
    if (!is_valid_ipv4_cidr($value)) {
      continue;
    }

    $canonical = canonicalize_ipv4_cidr($value);
    if (isset($seen[$canonical])) {
      continue;
    }

    $seen[$canonical] = true;
    $cidrs[] = $canonical;
  }

  if (!$cidrs) {
    throw new RuntimeException('No IPv4 CIDRs were found in the Nautobot API response.');
  }

  debug_log($debug, 'Validated CIDRs: ' . implode(', ', $cidrs));
  return $cidrs;
}

function expand_cidrs(array $cidrs): array {
  $ips = [];
  $seen = [];

  foreach ($cidrs as $cidr) {
    [$ip, $mask] = explode('/', $cidr, 2);
    $mask = (int) $mask;
    $start = ipv4_to_uint($ip);
    $count = (int) (2 ** (32 - $mask));
    for ($offset = 0; $offset < $count; $offset++) {
      $candidate = long2ip((int) ($start + $offset));
      if (isset($seen[$candidate])) {
        continue;
      }
      $seen[$candidate] = true;
      $ips[] = $candidate;
    }
  }

  return $ips;
}

function render_php_allowed_ips(array $ips): string {
  $lines = [
    '  // Guest network IPs.',
    '  static $allowed_ips = [',
  ];
  foreach ($ips as $ip) {
    $lines[] = "    '{$ip}' => TRUE,";
  }
  $lines[] = '  ];';
  return implode(PHP_EOL, $lines);
}

function update_php_module(string $path, array $ips): void {
  $text = file_get_contents($path);
  if ($text === false) {
    throw new RuntimeException("Unable to read {$path}");
  }

  $start = strpos($text, "  // Guest network IPs.
  static \$allowed_ips = [");
  $end = strpos($text, "

  \$request = \Drupal::request();", $start !== false ? $start : 0);
  if ($start === false || $end === false) {
    throw new RuntimeException("Unable to update guest network IP block in {$path}");
  }

  $replacement = render_php_allowed_ips($ips);
  $updated = substr($text, 0, $start) . $replacement . substr($text, $end);
  file_put_contents($path, $updated);
}

function render_traefik_cidrs(array $cidrs): string {
  $lines = [
    '          exemptIps:',
    '            # Lehigh range',
    '            - 128.180.0.0/16',
    '            # Lehigh Guest Network',
  ];
  foreach ($cidrs as $cidr) {
    $lines[] = "            - {$cidr}";
  }
  return implode(PHP_EOL, $lines);
}

function update_traefik(string $path, array $cidrs): void {
  $text = file_get_contents($path);
  if ($text === false) {
    throw new RuntimeException("Unable to read {$path}");
  }

  $start = strpos($text, "          exemptIps:
            # Lehigh range
            - 128.180.0.0/16
            # Lehigh Guest Network");
  $end = strpos($text, "
{{- else }}", $start !== false ? $start : 0);
  if ($start === false || $end === false) {
    throw new RuntimeException("Unable to update exemptIps block in {$path}");
  }

  $replacement = render_traefik_cidrs($cidrs);
  $updated = substr($text, 0, $start) . $replacement . substr($text, $end);
  file_put_contents($path, $updated);
}

function main(array $argv): int {
  $args = parse_args($argv);
  $apiUrl = resolve_nautobot_api_url($args, $args['debug']);
  $apiKey = resolve_nautobot_api_key($args['env'], $args['debug']);
  $cidrs = fetch_cidrs_from_nautobot($apiUrl, $apiKey, $args['debug']);
  $ips = expand_cidrs($cidrs);

  fwrite(STDOUT, "CIDRs found:" . PHP_EOL);
  foreach ($cidrs as $cidr) {
    fwrite(STDOUT, $cidr . PHP_EOL);
  }
  fwrite(STDOUT, 'Expanded IP count: ' . count($ips) . PHP_EOL);

  if ($args['dry-run']) {
    return 0;
  }

  update_php_module($args['module'], $ips);
  update_traefik($args['traefik'], $cidrs);

  fwrite(STDOUT, 'Updated ' . $args['module'] . PHP_EOL);
  fwrite(STDOUT, 'Updated ' . $args['traefik'] . PHP_EOL);
  return 0;
}

try {
  exit(main($argv));
}
catch (Throwable $e) {
  stderr($e->getMessage());
  exit(1);
}
