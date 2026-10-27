#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Dependency-free validator for the JSON Schema vocabulary this package uses. */
final class CkProfileSchemaValidator {
  /** @var list<string> */
  private array $errors = [];

  public function __construct(private readonly array $root) {}

  /** @return list<string> */
  public function validate(mixed $value): array {
    $this->errors = [];
    $this->walk($value, $this->root, '$');
    return $this->errors;
  }

  private function walk(mixed $value, array|bool $schema, string $path): void {
    if ($schema === TRUE) return;
    if ($schema === FALSE) {
      $this->errors[] = "{$path}: value is forbidden by the schema";
      return;
    }
    if (isset($schema['$ref'])) {
      $this->walk($value, $this->resolve((string) $schema['$ref']), $path);
      return;
    }
    if (isset($schema['anyOf'])) {
      $matches = 0;
      foreach ($schema['anyOf'] as $candidate) {
        if ((new self($this->root))->validateAgainst($value, $candidate) === []) $matches++;
      }
      if ($matches === 0) $this->errors[] = "{$path}: value does not match any anyOf alternative";
    }
    if (isset($schema['oneOf'])) {
      $matches = 0;
      foreach ($schema['oneOf'] as $candidate) {
        if ((new self($this->root))->validateAgainst($value, $candidate) === []) $matches++;
      }
      if ($matches !== 1) $this->errors[] = "{$path}: value must match exactly one oneOf alternative";
    }
    if (isset($schema['type']) && !$this->matchesType($value, $schema['type'])) {
      $this->errors[] = "{$path}: expected {$schema['type']}";
      return;
    }
    if (isset($schema['enum']) && !$this->matchesEnum($value, $schema['enum'])) {
      $this->errors[] = "{$path}: value is not one of " . implode(', ', $schema['enum']);
    }
    if (is_string($value)) {
      $length = preg_match_all('/./us', $value);
      if (isset($schema['minLength']) && $length < $schema['minLength']) {
        $this->errors[] = "{$path}: string is shorter than {$schema['minLength']}";
      }
      if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
        $this->errors[] = "{$path}: string is longer than {$schema['maxLength']}";
      }
      if (isset($schema['pattern']) && preg_match('#' . $schema['pattern'] . '#', $value) !== 1) {
        $this->errors[] = "{$path}: string does not match {$schema['pattern']}";
      }
      if (($schema['format'] ?? NULL) === 'uri' && filter_var($value, FILTER_VALIDATE_URL) === FALSE) {
        $this->errors[] = "{$path}: expected an absolute URI";
      }
    }
    if (is_int($value) || is_float($value)) {
      if (isset($schema['minimum']) && $value < $schema['minimum']) {
        $this->errors[] = "{$path}: number is below {$schema['minimum']}";
      }
      if (isset($schema['maximum']) && $value > $schema['maximum']) {
        $this->errors[] = "{$path}: number is above {$schema['maximum']}";
      }
    }
    if (is_array($value)) {
      if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
        $this->errors[] = "{$path}: array has fewer than {$schema['minItems']} items";
      }
      if (($schema['uniqueItems'] ?? FALSE) === TRUE) {
        $encoded = array_map(static fn (mixed $item): string => json_encode($item, JSON_THROW_ON_ERROR), $value);
        if (count($encoded) !== count(array_unique($encoded))) {
          $this->errors[] = "{$path}: array items must be unique";
        }
      }
      if (isset($schema['items'])) {
        foreach ($value as $index => $item) $this->walk($item, $schema['items'], "{$path}[{$index}]");
      }
      if (isset($schema['contains'])) {
        $matched = FALSE;
        foreach ($value as $item) {
          $probe = new self($this->root);
          if ($probe->validateAgainst($item, $schema['contains']) === []) {
            $matched = TRUE;
            break;
          }
        }
        if (!$matched) $this->errors[] = "{$path}: no array item matches contains";
      }
    }
    if (is_object($value)) $this->walkObject($value, $schema, $path);
  }

  /** @param array<string, mixed>|bool $schema @return list<string> */
  private function validateAgainst(mixed $value, array|bool $schema): array {
    $this->walk($value, $schema, '$');
    return $this->errors;
  }

  private function walkObject(object $value, array $schema, string $path): void {
    $properties = $schema['properties'] ?? [];
    foreach ($schema['required'] ?? [] as $required) {
      if (!property_exists($value, $required)) $this->errors[] = "{$path}: missing required property {$required}";
    }
    foreach (get_object_vars($value) as $name => $child) {
      if (array_key_exists($name, $properties)) {
        $this->walk($child, $properties[$name], "{$path}.{$name}");
      }
      elseif (($schema['additionalProperties'] ?? TRUE) === FALSE) {
        $this->errors[] = "{$path}: unknown property {$name}";
      }
    }
    foreach ($schema['dependentRequired'] ?? [] as $property => $required) {
      if (!property_exists($value, $property)) continue;
      foreach ($required as $name) {
        if (!property_exists($value, $name)) $this->errors[] = "{$path}: {$property} requires {$name}";
      }
    }
    foreach ($schema['dependentSchemas'] ?? [] as $property => $dependent) {
      if (property_exists($value, $property)) $this->walk($value, $dependent, $path);
    }
  }

  private function matchesType(mixed $value, string $type): bool {
    return match ($type) {
      'object' => is_object($value),
      'array' => is_array($value),
      'string' => is_string($value),
      'boolean' => is_bool($value),
      'integer' => is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value),
      'number' => is_int($value) || is_float($value),
      'null' => $value === NULL,
      default => FALSE,
    };
  }

  /** @param list<mixed> $choices */
  private function matchesEnum(mixed $value, array $choices): bool {
    foreach ($choices as $choice) {
      // JSON Schema numbers compare by mathematical value. JSON 1 and 1.0
      // are therefore equal even though PHP's strict comparison distinguishes
      // the decoder's int and float representations.
      if ((is_int($value) || is_float($value)) && (is_int($choice) || is_float($choice))) {
        if ($value == $choice) return TRUE;
      }
      elseif ($value === $choice) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function resolve(string $ref): array|bool {
    if (!str_starts_with($ref, '#/')) throw new RuntimeException("unsupported external schema reference: {$ref}");
    $value = $this->root;
    foreach (explode('/', substr($ref, 2)) as $segment) {
      $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
      if (!is_array($value) || !array_key_exists($segment, $value)) throw new RuntimeException("unresolved schema reference: {$ref}");
      $value = $value[$segment];
    }
    return $value;
  }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
  $arguments = $_SERVER['argv'];
  $schemaFile = count($arguments) >= 3 ? $arguments[1] : __DIR__ . '/profile.schema.json';
  $documentFile = count($arguments) >= 3 ? $arguments[2] : ($arguments[1] ?? '');
  if ($documentFile === '') {
    fwrite(STDERR, "usage: validate.php [schema.json] <profile.json>\n");
    exit(2);
  }
  try {
    $schema = json_decode((string) file_get_contents($schemaFile), TRUE, 512, JSON_THROW_ON_ERROR);
    $document = json_decode((string) file_get_contents($documentFile), FALSE, 512, JSON_THROW_ON_ERROR);
    $errors = (new CkProfileSchemaValidator($schema))->validate($document);
  }
  catch (Throwable $e) {
    fwrite(STDERR, "profile validation failed: {$e->getMessage()}\n");
    exit(2);
  }
  if ($errors !== []) {
    foreach ($errors as $error) fwrite(STDERR, "{$documentFile}: {$error}\n");
    exit(1);
  }
  echo "{$documentFile}: valid civicrm profile\n";
}
