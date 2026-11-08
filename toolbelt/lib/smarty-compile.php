<?php

/**
 * cksmarty payload — run through `cv scr` by the `cksmarty` wrapper, so this
 * file executes inside a fully booted CiviCRM with the extension enabled.
 *
 * What it does and why it has to run here:
 *
 * Static analysis of a .tpl can only ever balance braces. Whether a template
 * COMPILES depends on the Smarty version core ships (2, 4 or 5 — they disagree
 * about `{php}`, about unregistered PHP functions as modifiers, about `{if}`
 * expression syntax), on the prefilters CRM_Core_Smarty installs
 * (resetExtScope, htxtFilter — both rewrite the source before the compiler sees
 * it), and on which `{crm*}` plugins are registered, which includes the ones
 * the extension itself adds from its own hook_civicrm_config. None of that is
 * knowable from the file. So: compile the real files with the real
 * CRM_Core_Smarty singleton.
 *
 * COMPILE, not render. Rendering needs the variables a Page/Form would assign
 * and would fail on every template for reasons that are not the template's
 * fault. Compilation is the gate that is both meaningful and universally
 * applicable: a template that does not compile is broken on every site, for
 * every user, on the first request that touches it.
 *
 * Three sources, because a repo ships Smarty in three shapes:
 *   * .tpl FILES in the checkout;
 *   * installed managed MessageTemplate BODIES, which are Smarty strings in
 *     the database and never a file anywhere;
 *   * column and tally REWRITES of installed managed SearchDisplays, which
 *     SearchKit renders through Smarty.
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

// createTemplate()/compileTemplateSource() is the one compile-without-render
// pair that exists in Smarty 3, 4 and 5 alike. Smarty's own
// compileAllTemplates() is NOT usable here for two independent reasons: it
// walks the configured template dirs (i.e. all of core, not this extension),
// and it CATCHES every compile exception, prints it and returns the number of
// files it managed to compile — a broken template leaves no trace in the return
// value, so a gate built on it would be green forever.
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
    // compileTemplateSource() compiles unconditionally — it does not consult
    // mustCompile(), so a stale compiled file from an earlier boot cannot make
    // a broken template look fine.
    $smarty->createTemplate($resource)->compileTemplateSource();
    return NULL;
  }
  catch (\Throwable $e) {
    // Smarty 5 throws \Smarty\CompilerException, Smarty 4
    // SmartyCompilerException, and a prefilter may throw anything at all.
    return get_class($e) . ': ' . $e->getMessage();
  }
};

$failures = [];

// Both token layers run before Smarty. A token is assumed populated here: whether
// a template survives an empty one depends on the record, not on the source.
$populatedToken = '1';

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
// Absolute `file:` paths rather than names relative to a template dir: the
// extension's template dir is registered under whatever prefix its
// hook_civicrm_config chose, and a repo also keeps .tpl files outside it
// (tests/, ang/). No security policy is active on the singleton by default, so
// absolute paths resolve. The vendor/node_modules/dist exclusions are the same
// set every other ck* tool skips — third-party templates are their author's
// CI's problem, and a vendored tree can carry Smarty 2 syntax legitimately.
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
    $error = $compile($smarty, 'file:' . $tpl);
    $relative = substr($tpl, strlen($root) + 1);
    if ($error !== NULL) {
      $failures[] = "$relative: $error";
      echo "  FAIL $relative\n";
    }
  }
}

// --- installed managed MessageTemplate bodies ------------------------------
//
// The blind spot nothing else covers: a workflow message template declared in a
// .mgd.php is a Smarty STRING in civicrm_msg_template. It is compiled the first
// time the workflow fires — which in practice is on a production site, at the
// moment someone donates. A body with `{if $x}` and no `{/if}` passes every
// file-based check in this toolchain and every unit test that does not send
// that exact mail.
//
// `is_reserved` copies are core's business.
//
// Skipped rather than failed when the extension key is unknown: the .tpl half
// above is still a real gate, and an unreadable info.xml is a different tool's
// complaint.
if ($key === '') {
  echo "cksmarty: no extension key — skipping the managed MessageTemplate bodies and SearchDisplay rewrites.\n";
}
else {
  // A repo may render some of its own MessageTemplates itself — token
  // substitution over `{placeholder}` rather than a Smarty pass — in which case
  // the body is not Smarty source and compiling it proves nothing. Declared in
  // civikitchen.yaml (CK_SMARTY_SKIP holds the managed NAMES, comma-separated) so the
  // exception is written down with its reason, next to the code.
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
    $tokens = new \Civi\Token\TokenProcessor(\Civi::dispatcher(), ['controller' => 'cksmarty']);
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
        // `string:` and not `eval:`: both run the identical compiler, but the
        // string resource writes a compiled file keyed by content hash, which
        // is the same path a real render takes. eval: is marked recompiled and
        // is not written at all.
        // Civi tokens ({contact.first_name}) are substituted before Smarty
        // sees the body, so strip them with core's own token parser first.
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
