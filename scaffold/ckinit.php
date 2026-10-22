#!/usr/bin/env php
<?php
declare(strict_types=1);

$templateDir = __DIR__ . '/template/extension';
$yamlAutoload = dirname(__DIR__) . '/packages/civikitchen-scenario-schema/vendor/autoload.php';
if (!is_file($yamlAutoload)) {
  fwrite(STDERR, "ckinit: YAML parser dependency is missing; run composer install --working-dir=packages/civikitchen-scenario-schema\n");
  exit(2);
}

/**
 * Template files civikitchen OWNS: identical in every conforming repo, safe to
 * overwrite on --update and compared on --check. Everything else in the
 * template is seeded once (composer.json, phpstan.neon.dist, the dev compose
 * file, .gitignore) and then belongs to the extension — repos edit those, so
 * ckinit never touches them again.
 *
 * A repo that must deviate on a managed file declares it in civikitchen.yaml:
 *
 *   policy.template_custom.paths: [.docker/docker-compose.ci.yml]
 */
const MANAGED_FILES = [
  '.gitattributes',
  '.github/workflows/ci.yml',
  '.gitlab-ci.yml',
  '.github/workflows/release.yml',
  '.docker/docker-compose.ci.yml',
  '.docker/init.d/README.md',
  'renovate.json',
  'phpstanBootstrap.php',
  'tests/phpunit/bootstrap.php',
  'tests/phpunit/ckHeadless.php',
  'tests/e2e/lib.sh',
  '.civikitchen/strauss.php',
];

/**
 * Seeded files, listed explicitly rather than "everything else": a template
 * file in neither list aborts every ckinit run. Without that, a new template
 * file would default to seeded — copied once, then never updated — which for
 * a workflow or bootstrap file is exactly the wrong silent default.
 */
const SEEDED_FILES = [
  '.gitignore',
  'civikitchen.yaml',
  '.docker/docker-compose.yml',
  '.docker/profiles/dev/profile.json',
  'composer.json',
  'phpstan.neon.dist',
  // Opt-in test analysis. Seeded, never managed: its mere existence turns a
  // second CI gate on, so pushing it into existing repos would fail them.
  'phpstan-tests.neon.dist',
  'phpunit.xml.dist',
];

/**
 * Seeded files whose ABSENCE is never drift.
 *
 * A new repo gets them; an existing one is not made to. phpstan-tests.neon.dist
 * is an opt-in gate — its existence is the switch CI reads — so reporting it
 * missing would turn the drift check into the forced rollout the opt-in was
 * meant to avoid. --update therefore does not create these either.
 */
const OPTIONAL_FILES = [
  'phpstan-tests.neon.dist',
];

/**
 * Files GitHub and Renovate only read at the repository root. For an extension
 * below the root they are neither written nor checked; the root pass owns them.
 */
const ROOT_ONLY_FILES = [
  '.github/workflows/ci.yml',
  '.github/workflows/release.yml',
  '.gitlab-ci.yml',
  'renovate.json',
];

/** The files only one CI host reads; policy.ci picks the set (absent: GitHub). */
const GITHUB_FILES = [
  '.github/workflows/ci.yml',
  '.github/workflows/release.yml',
  'renovate.json',
];
const GITLAB_FILES = [
  '.gitlab-ci.yml',
];

/** Written only where composer.json declares extra.strauss. */
const STRAUSS_FILES = [
  '.civikitchen/strauss.php',
];

/** The managed release caller and the reusable workflow it calls. */
const RELEASE_CALLER = '.github/workflows/release.yml';
const SHARED_RELEASE = 'extension-release.yml';

/** The root release caller's job that publishes every extension's archive. */
const RELEASE_PUBLISH_JOB = 'publish';

/** The compose files whose managed app block mounts the extension directory. */
const COMPOSE_FILES = [
  '.docker/docker-compose.ci.yml',
  '.docker/docker-compose.yml',
];

function usage(int $status = 2): never {
  $stream = $status === 0 ? STDOUT : STDERR;
  fwrite($stream, <<<'TXT'
ckinit — add or refresh the CiviKitchen development standard in a civix extension.

Usage:
  scaffold/ckinit.php [--force] <extension-directory>    seed the template
  scaffold/ckinit.php --update <extension-directory>     refresh managed files
  scaffold/ckinit.php --check <extension-directory>      report drift, exit 1 on any

The target contains info.xml, or is the root of a repository whose direct
subdirectories are extensions — then ckinit manages the root files
(renovate.json, .gitattributes, one managed CI job block per extension, and a
release caller with one build job per releasing extension plus the job that
publishes them together) and runs the same pass for every extension directory. Files from scaffold/template/extension are copied
recursively; __EXTKEY__ is replaced with info.xml's <file> value,
__VENDOR__ with the vendor segment of the extension key, __RENOVATE_PRESET__
with the renovate_preset policy key (default config:recommended), __DB_IMAGE__
with the database policy key (default mariadb:11.4) and __LICENSE__ with
info.xml's <license>.

With `ci: gitlab` under policy in civikitchen.yaml, a single-extension
repository gets a managed .gitlab-ci.yml instead of the GitHub callers and
renovate.json.

Seeding preserves existing files unless --force is given. --update rewrites
only the MANAGED files (CI caller, test bootstraps, CI compose stack — the
ones meant to be identical everywhere) and creates whatever is missing;
seeded files the repo has edited (composer.json, phpstan.neon.dist, dev
compose, .gitignore) are never touched. --check is
the dry twin for CI.

Some managed files carry marked blocks:
  # BEGIN CIVIKITCHEN MANAGED <name> … # END CIVIKITCHEN MANAGED <name>
Only the blocks are managed (compared, refreshed); what a repo writes outside
them — extra workflow inputs and jobs, a sibling mount — is its own. A repo
that must deviate INSIDE a block, or on a file without blocks, lists paths and
a non-empty reason under policy.template_custom in civikitchen.yaml.

Typical flow:
  civix generate:module org.example.myext
  /path/to/civikitchen/scaffold/ckinit ./org.example.myext   # runs this file in the image
TXT);
  exit($status);
}

$force = FALSE;
$mode = 'seed';
$positionals = [];
foreach (array_slice($argv, 1) as $arg) {
  if ($arg === '--force') {
    $force = TRUE;
  }
  elseif ($arg === '--update' || $arg === '--check') {
    if ($mode !== 'seed') {
      fwrite(STDERR, "ckinit: --update and --check are mutually exclusive\n");
      usage();
    }
    $mode = substr($arg, 2);
  }
  elseif ($arg === '-h' || $arg === '--help') {
    usage(0);
  }
  elseif (str_starts_with($arg, '-')) {
    fwrite(STDERR, "ckinit: unknown option: {$arg}\n");
    usage();
  }
  else {
    $positionals[] = $arg;
  }
}

if ($force && $mode !== 'seed') {
  // --force means "overwrite files the repo owns"; combined with --update it
  // would silently clobber composer.json and friends. Say what you mean.
  fwrite(STDERR, "ckinit: --force only applies to seeding, not --{$mode}\n");
  usage();
}

if (count($positionals) !== 1) {
  usage();
}

$target = realpath($positionals[0]);
if ($target === FALSE || !is_dir($target)) {
  fwrite(STDERR, "ckinit: target directory does not exist: {$positionals[0]}\n");
  exit(2);
}

