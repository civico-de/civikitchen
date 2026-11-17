<?php

declare(strict_types=1);

function ck_credentials_output_mode(): string {
  $mode = getenv('CK_CREDENTIALS_OUTPUT') ?: 'file';
  if (!in_array($mode, ['file', 'log', 'both', 'none'], TRUE)) {
    throw new \RuntimeException(
      "configure-api-users: CK_CREDENTIALS_OUTPUT must be file, log, both, or none (got '{$mode}')"
    );
  }
  return $mode;
}

function ck_credentials_writes_file(string $mode): bool {
  return $mode === 'file' || $mode === 'both';
}

function ck_credentials_writes_log(string $mode): bool {
  return $mode === 'log' || $mode === 'both';
}

/**
 * Remove a previous credentials file before rotating; `log` and `none` promise
 * none remains, so a failure is fatal.
 */
function ck_credentials_remove_file(string $path): void {
  if (!file_exists($path) && !is_link($path)) {
    return;
  }
  if (!unlink($path) || file_exists($path) || is_link($path)) {
    throw new \RuntimeException("configure-api-users: cannot remove stale credentials file {$path}");
  }
}

function ck_credentials_password(): string {
  return bin2hex(random_bytes(24));
}

/** @return array<string, string> username => serialized credential line */
function ck_credentials_read_file(string $path): array {
  $lines = [];
  if (!is_file($path)) {
    return $lines;
  }
  foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $lines[explode(':', $line, 2)[0]] = $line;
  }
  return $lines;
}

/** @param array<string, string> $lines */
function ck_credentials_write_file(string $path, array $lines): void {
  $dir = dirname($path);
  if (!is_dir($dir) || !is_writable($dir)) {
    throw new \RuntimeException("configure-api-users: credentials directory is not writable: {$dir}");
  }
  $temporary = tempnam($dir, '.civikitchen-credentials-');
  if ($temporary === FALSE) {
    throw new \RuntimeException("configure-api-users: cannot create temporary credentials file in {$dir}");
  }
  try {
    $body = $lines ? implode("\n", $lines) . "\n" : '';
    if (file_put_contents($temporary, $body, LOCK_EX) === FALSE
      || !chmod($temporary, 0600)
      || !rename($temporary, $path)
      || !chmod($path, 0600)
    ) {
      throw new \RuntimeException("configure-api-users: cannot write credentials file {$path}");
    }
  }
  finally {
    if (is_file($temporary)) {
      unlink($temporary);
    }
  }
}

/** @param list<array{0: string, 1: string, 2: string}> $credentials */
function ck_credentials_log(array $credentials): void {
  echo "==========================================\n";
  echo "API User Credentials\n";
  echo "==========================================\n\n";
  foreach ($credentials as [$username, $password, $apiKey]) {
    printf("%-15s | Username: %-12s | Password: %-12s\n", 'User', $username, $password);
    printf("%-15s | API Key:  %s\n\n", '', $apiKey);
  }
  echo "==========================================\n\n";
}
