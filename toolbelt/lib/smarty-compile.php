<?php

/**
 * cksmarty payload, run through `cv scr` inside a booted CiviCRM with the
 * extension enabled. Compiles (does not render) the repo's .tpl files, its
 * managed MessageTemplate bodies and managed SearchDisplay rewrites with the
 * real CRM_Core_Smarty singleton, its prefilters and registered plugins.
 *
 * Env in: CK_SMARTY_ROOT (extension checkout), CK_SMARTY_KEY (extension key),
 * CK_SMARTY_DONE (file written on success, so the wrapper can tell a run from a failed boot).
 * Exit: 0 clean, 1 on the first compile failure reported (all are printed).
 */

$root = rtrim(getenv('CK_SMARTY_ROOT') ?: getcwd(), '/');
$key = getenv('CK_SMARTY_KEY') ?: '';

if (!is_dir($root)) {
  fwrite(STDERR, "cksmarty: not a directory: $root\n");
  exit(2);
}

$smarty = CRM_Core_Smarty::singleton();

// The one compile-without-render API in Smarty 3, 4 and 5. compileAllTemplates()
// walks all of core and swallows compile exceptions.
$probe = $smarty->createTemplate('string:cksmarty');
if (!method_exists($probe, 'compileTemplateSource')) {
  fwrite(STDERR, "cksmarty: this Smarty (" . $smarty->getVersion() . ") has no per-template compile API — cannot gate.\n");
  exit(2);
}
unset($probe);

/**
 * Compile one Smarty resource, returning the error message or NULL.
 *
 * @param \CRM_Core_Smarty $smarty
 * @param string $resource
 *
 * @return string|null
 */
$compile = function ($smarty, string $resource): ?string {
  try {
    // Compiles unconditionally, so a stale compiled file cannot hide a break.
    $smarty->createTemplate($resource)->compileTemplateSource();
    return NULL;
  }
  catch (\Throwable $e) {
    // Smarty 5 throws \Smarty\CompilerException, Smarty 4
    // SmartyCompilerException, and a prefilter may throw anything at all.
    return get_class($e) . ': ' . $e->getMessage();
  }
};

// The compiler leaves a literal {include} unresolved until render, so a missing target compiles.
$missingIncludes = static function ($smarty, string $source): array {
  $source = preg_replace('/\{\*.*?\*\}|\{literal\}.*?\{\/literal\}/s', '', $source);
  preg_match_all('/\{include\s[^}]*?\bfile=(["\'])([^"\'$`:]+)\1(?=[\s}])/', $source, $matches);
  return array_values(array_filter(array_unique($matches[2]), static fn (string $target): bool => !$smarty->templateExists($target)));
};

$failures = [];

// Both token layers run before Smarty. A token is assumed populated here: whether
// a template survives an empty one depends on the record, not on the source.
$populatedToken = '1';
$tokens = new \Civi\Token\TokenProcessor(\Civi::dispatcher(), ['controller' => 'cksmarty']);

// Read through Managed: it records what this extension owns and what really installed.
$managedRecords = static function (string $key, string $entityType) {
  return \Civi\Api4\Managed::get(FALSE)
    ->addSelect('entity_id', 'name')
    ->addWhere('module', '=', $key)
    ->addWhere('entity_type', '=', $entityType)
    ->addWhere('entity_id', 'IS NOT EMPTY')
    ->execute();
};

// --- .tpl files ------------------------------------------------------------
//
// Absolute `file:` paths, since .tpl files also live outside the template dir;
// they resolve because the singleton has no security policy.
$skipDirs = ['vendor', 'node_modules', '.git', 'dist', 'build', '.civikitchen-siblings'];
$tpls = [];
$it = new RecursiveIteratorIterator(
  new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    function ($current) use ($skipDirs) {
      return !($current->isDir() && in_array($current->getFilename(), $skipDirs, TRUE));
    }
  )
);
foreach ($it as $file) {
  if ($file->isFile() && strtolower($file->getExtension()) === 'tpl') {
    $tpls[] = $file->getPathname();
  }
}
sort($tpls);

if (!$tpls) {
  echo "cksmarty: no .tpl files in this repo — nothing to compile.\n";
}
else {
  echo "cksmarty: compiling " . count($tpls) . " .tpl file(s) with " . get_class($smarty)
    . " (Smarty " . $smarty->getVersion() . ") ...\n";
  foreach ($tpls as $tpl) {
    $relative = substr($tpl, strlen($root) + 1);
    // A .mgd.php reads these into a MessageTemplate, so core replaces their tokens before Smarty.
    $resource = str_contains("/$relative", '/managed/')
      ? 'string:' . $tokens->visitTokens(file_get_contents($tpl), static fn (): string => $populatedToken)
      : 'file:' . $tpl;
    $error = $compile($smarty, $resource);
    if ($error !== NULL) {
      $failures[] = "$relative: $error";
      echo "  FAIL $relative\n";
    }
    foreach ($missingIncludes($smarty, file_get_contents($tpl)) as $target) {
      $failures[] = "$relative: include target does not exist: $target";
      echo "  FAIL $relative (include $target)\n";
    }
  }
}