// Same rule as ckconform's Context::repositoryRoot(), realpath on both sides so
// a /var vs /private/var target does not read as "below the root".
$repositoryRoot = repositoryRoot($target);
$belowRoot = $repositoryRoot !== NULL && $repositoryRoot !== $target;
$depthBelowRoot = $belowRoot
  ? count(array_filter(explode('/', substr($target, strlen((string) $repositoryRoot)))))
  : 0;

$infoPath = $target . '/info.xml';
if (!is_file($infoPath)) {
  if ($repositoryRoot === $target) {
    exit(runRootPass($target, $mode, $force, $yamlAutoload));
  }
  fwrite(STDERR, "ckinit: target is not a CiviCRM extension (missing info.xml): {$target}\n");
  exit(2);
}

$previous = libxml_use_internal_errors(TRUE);
$xml = simplexml_load_file($infoPath);
libxml_use_internal_errors($previous);
if ($xml === FALSE) {
  fwrite(STDERR, "ckinit: cannot parse {$infoPath}\n");
  exit(2);
}

$extensionFile = trim((string) $xml->file);
if ($extensionFile === '' || preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $extensionFile) !== 1) {
  fwrite(STDERR, "ckinit: info.xml has an invalid or missing <file> value\n");
  exit(2);
}
$scenarioName = strtolower((string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', $extensionFile));
if (preg_match('/^[a-z]/', $scenarioName) !== 1) $scenarioName = 'extension-' . $scenarioName;

// composer.json declares the licence info.xml does (ckconform license-coherence);
// composer spells the closed-source case `proprietary`.
$license = trim((string) $xml->license);
// Only a composer.json about to be written needs it; --check/--update leave a seeded one alone.
if ($license === '' && ($mode === 'seed' || !is_file($target . '/composer.json'))) {
  fwrite(STDERR, "ckinit: info.xml has no <license>; composer.json has to declare the same one\n");
  exit(2);
}
$composerLicense = json_encode(strcasecmp($license, 'Proprietary') === 0 ? 'proprietary' : $license, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

// Composer vendor from the reverse-domain key: `org.example.myext` -> `example`.
$keySegments = explode('.', trim((string) $xml['key']));
$vendor = count($keySegments) >= 3 ? $keySegments[count($keySegments) - 2] : '';
if (preg_match('/^[a-z0-9]([a-z0-9_.-]*[a-z0-9])?$/', $vendor) !== 1) {
  $vendor = 'example';
}

// Template files the repo has declared custom, from civikitchen.yaml (same KEY=VALUE
// format ckconform reads; first occurrence wins). The reason after ' -- ' is
// mandatory — an unexplained exception is indistinguishable from a stale one.
// For a MANAGED file, custom means "the content is this repo's own"; for a
// SEEDED file it means the repo owns the file's whole existence — including
// not having it (a repo with no PHP test suite and a declared tests policy
// has no business carrying a phpunit.xml.dist, and --update must not keep
// reseeding one). Names are validated against the full template inventory, so
// a typo still fails loudly instead of disabling nothing.
//
// The parser is ckconform's, required straight out of the checkout: ckinit
// runs on a bare runner with no image, so it cannot shell out to
// `ckconform --policy-env` the way the ck* tools do, but it can use the class.
require_once $yamlAutoload;
require_once dirname(__DIR__) . '/toolbelt/ckconform/src/Policy.php';

$custom = [];
$legacyPolicy = $target . '/' . \CiviKitchen\Ckconform\Policy::LEGACY_FILE;
if (is_file($legacyPolicy)) {
  fwrite(STDERR, "ckinit: legacy policy file is no longer supported; migrate it to civikitchen.yaml\n");
  exit(2);
}
$policyRaw = is_file($target . '/civikitchen.yaml') ? file_get_contents($target . '/civikitchen.yaml') : FALSE;
$releasesNothing = FALSE;
$onGitlab = FALSE;
$policy = is_string($policyRaw) ? parsedPolicy($policyRaw) : [];
$databaseImage = $policy['database'][0] ?? \CiviKitchen\Ckconform\Policy::DEFAULT_DATABASE;
if (is_string($policyRaw)) {
  // A repo that declares release: none has no caller to keep in line.
  $releasesNothing = str_starts_with($policy['release'][0] ?? '', 'none');
  $onGitlab = ($policy['ci'][0] ?? '') === 'gitlab';
  $declared = $policy['template_custom'] ?? [];
  // First occurrence wins; ckconform's policy-key check reports a second line
  // that would silently do nothing.
  foreach (array_slice($declared, 0, 1) as $value) {
    if (preg_match('/\s--\s\S/', $value) !== 1) {
      fwrite(STDERR, "ckinit: policy.template_custom in civikitchen.yaml needs paths and a reason\n");
      exit(2);
    }
    $value = (string) preg_replace('/\s--\s.*$/', '', $value);
    foreach (explode(',', $value) as $item) {
      $item = trim($item);
      if ($item === '') {
        continue;
      }
      if (!in_array($item, MANAGED_FILES, TRUE) && !in_array($item, SEEDED_FILES, TRUE)) {
        fwrite(STDERR, "ckinit: template_custom lists '{$item}', which is not a template file.\n");
        fwrite(STDERR, "Template files:\n  " . implode("\n  ", array_merge(MANAGED_FILES, SEEDED_FILES)) . "\n");
        exit(2);
      }
      $custom[$item] = TRUE;
    }
  }
}

// The root pass writes one GitHub caller for all extensions; nothing does that for GitLab yet.
if ($onGitlab && $belowRoot) {
  fwrite(STDERR, "ckinit: ci: gitlab supports a repository holding one extension at its root, not {$target}\n");
  exit(2);
}

// Seeding writes the template's composer.json, which declares no extra.strauss.
$composerPath = $target . '/composer.json';
$composer = $mode !== 'seed' && is_file($composerPath) ? json_decode((string) file_get_contents($composerPath), TRUE) : [];
if (!is_array($composer)) {
  fwrite(STDERR, "ckinit: cannot parse {$composerPath}\n");
  exit(2);
}
$usesStrauss = isset($composer['extra']) && is_array($composer['extra']) && isset($composer['extra']['strauss']);

// The Renovate preset the managed renovate.json extends. An organisation
// sets it once in the CK_DEFAULT_CONFIG file rather than per repo, which is
// why this goes through the layered view; the repo file can still override.
$renovatePreset = renovatePreset(is_string($policyRaw) ? $policyRaw : NULL);

$iterator = new RecursiveIteratorIterator(
  new RecursiveDirectoryIterator($templateDir, FilesystemIterator::SKIP_DOTS),
  RecursiveIteratorIterator::SELF_FIRST,
);

$files = [];
$inventory = [];
$conflicts = [];
foreach ($iterator as $item) {
  if ($item->isDir()) {
    continue;
  }
  $relative = substr($item->getPathname(), strlen($templateDir) + 1);
  $destination = $target . '/' . $relative;
  $content = file_get_contents($item->getPathname());
  if ($content === FALSE) {
    fwrite(STDERR, "ckinit: cannot read template file: {$relative}\n");
    exit(1);
  }
  $inventory[] = $relative;
  if (($belowRoot && in_array($relative, ROOT_ONLY_FILES, TRUE)) || ($releasesNothing && $relative === RELEASE_CALLER)
    || in_array($relative, $onGitlab ? GITHUB_FILES : GITLAB_FILES, TRUE)
    || (!$usesStrauss && in_array($relative, STRAUSS_FILES, TRUE))) {
    continue;
  }
  $rendered = str_replace(
      ['__EXTKEY__', '__EXTENSION_KEY__', '__SCENARIO_NAME__', '__VENDOR__', '__RENOVATE_PRESET__', '__DB_IMAGE__', '"__LICENSE__"'],
      [$extensionFile, trim((string) $xml['key']), $scenarioName, $vendor, $renovatePreset, $databaseImage, $composerLicense],
      $content,
    );
  if ($belowRoot && in_array($relative, COMPOSE_FILES, TRUE)) {
    $rendered = withRepositoryMount($rendered, $relative, $extensionFile, $depthBelowRoot);
  }
  // GitLab reads the database from a variable below the managed block, which
  // only a newly created caller gets from here.
  if ($relative === '.gitlab-ci.yml' && isset($policy['database']) && !file_exists($destination)) {
    $rendered .= "variables:\n  CK_DB_IMAGE: {$databaseImage}\n";
  }
  $files[] = [$destination, $relative, $item->getPerms() & 0777, $rendered];
  if ($mode === 'seed' && (file_exists($destination) || is_link($destination)) && !$force) {
    $conflicts[] = $relative;
  }
  for ($check = dirname($destination); str_starts_with($check, $target . '/'); $check = dirname($check)) {
    if (is_link($check)) {
      fwrite(STDERR, "ckinit: refusing destination below symlink: {$check}\n");
      exit(1);
    }
  }
}
usort($files, static fn (array $a, array $b): int => strcmp($a[1], $b[1]));

// The two lists must be an exact, disjoint inventory of the template. Checked
// on every run so the mismatch surfaces on the developer machine that added
// the file, not months later in some repo's CI.
$unclassified = array_diff($inventory, MANAGED_FILES, SEEDED_FILES);
$stale = array_diff(array_merge(MANAGED_FILES, SEEDED_FILES), $inventory);
$overlap = array_intersect(MANAGED_FILES, SEEDED_FILES);
// An optional file that is not seeded would be a file nothing ever creates.
$orphanOptional = array_diff(OPTIONAL_FILES, SEEDED_FILES);
if ($unclassified !== [] || $stale !== [] || $overlap !== [] || $orphanOptional !== []) {
  foreach ($unclassified as $relative) {
    fwrite(STDERR, "ckinit: template file not classified — add to MANAGED_FILES or SEEDED_FILES: {$relative}\n");
  }
  foreach ($stale as $relative) {
    fwrite(STDERR, "ckinit: listed file missing from the template: {$relative}\n");
  }
  foreach ($overlap as $relative) {
    fwrite(STDERR, "ckinit: file listed as both managed and seeded: {$relative}\n");
  }
  foreach ($orphanOptional as $relative) {
    fwrite(STDERR, "ckinit: optional file is not in SEEDED_FILES: {$relative}\n");
  }
  exit(2);
}

if ($conflicts !== []) {
  fwrite(STDERR, "ckinit: refusing to overwrite existing files:\n");
  foreach ($conflicts as $relative) {
    fwrite(STDERR, "  {$relative}\n");
  }
  fwrite(STDERR, "Re-run with --force only after reviewing these files,\n");
  fwrite(STDERR, "or --update to refresh just the template-managed files.\n");
  exit(1);
}

/** Does a parsed job publish through the shared release workflow? A dry run publishes nothing. */
function publishesRelease(mixed $job): bool {
  $uses = is_array($job) ? ($job['uses'] ?? NULL) : NULL;
  $dryRun = is_array($job) && is_array($job['with'] ?? NULL) ? ($job['with']['dry_run'] ?? FALSE) : FALSE;
  return is_string($uses) && basename(explode('@', $uses, 2)[0]) === SHARED_RELEASE
    && $dryRun !== TRUE && $dryRun !== 'true';
}

/** Whether a workflow has a job that publishes releases; one that does not parse cannot be ruled out. */
function workflowPublishesRelease(string $content): bool {
  try {
    $parsed = \Symfony\Component\Yaml\Yaml::parse($content);
  }
  catch (\Symfony\Component\Yaml\Exception\ParseException $e) {
    return TRUE;
  }
  $jobs = is_array($parsed) ? ($parsed['jobs'] ?? NULL) : NULL;
  return array_filter(is_array($jobs) ? $jobs : [], 'publishesRelease') !== [];
}

/**
 * Workflow files other than the managed caller whose jobs call the shared
 * release workflow; a file that does not parse is named as well.
 *
 * @return list<string>
 */
function otherReleaseCallers(string $target): array {
  $found = [];
  foreach (glob($target . '/.github/workflows/*.{yml,yaml}', GLOB_BRACE) ?: [] as $path) {
    $relative = substr($path, strlen($target) + 1);
    if ($relative === RELEASE_CALLER) {
      continue;
    }
    try {
      $parsed = \Symfony\Component\Yaml\Yaml::parse((string) file_get_contents($path));
    }
    catch (\Symfony\Component\Yaml\Exception\ParseException $e) {
      $found[] = "{$relative} (unparsable, cannot rule it out)";
      continue;
    }
    $jobs = is_array($parsed) ? ($parsed['jobs'] ?? NULL) : NULL;
    foreach (is_array($jobs) ? $jobs : [] as $job) {
      if (publishesRelease($job)) {
        $found[] = $relative;
        break;
      }
    }
  }
  return $found;
}

/**
 * Repository-owned lines of a managed file: anything but comments and blank
 * lines outside its managed blocks, or the whole file when it has none.
 *
 * @return list<string>
 */
function repositoryOwnedLines(string $content, string $relative): array {
  if (managedBlocks($content, $relative) === []) {
    return ['the whole file (no managed markers)'];
  }
  [$leading, $segments] = managedSegments($content);
  $outside = $leading . implode('', array_column($segments, 'trailing'));
  return array_values(array_filter(
    array_map('trim', explode("\n", $outside)),
    static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#'),
  ));
}

/**
 * The drift of a release caller left behind when nothing releases: NULL when
 * --update may delete it, else why it stays. A tag push would still publish.
 */
function staleReleaseCaller(string $destination, string $mode): ?string {
  $owned = repositoryOwnedLines((string) file_get_contents($destination), RELEASE_CALLER);
  if ($owned !== []) {
    return 'release: none, but it still publishes every tag and carries repository lines — delete it by hand: '
      . implode(' | ', $owned);
  }
  return $mode === 'check' ? 'release: none, but it still publishes every tag' : NULL;
}

function removeReleaseCaller(string $destination): void {
  if (!unlink($destination)) {
    fwrite(STDERR, 'ckinit: cannot delete ' . RELEASE_CALLER . "\n");
    exit(1);
  }
  fwrite(STDOUT, 'removed   ' . RELEASE_CALLER . " (release: none)\n");
}

/**
 * A release caller from before the markers, rewritten onto the template with
 * its caller job's `with:` and `secrets:` carried over as written. Returns the
 * new text, or NULL and the paths of repo-owned content it cannot place.
 *
 * @return array{?string, list<string>}
 */
function migrateReleaseCaller(string $existing, string $template): array {
  try {
    $old = \Symfony\Component\Yaml\Yaml::parse($existing);
  }
  catch (\Symfony\Component\Yaml\Exception\ParseException $e) {
    return [NULL, ['the whole file (' . $e->getMessage() . ')']];
  }
  $wanted = \Symfony\Component\Yaml\Yaml::parse($template);
  $jobs = is_array($old) && is_array($old['jobs'] ?? NULL) ? $old['jobs'] : [];
  $callers = array_keys(array_filter($jobs, 'publishesRelease'));
  if (count($callers) !== 1) {
    return [NULL, ['the whole file (no single job calls ' . SHARED_RELEASE . ')']];
  }
  $id = (string) $callers[0];
  $carried = array_intersect_key($jobs[$id], ['with' => TRUE, 'secrets' => TRUE]);

  // Everything but the carried inputs and the tag trigger must match the template.
  $rest = $old;
  unset($rest['jobs'][$id]);
  $rest['jobs']['release'] = array_diff_key($jobs[$id], $carried);
  if (isset($rest['on']['push']['tags'])) {
    $rest['on']['push']['tags'] = $wanted['on']['push']['tags'];
  }
  // Callers from before the markers name the repository's previous owner.
  if (is_string($rest['jobs']['release']['uses'] ?? NULL)) {
    $rest['jobs']['release']['uses'] = (string) preg_replace('#^jfilter/civikitchen/#i', 'civico-de/civikitchen/', $rest['jobs']['release']['uses']);
  }
  $lost = yamlDifferences($rest, $wanted);
  if ($id !== 'release') {
    $lost[] = "jobs.{$id} (job id)";
  }
  if ($lost !== []) {
    return [NULL, $lost];
  }

  $migrated = $template . carriedInputs($existing, $id);
  $parsed = \Symfony\Component\Yaml\Yaml::parse($migrated);
  $now = array_intersect_key($parsed['jobs']['release'] ?? [], ['with' => TRUE, 'secrets' => TRUE]);
  ksort($now);
  ksort($carried);
  if ($now !== $carried) {
    return [NULL, ["jobs.{$id}.with / jobs.{$id}.secrets (not carried over intact)"]];
  }
  return [$migrated, []];
}

/**
 * Dotted paths where $have differs from $want: keys only one side has, and
 * values that differ.
 *
 * @return list<string>
 */
function yamlDifferences(mixed $have, mixed $want, string $path = ''): array {
  if (!is_array($have) || !is_array($want) || array_is_list($have) || array_is_list($want)) {
    return $have === $want ? [] : [$path === '' ? 'the whole file' : $path];
  }
  $differences = [];
  foreach (array_unique(array_merge(array_keys($have), array_keys($want))) as $key) {
    $at = $path === '' ? (string) $key : "{$path}.{$key}";
    if (!array_key_exists($key, $have) || !array_key_exists($key, $want)) {
      $differences[] = $at;
      continue;
    }
    array_push($differences, ...yamlDifferences($have[$key], $want[$key], $at));
  }
  return $differences;
}

/**
 * The `with:` and `secrets:` blocks of job $id as written, comments included,
 * re-indented to the template's four-space job keys.
 */
function carriedInputs(string $existing, string $id): string {
  $lines = preg_split('/(?<=\n)/', $existing) ?: [];
  $jobIndent = NULL;
  $keyIndent = NULL;
  $capturing = FALSE;
  $pending = '';
  $out = '';
  foreach ($lines as $line) {
    $indent = strlen($line) - strlen(ltrim($line, ' '));
    $blank = trim($line) === '';
    $comment = str_starts_with(ltrim($line), '#');
    if ($jobIndent === NULL) {
      if (preg_match('/^(\s+)' . preg_quote($id, '/') . ':\s*(#.*)?$/', rtrim($line, "\n"), $m) === 1) {
        $jobIndent = strlen($m[1]);
      }
      continue;
    }
    if (!$blank && !$comment && $indent <= $jobIndent) {
      break;
    }
    if ($blank) {
      if ($capturing) {
        $pending .= $line;
      }
      continue;
    }
    $keyIndent ??= $indent;
    if ($comment && $indent <= $keyIndent) {
      $pending .= $line;
      continue;
    }
    if (!$comment && $indent === $keyIndent) {
      $capturing = preg_match('/^\s*(with|secrets):/', $line) === 1;
      if (!$capturing) {
        $pending = '';
        continue;
      }
    }
    if ($capturing) {
      $out .= $pending . $line;
    }
    $pending = '';
  }
  if ($capturing && $pending !== '' && trim($pending) !== '') {
    $out .= $pending;
  }
  if (trim($out) === '') {
    return '';
  }
  $shift = 4 - (int) $keyIndent;
  return (string) preg_replace_callback('/^( *)(?=\S)/m', static fn (array $m): string =>
    str_repeat(' ', max(0, strlen($m[1]) + $shift)), rtrim($out) . "\n");
}

/** The git repository root at or above $directory, NULL when there is none. */
function repositoryRoot(string $directory): ?string {
  $directory = rtrim((string) realpath($directory), '/');
  while ($directory !== '' && $directory !== dirname($directory)) {
    if (file_exists($directory . '/.git')) {
      return $directory;
    }
    $directory = dirname($directory);
  }
  return NULL;
}

/**
 * A civikitchen.yaml's policy view; an invalid file ends the run.
 *
 * @return array<string, list<string>>
 */
function parsedPolicy(string $raw): array {
  try {
    return \CiviKitchen\Ckconform\Policy::parse($raw);
  }
  catch (\RuntimeException $e) {
    fwrite(STDERR, "ckinit: {$e->getMessage()}\n");
    exit(2);
  }
}

/**
 * The database image a .gitlab-ci.yml runs: its CK_DB_IMAGE, else the shared
 * pipeline's default. NULL when the file is absent or does not parse.
 */
function gitlabDatabase(string $file): ?string {
  try {
    $parsed = is_file($file) ? \Symfony\Component\Yaml\Yaml::parse((string) file_get_contents($file), \Symfony\Component\Yaml\Yaml::PARSE_CUSTOM_TAGS) : NULL;
  }
  catch (\Symfony\Component\Yaml\Exception\ParseException $e) {
    return NULL;
  }
  if (!is_array($parsed)) {
    return NULL;
  }
  $variable = is_array($parsed['variables'] ?? NULL) ? ($parsed['variables']['CK_DB_IMAGE'] ?? NULL) : NULL;
  $value = is_array($variable) ? ($variable['value'] ?? NULL) : $variable;
  return is_string($value) ? $value : \CiviKitchen\Ckconform\Policy::DEFAULT_DATABASE;
}

/** The validated Renovate preset for a policy file's contents (NULL: no file). */
function renovatePreset(?string $policyRaw): string {
  try {
    $effective = \CiviKitchen\Ckconform\Policy::effective($policyRaw);
  }
  catch (\RuntimeException $e) {
    fwrite(STDERR, "ckinit: {$e->getMessage()}\n");
    exit(2);
  }
  $preset = \CiviKitchen\Ckconform\Policy::stripReason($effective['renovate_preset'][0] ?? 'config:recommended');
  if (preg_match('#^[A-Za-z0-9][A-Za-z0-9_.:/>@-]*$#', $preset) !== 1) {
    fwrite(STDERR, "ckinit: renovate_preset '{$preset}' is not a Renovate preset name (e.g. github>org/renovate)\n");
    exit(2);
  }
  return $preset;
}

/**
 * The compose file with the repository root mounted next to the extension
 * directory: below the root, `..` is the extension, so `.git` stays outside.
 */
function withRepositoryMount(string $content, string $relative, string $extensionFile, int $depth): string {
  $needle = "      - ..:/var/www/html/ext/{$extensionFile}\n";
  if (substr_count($content, $needle) !== 1) {
    fwrite(STDERR, "ckinit: {$relative}: cannot place the repository mount — the extension mount line is missing\n");
    exit(1);
  }
  $up = '..' . str_repeat('/..', $depth);
  return str_replace(
    $needle,
    $needle
      . "      # The repository root: .git for cklint, ckfmt and ckconform.\n"
      . "      - {$up}:/civikitchen-repo\n",
    $content,
  );
}

/** The workflow job id for an extension directory: job ids are [A-Za-z_][A-Za-z0-9_-]*. */
function jobId(string $directory): string {
  $job = (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $directory);
  return preg_match('/^[A-Za-z_]/', $job) === 1 ? $job : 'ext_' . $job;
}

/**
 * The root caller workflow: one managed block per extension directory holding
 * the job id, the `uses:` line and working_directory. `with:` inputs the
 * repository adds after a block's END marker are its own.
 */
function rootCallerYaml(array $extensions): string {
  $out = <<<'TXT'
# Thin caller for a repository of several extensions: one job per extension
# directory, each calling civikitchen's reusable extension-ci.yml for that
# directory, so the pipeline is defined once instead of per extension.
#
# Managed by ckinit: the job id, the `uses:` line and working_directory. Further
# `with:` inputs for a job go after its END marker and survive `ckinit
# --update`; an extension directory without a job, or a job whose directory is
# gone, is reported by `ckinit --check`.
#
# The @v1 pin is the versioned contract: workflow, template, ck* tools and
# images are released together. See civikitchen's docs/releases.md.
# BEGIN CIVIKITCHEN MANAGED header
name: CI

on:
  push:
    branches: [main]
  pull_request:
  # Manual run: a reusable-workflow rerun reuses the ref it first resolved, so
  # picking up a moved civikitchen tag needs a fresh event.
  workflow_dispatch:

# The jobs only read the repo; do not inherit the org default token.
permissions:
  contents: read

jobs:
# END CIVIKITCHEN MANAGED header

TXT;
  foreach ($extensions as $directory) {
    $out .= "  # BEGIN CIVIKITCHEN MANAGED job-{$directory}\n"
      . '  ' . jobId($directory) . ":\n"
      . "    uses: civico-de/civikitchen/.github/workflows/extension-ci.yml@v1\n"
      . "    with:\n"
      . "      working_directory: {$directory}\n"
      . "  # END CIVIKITCHEN MANAGED job-{$directory}\n";
  }
  return $out;
}

/**
 * The root release caller: one managed build job per releasing extension,
 * needing the jobs of the same-repository extensions it requires, and one
 * publish job needing them all. Inputs after a block's END marker are the repo's.
 *
 * @param array<string, list<string>> $needs directory => directories it requires
 */
function rootReleaseYaml(array $needs): string {
  $out = <<<'TXT'
# Thin caller for a repository of several extensions, released in lockstep: one
# vX.Y.Z tag releases all of them. Each extension's job builds, verifies and
# smoke-tests its archive with civikitchen's reusable extension-release.yml;
# the publish job creates the one GitHub release and attaches every archive.
#
# Managed by ckinit: job ids, `uses:`, working_directory, stage and needs.
# Further `with:` inputs and `secrets:` for a job go after its END marker and
# survive `ckinit --update`. An extension that never releases declares
# `release: none` with a reason in its civikitchen.yaml and gets no job.
#
# The @v1 pin is the versioned contract, the same one ci.yml follows. See
# civikitchen's docs/extension-releases.md.
# BEGIN CIVIKITCHEN MANAGED header
name: Release

on:
  push:
    tags: ['v[0-9]+.[0-9]+.[0-9]+', 'v[0-9]+.[0-9]+.[0-9]+-*']

permissions:
  contents: read

jobs:
# END CIVIKITCHEN MANAGED header

TXT;
  $jobList = static fn (array $directories): string =>
    '[' . implode(', ', array_map('jobId', $directories)) . ']';
  foreach ($needs as $directory => $required) {
    $out .= "  # BEGIN CIVIKITCHEN MANAGED job-{$directory}\n"
      . '  ' . jobId($directory) . ":\n"
      . ($required === [] ? '' : '    needs: ' . $jobList($required) . "\n")
      . "    permissions:\n"
      . "      contents: write        # a called workflow can only narrow this\n"
      . "    uses: civico-de/civikitchen/.github/workflows/extension-release.yml@v1\n"
      . "    with:\n"
      . "      working_directory: {$directory}\n"
      . "      stage: build\n"
      . "  # END CIVIKITCHEN MANAGED job-{$directory}\n";
  }
  return $out . "  # BEGIN CIVIKITCHEN MANAGED publish\n"
    . '  ' . RELEASE_PUBLISH_JOB . ":\n"
    . '    needs: ' . $jobList(array_keys($needs)) . "\n"
    . "    permissions:\n"
    . "      contents: write        # creates the release\n"
    . "    uses: civico-de/civikitchen/.github/workflows/extension-release.yml@v1\n"
    . "    with:\n"
    . "      stage: publish\n"
    . "  # END CIVIKITCHEN MANAGED publish\n";
}

/**
 * Directory => same-repository directories it requires, for every extension
 * that releases; NULL after reporting a layout no release caller can build.
 *
 * @param list<string> $extensions
 * @return array<string, list<string>>|null
 */
function releaseNeeds(string $root, array $extensions): ?array {
  $keys = [];
  $requires = [];
  $releasing = [];
  foreach ($extensions as $directory) {
    $previous = libxml_use_internal_errors(TRUE);
    $xml = simplexml_load_file("{$root}/{$directory}/info.xml");
    libxml_use_internal_errors($previous);
    if ($xml === FALSE) {
      fwrite(STDERR, "ckinit: cannot parse {$directory}/info.xml\n");
      return NULL;
    }
    $keys[trim((string) $xml['key'])] = $directory;
    $requires[$directory] = array_map(static fn ($ext): string => trim((string) $ext), $xml->xpath('requires/ext') ?: []);
    $policy = "{$root}/{$directory}/civikitchen.yaml";
    $declared = is_file($policy) ? (parsedPolicy((string) file_get_contents($policy))['release'][0] ?? '') : '';
    if (!str_starts_with($declared, 'none')) {
      $releasing[] = $directory;
    }
  }
  $needs = [];
  foreach ($releasing as $directory) {
    if (jobId($directory) === RELEASE_PUBLISH_JOB) {
      fwrite(STDERR, "ckinit: the extension directory '{$directory}' needs the release job id '"
        . RELEASE_PUBLISH_JOB . "', which publishes the release; rename the directory\n");
      return NULL;
    }
    $needs[$directory] = [];
    foreach ($requires[$directory] as $key) {
      if (!isset($keys[$key])) {
        continue;
      }
      if (!in_array($keys[$key], $releasing, TRUE)) {
        fwrite(STDERR, "ckinit: {$directory} requires {$keys[$key]}, which declares release: none — "
          . "a lockstep release cannot install {$directory} without it\n");
        return NULL;
      }
      $needs[$directory][] = $keys[$key];
    }
  }
  return $needs;
}

/**
 * The repository-root pass: stamp the root-only files, then run the ordinary
 * per-extension pass for every direct subdirectory that is an extension.
 */
function runRootPass(string $root, string $mode, bool $force, string $yamlAutoload): int {
  $extensions = [];
  $jobIds = [];
  foreach (scandir($root) ?: [] as $entry) {
    $path = $root . '/' . $entry;
    // Dot-directories are never extensions of the repository: in CI the
    // workspace root also holds the .civikitchen-* helper checkouts.
    if (str_starts_with($entry, '.') || is_link($path) || !is_dir($path) || !is_file($path . '/info.xml')) {
      continue;
    }
    if (preg_match('/^\S+$/', $entry) !== 1) {
      fwrite(STDERR, "ckinit: extension directory name cannot carry whitespace: {$entry}\n");
      return 2;
    }
    // Two directories that differ only in characters a job id cannot carry
    // would collide into one job, and one extension would never be built.
    $job = jobId($entry);
    if (isset($jobIds[$job])) {
      fwrite(STDERR, "ckinit: the extension directories '{$jobIds[$job]}' and '{$entry}' both need "
        . "the CI job id '{$job}'; rename one of them\n");
      return 2;
    }
    $jobIds[$job] = $entry;
    $extensions[] = $entry;
  }
  sort($extensions);
  if ($extensions === []) {
    fwrite(STDERR, "ckinit: {$root} has no info.xml, and no direct subdirectory is an extension\n");
    return 2;
  }

  require_once $yamlAutoload;
  require_once dirname(__DIR__) . '/toolbelt/ckconform/src/Policy.php';
  $policyFile = $root . '/civikitchen.yaml';
  $rootPolicy = is_file($policyFile) ? (string) file_get_contents($policyFile) : NULL;
  if ($rootPolicy !== NULL && (parsedPolicy($rootPolicy)['ci'][0] ?? '') === 'gitlab') {
    fwrite(STDERR, "ckinit: ci: gitlab supports a repository holding one extension at its root, not {$root}\n");
    return 2;
  }
  $preset = renovatePreset($rootPolicy);

  $needs = releaseNeeds($root, $extensions);
  if ($needs === NULL) {
    return 2;
  }

  $templateDir = __DIR__ . '/template/extension';
  $rootFiles = [
    '.gitattributes' => (string) file_get_contents($templateDir . '/.gitattributes'),
    '.github/workflows/ci.yml' => rootCallerYaml($extensions),
    'renovate.json' => str_replace(
      '__RENOVATE_PRESET__',
      $preset,
      (string) file_get_contents($templateDir . '/renovate.json'),
    ),
  ];
  // Like a single extension's: no caller when nothing releases.
  if ($needs !== []) {
    $rootFiles[RELEASE_CALLER] = rootReleaseYaml($needs);
  }
  $failed = [];
  $releaseDestination = $root . '/' . RELEASE_CALLER;
  if ($needs === [] && $mode !== 'seed' && (is_file($releaseDestination) || is_link($releaseDestination))) {
    assertRegular($releaseDestination, RELEASE_CALLER);
    if (workflowPublishesRelease((string) file_get_contents($releaseDestination))) {
      $why = staleReleaseCaller($releaseDestination, $mode);
      if ($why === NULL) {
        removeReleaseCaller($releaseDestination);
      }
      else {
        fwrite(STDOUT, 'drifted   ' . RELEASE_CALLER . " ({$why})\n");
        $failed[] = RELEASE_CALLER;
      }
    }
  }

  if ($mode === 'seed' && !$force) {
    $conflicts = array_filter(array_keys($rootFiles), static fn (string $r): bool => file_exists($root . '/' . $r));
    if ($conflicts !== []) {
      fwrite(STDERR, "ckinit: refusing to overwrite existing files:\n  " . implode("\n  ", $conflicts) . "\n");
      fwrite(STDERR, "Re-run with --force, or --update to refresh just the template-managed files.\n");
      return 1;
    }
  }

  foreach ($rootFiles as $relative => $content) {
    $destination = $root . '/' . $relative;
    assertRegular($destination, $relative);
    $others = $relative === RELEASE_CALLER ? otherReleaseCallers($root) : [];
    if ($others !== []) {
      fwrite(STDOUT, "drifted   {$relative} (" . implode(', ', $others) . ' also calls ' . SHARED_RELEASE
        . "; two callers would publish every tag twice)\n");
      $failed[] = $relative;
      continue;
    }
    if (!is_file($destination)) {
      if ($mode === 'check') {
        fwrite(STDOUT, "missing   {$relative}\n");
        $failed[] = $relative;
      }
      else {
        writeRendered($destination, $relative, 0644, $content);
        fwrite(STDOUT, "created   {$relative}\n");
      }
      continue;
    }
    $existing = (string) file_get_contents($destination);
    if ($existing === $content) {
      continue;
    }
    $blocks = managedBlocks($content, $relative);
    $rendered = $content;
    $repoBlocks = managedBlocks($existing, $relative);
    // A hand-written caller holds inputs and secrets no marker says are the repository's.
    if ($blocks !== [] && $repoBlocks === []) {
      fwrite(STDOUT, "drifted   {$relative} (no managed markers — ckinit would drop its inputs and secrets; "
        . "replace it by hand with the managed jobs, repository lines after their END markers)\n");
      $failed[] = $relative;
      continue;
    }
    if ($blocks !== [] && $repoBlocks !== []) {
      // One block per extension directory, so a changed set is an added or
      // removed extension: --update reconciles it, --check reports it.
      // By name, not by position: a job appended by --update sits last whatever
      // the directory sorts as, and job order in the workflow is cosmetic.
      $repoNames = array_keys($repoBlocks);
      $wantedNames = array_keys($blocks);
      sort($repoNames);
      sort($wantedNames);
      $changedSet = $repoNames !== $wantedNames;
      $rendered = reconcileRootJobs($existing, $blocks, $relative, $mode !== 'check');
      if ($rendered === NULL) {
        fwrite(STDOUT, "drifted   {$relative} (managed blocks do not match the extension directories: "
          . implode(', ', array_keys($blocks)) . " — align the markers by hand)\n");
        $failed[] = $relative;
        continue;
      }
      if ($mode === 'check' && $changedSet) {
        fwrite(STDOUT, "drifted   {$relative} (one job per extension directory: "
          . implode(', ', array_keys($blocks)) . ")\n");
        $failed[] = $relative;
        continue;
      }
      if ($rendered === $existing) {
        continue;
      }
    }
    if ($mode === 'check') {
      fwrite(STDOUT, "drifted   {$relative}\n");
      $failed[] = $relative;
    }
    else {
      writeRendered($destination, $relative, 0644, $rendered);
      fwrite(STDOUT, "updated   {$relative}\n");
    }
  }

  $arguments = $mode === 'seed' ? ($force ? ['--force'] : []) : ['--' . $mode];
  foreach ($extensions as $directory) {
    fwrite(STDOUT, "\n== {$directory}\n");
    $command = array_merge([PHP_BINARY, __FILE__], $arguments, [$root . '/' . $directory]);
    $status = 0;
    passthru(implode(' ', array_map('escapeshellarg', $command)), $status);
    if ($status !== 0) {
      $failed[] = $directory;
    }
  }

  if ($failed !== []) {
    fwrite(STDERR, "\nckinit: " . count($failed) . " root file(s) / extension(s) need attention: "
      . implode(', ', $failed) . "\n");
    return 1;
  }
  fwrite(STDOUT, "\nRoot files and " . count($extensions) . " extension(s) are in shape.\n");
  return 0;
}

/**
 * The managed blocks of a file, in order: name => text including both marker
 * lines. A file without markers is whole-file managed. Unbalanced or repeated
 * markers are a template or repo error, never silently a smaller block.
 *
 * @return array<string, string>
 */
function managedBlocks(string $content, string $relative): array {
  $blocks = [];
  $open = NULL;
  $buffer = '';
  foreach (preg_split('/(?<=\n)/', $content) ?: [] as $line) {
    if (preg_match('/^\s*#\s*(BEGIN|END) CIVIKITCHEN MANAGED (\S+)\s*$/', $line, $m) === 1) {
      if ($m[1] === 'BEGIN') {
        if ($open !== NULL || isset($blocks[$m[2]])) {
          fwrite(STDERR, "ckinit: {$relative}: managed block '{$m[2]}' opened twice or inside '{$open}'\n");
          exit(1);
        }
        $open = $m[2];
        $buffer = $line;
        continue;
      }
      if ($open !== $m[2]) {
        fwrite(STDERR, "ckinit: {$relative}: END of managed block '{$m[2]}' without its BEGIN\n");
        exit(1);
      }
      $blocks[$open] = $buffer . $line;
      $open = NULL;
      continue;
    }
    if ($open !== NULL) {
      $buffer .= $line;
    }
  }
  if ($open !== NULL) {
    fwrite(STDERR, "ckinit: {$relative}: managed block '{$open}' is never closed\n");
    exit(1);
  }
  return $blocks;
}

/**
 * The repo file with every template block's text swapped in, or NULL when
 * the repo's blocks do not match the template's set (a block missing, an
 * unknown one, or a different order) — that needs a person, not a rewrite.
 */
function spliceBlocks(string $repoContent, array $templateBlocks, string $relative): ?string {
  $repoBlocks = managedBlocks($repoContent, $relative);
  if (array_keys($repoBlocks) !== array_keys($templateBlocks)) {
    return NULL;
  }
  $out = $repoContent;
  foreach ($repoBlocks as $name => $text) {
    $at = strpos($out, $text);
    if ($at === FALSE) {
      return NULL;
    }
    $out = substr($out, 0, $at) . $templateBlocks[$name] . substr($out, $at + strlen($text));
  }
  return $out;
}

/**
 * The file split into the text before the first managed block and one segment
 * per block: name, the block including both markers, and the repo-owned lines
 * that follow it. Call only on content managedBlocks() has accepted.
 *
 * @return array{string, list<array{name: string, block: string, trailing: string}>}
 */
function managedSegments(string $content): array {
  $leading = '';
  $segments = [];
  $current = NULL;
  $inBlock = FALSE;
  foreach (preg_split('/(?<=\n)/', $content) ?: [] as $line) {
    if (preg_match('/^\s*#\s*BEGIN CIVIKITCHEN MANAGED (\S+)\s*$/', $line, $m) === 1) {
      if ($current !== NULL) {
        $segments[] = $current;
      }
      $current = ['name' => $m[1], 'block' => $line, 'trailing' => ''];
      $inBlock = TRUE;
      continue;
    }
    if ($inBlock && preg_match('/^\s*#\s*END CIVIKITCHEN MANAGED \S+\s*$/', $line) === 1) {
      $current['block'] .= $line;
      $inBlock = FALSE;
      continue;
    }
    if ($current === NULL) {
      $leading .= $line;
    }
    elseif ($inBlock) {
      $current['block'] .= $line;
    }
    else {
      $current['trailing'] .= $line;
    }
  }
  if ($current !== NULL) {
    $segments[] = $current;
  }
  return [$leading, $segments];
}

/**
 * The root workflow with its job blocks reconciled against the extension
 * directories: blocks the repo has are refreshed in place and keep the lines
 * that follow them, a directory without a block gets one appended, a block
 * without a directory is dropped. NULL when that needs a person.
 */
function reconcileRootJobs(string $existing, array $templateBlocks, string $relative, bool $apply): ?string {
  [$leading, $segments] = managedSegments($existing);
  $present = array_column($segments, 'name');
  // Without the header block the file's shape is unknown, and appending the
  // template's header at the end would produce nonsense.
  if (!in_array((string) array_key_first($templateBlocks), $present, TRUE)) {
    return NULL;
  }
  $out = $leading;
  foreach ($segments as $segment) {
    if (!isset($templateBlocks[$segment['name']])) {
      if (trim($segment['trailing']) !== '') {
        if (!$apply) {
          return NULL;
        }
        fwrite(STDERR, "ckinit: {$relative}: '{$segment['name']}' has no extension directory any more, "
          . "but repository lines follow it:\n");
        foreach (explode("\n", rtrim($segment['trailing'], "\n")) as $line) {
          fwrite(STDERR, "  {$line}\n");
        }
        fwrite(STDERR, "Delete them, or restore the directory — ckinit does not guess where they belong.\n");
        exit(1);
      }
      continue;
    }
    $out .= $templateBlocks[$segment['name']] . $segment['trailing'];
  }
  foreach ($templateBlocks as $name => $text) {
    if (!in_array($name, $present, TRUE)) {
      $out .= $text;
    }
  }
  return $out;
}

function writeRendered(string $destination, string $relative, int $perms, string $content): void {
  $parent = dirname($destination);
  if (!is_dir($parent) && !mkdir($parent, 0775, TRUE) && !is_dir($parent)) {
    fwrite(STDERR, "ckinit: cannot create directory: {$parent}\n");
    exit(1);
  }
  $temporary = tempnam($parent, '.ckinit-');
  if ($temporary === FALSE || file_put_contents($temporary, $content) === FALSE) {
    fwrite(STDERR, "ckinit: cannot write: {$destination}\n");
    exit(1);
  }
  if (!chmod($temporary, $perms)) {
    @unlink($temporary);
    fwrite(STDERR, "ckinit: cannot set mode on: {$destination}\n");
    exit(1);
  }
  if (!rename($temporary, $destination)) {
    @unlink($temporary);
    fwrite(STDERR, "ckinit: cannot replace: {$destination}\n");
    exit(1);
  }
}

/** A destination we may only replace if it is an ordinary file (or absent). */
function assertRegular(string $destination, string $relative): void {
  if ((file_exists($destination) || is_link($destination))
    && (is_link($destination) || !is_file($destination))) {
    fwrite(STDERR, "ckinit: refusing non-regular destination: {$relative}\n");
    exit(1);
  }
}

if ($mode === 'seed') {
  // All-or-nothing: refuse strange destinations before the first write.
  foreach ($files as [$destination, $relative]) {
    assertRegular($destination, $relative);
  }
  foreach ($files as [$destination, $relative, $perms, $content]) {
    writeRendered($destination, $relative, $perms, $content);
    fwrite(STDOUT, "created {$relative}\n");
  }
  fwrite(STDOUT, "\nCiviKitchen tooling installed for {$extensionFile}.\n");
  fwrite(STDOUT, "Next: review composer.json and .docker/, then start the stack and run ck ci in it (.docker/docker-compose.yml says how).\n");
  exit(0);
}

// The release caller is checked before anything is written: a caller in
// another workflow, or repo-owned content --update cannot carry over, stops it.
$blocked = [];
foreach ($files as $index => [$destination, $relative, , $content]) {
  if ($relative !== RELEASE_CALLER || isset($custom[$relative])) {
    continue;
  }
  $others = otherReleaseCallers($target);
  if ($others !== []) {
    $blocked[$relative] = implode(', ', $others) . ' also calls ' . SHARED_RELEASE . '; two callers would publish every tag twice';
    continue;
  }
  if (!is_file($destination)) {
    continue;
  }
  $existing = (string) file_get_contents($destination);
  if (managedBlocks($existing, $relative) !== []) {
    continue;
  }
  [$migrated, $lost] = migrateReleaseCaller($existing, $content);
  if ($lost !== []) {
    $blocked[$relative] = 'would drop: ' . implode(', ', $lost);
    continue;
  }
  $files[$index][3] = (string) $migrated;
}
$retire = FALSE;
$releaseDestination = $target . '/' . RELEASE_CALLER;
if ($releasesNothing && !$belowRoot && !isset($custom[RELEASE_CALLER]) && (is_file($releaseDestination) || is_link($releaseDestination))) {
  assertRegular($releaseDestination, RELEASE_CALLER);
  // A release.yml that calls no release (a docs build on tags) is the repository's.
  if (workflowPublishesRelease((string) file_get_contents($releaseDestination))) {
    $why = staleReleaseCaller($releaseDestination, $mode);
    if ($why === NULL) {
      $retire = TRUE;
    }
    else {
      $blocked[RELEASE_CALLER] = $why;
    }
  }
}
// GitLab runs CK_DB_IMAGE, not the CI compose file, and that variable sits
// below the managed block where --update does not write.
if ($onGitlab && isset($policy['database']) && !isset($custom['.gitlab-ci.yml'])) {
  $gitlabFile = $target . '/.gitlab-ci.yml';
  $gitlabDatabase = gitlabDatabase($gitlabFile);
  if ($gitlabDatabase === NULL && is_file($gitlabFile)) {
    $blocked['.gitlab-ci.yml'] = 'cannot read its CK_DB_IMAGE: the file does not parse';
  }
  elseif ($gitlabDatabase !== NULL && $gitlabDatabase !== $databaseImage) {
    $blocked['.gitlab-ci.yml'] = "policy.database is {$databaseImage}, but CK_DB_IMAGE is {$gitlabDatabase}; set CK_DB_IMAGE: {$databaseImage} under variables below the managed block";
  }
}
if ($blocked !== [] && $mode === 'update') {
  foreach ($blocked as $relative => $why) {
    fwrite(STDERR, "ckinit: refusing to update {$relative}: {$why}\n");
  }
  fwrite(STDERR, "Nothing was written. Fix that by hand, then run --update again.\n");
  exit(1);
}

// --update / --check: managed files converge on the template, missing files
// (managed or seeded) count, seeded files the repo edited are its business.
$drifted = [];
$missing = [];
foreach ($files as [$destination, $relative, $perms, $content]) {
  $managed = in_array($relative, MANAGED_FILES, TRUE);
  if (isset($blocked[$relative])) {
    fwrite(STDOUT, "drifted   {$relative} ({$blocked[$relative]})\n");
    $drifted[] = $relative;
    continue;
  }
  if (isset($custom[$relative])) {
    fwrite(STDOUT, "custom    {$relative} (civikitchen.yaml template_custom)\n");
    continue;
  }
  assertRegular($destination, $relative);
  if (!is_file($destination)) {
    if (in_array($relative, OPTIONAL_FILES, TRUE)) {
      fwrite(STDOUT, "optional  {$relative} (absent — opt-in)\n");
      continue;
    }
    $missing[] = $relative;
    if ($mode === 'update') {
      writeRendered($destination, $relative, $perms, $content);
      fwrite(STDOUT, "created   {$relative}\n");
    }
    else {
      fwrite(STDOUT, "missing   {$relative}\n");
    }
    continue;
  }
  // Content plus the executable bit — the one mode bit git actually tracks,
  // so comparing full permissions would drift with the checkout umask.
  $sameExec = (($perms & 0111) !== 0) === ((fileperms($destination) & 0111) !== 0);
  $existing = (string) file_get_contents($destination);
  if (!$managed || ($existing === $content && $sameExec)) {
    continue;
  }
  // Block-managed: the repo's version converges block by block; a repo file
  // that predates the markers is whole-file drift and gets the template.
  $blocks = managedBlocks($content, $relative);
  $rendered = $content;
  if ($blocks !== [] && managedBlocks($existing, $relative) !== []) {
    $rendered = spliceBlocks($existing, $blocks, $relative);
    if ($rendered === NULL) {
      $drifted[] = $relative;
      fwrite(STDOUT, "drifted   {$relative} (managed blocks do not match the template's: "
        . implode(', ', array_keys($blocks)) . " — align the markers by hand)\n");
      continue;
    }
    if ($rendered === $existing && $sameExec) {
      continue;
    }
  }
  $drifted[] = $relative;
  if ($mode === 'update') {
    writeRendered($destination, $relative, $perms, $rendered);
    fwrite(STDOUT, "updated   {$relative}\n");
  }
  else {
    fwrite(STDOUT, "drifted   {$relative}\n");
  }
}

// Not among the rendered files when nothing releases, so reported here.
if ($retire) {
  removeReleaseCaller($releaseDestination);
  $drifted[] = RELEASE_CALLER;
}
elseif ($releasesNothing && isset($blocked[RELEASE_CALLER])) {
  fwrite(STDOUT, 'drifted   ' . RELEASE_CALLER . " ({$blocked[RELEASE_CALLER]})\n");
  $drifted[] = RELEASE_CALLER;
}

if ($mode === 'update') {
  if ($drifted === [] && $missing === []) {
    fwrite(STDOUT, "Template files are up to date for {$extensionFile}.\n");
  }
  else {
    fwrite(STDOUT, "\nRefreshed " . count($drifted) . " managed / created " . count($missing)
      . " missing file(s) for {$extensionFile}. Review with git diff before committing.\n");
  }
  exit(0);
}

if ($drifted === [] && $missing === []) {
  fwrite(STDOUT, "Template files are up to date for {$extensionFile}.\n");
  exit(0);
}
fwrite(STDERR, "\nckinit: " . count($drifted) . " drifted / " . count($missing)
  . " missing template file(s). Run scaffold/ckinit.php --update <dir> to refresh,\n"
  . "or declare a deliberate deviation under policy.template_custom in civikitchen.yaml\n");
exit(1);