// --- installed managed MessageTemplate bodies ------------------------------
//
// Smarty strings in civicrm_msg_template, otherwise first compiled when the
// workflow fires. Skipped, not failed, when the extension key is unknown.
if ($key === '') {
  echo "cksmarty: no extension key — skipping the managed MessageTemplate bodies and SearchDisplay rewrites.\n";
}
else {
  // Managed names (comma-separated, from civikitchen.yaml) of templates the
  // repo renders without Smarty.
  $skip = array_filter(array_map('trim', explode(',', (string) getenv('CK_SMARTY_SKIP'))));
  if ($skip) {
    echo 'cksmarty: skipping ' . count($skip) . " MessageTemplate(s) the repo renders without Smarty.\n";
  }

  $managed = $managedRecords($key, 'MessageTemplate');

  if (!count($managed)) {
    echo "cksmarty: extension '$key' installs no managed MessageTemplates.\n";
  }
  else {
    echo "cksmarty: compiling " . count($managed) . " managed MessageTemplate body/bodies ...\n";
    foreach ($managed as $record) {
      if (in_array($record['name'], $skip, TRUE)) {
        continue;
      }
      $tpl = \Civi\Api4\MessageTemplate::get(FALSE)
        ->addSelect('msg_subject', 'msg_text', 'msg_html')
        ->addWhere('id', '=', $record['entity_id'])
        ->execute()
        ->first();
      if (!$tpl) {
        continue;
      }
      foreach (['msg_subject', 'msg_text', 'msg_html'] as $field) {
        $body = (string) ($tpl[$field] ?? '');
        if (trim($body) === '') {
          continue;
        }
        // `string:`, not `eval:`, takes the same path as a real render. Civi
        // tokens are substituted before Smarty, so replace them first.
        $body = $tokens->visitTokens($body, static fn (): string => $populatedToken, $field === 'msg_html' ? 'text/html' : 'text/plain');
        $error = $compile($smarty, 'string:' . $body);
        if ($error !== NULL) {
          $failures[] = "MessageTemplate {$record['name']} ($field): $error";
          echo "  FAIL {$record['name']} ($field)\n";
        }
      }
    }
  }

  // --- installed managed SearchDisplay rewrites ------------------------------
  //
  // SearchKit replaces [field] tokens, resolves conditional punctuation, then
  // renders any rewrite containing braces through Smarty.
  $displays = $managedRecords($key, 'SearchDisplay');
  if (!count($displays)) {
    echo "cksmarty: extension '$key' installs no managed SearchDisplays.\n";
  }
  else {
    echo "cksmarty: compiling the rewrites of " . count($displays) . " managed SearchDisplay(s) ...\n";
    foreach ($displays as $record) {
      $display = \Civi\Api4\SearchDisplay::get(FALSE)
        ->addSelect('settings')
        ->addWhere('id', '=', $record['entity_id'])
        ->execute()
        ->first();
      foreach ($display['settings']['columns'] ?? [] as $index => $column) {
        $columnName = 'column ' . ($column['key'] ?? $index);
        $rewrites = [
          $columnName => (string) ($column['rewrite'] ?? ''),
          "$columnName tally" => (string) ($column['tally']['rewrite'] ?? ''),
        ];
        foreach ($rewrites as $label => $rewrite) {
          if (!str_contains($rewrite, '{') || !str_contains($rewrite, '}')) {
            continue;
          }
          foreach (\CRM_Utils_String::getSquareTokens($rewrite) as $token) {
            $rewrite = str_replace($token['token'], $populatedToken, $rewrite);
          }
          // Absent from older cores, whose SearchKit does not call it either.
          if (method_exists(\Civi\Token\TokenCompatSubscriber::class, 'renderConditionalPunctuation')) {
            $rewrite = \Civi\Token\TokenCompatSubscriber::renderConditionalPunctuation($rewrite);
          }
          $error = $compile($smarty, 'string:' . $rewrite);
          if ($error !== NULL) {
            $failures[] = "SearchDisplay {$record['name']} ($label): $error";
            echo "  FAIL {$record['name']} ($label)\n";
          }
        }
      }
    }
  }
}

if ($failures) {
  fwrite(STDERR, "\ncksmarty: " . count($failures) . " template(s) do not compile:\n");
  foreach ($failures as $failure) {
    fwrite(STDERR, "  $failure\n");
  }
  exit(1);
}

echo "cksmarty: all templates compile.\n";
$done = getenv('CK_SMARTY_DONE');
if ($done) {
  touch($done);
}
