<?php

declare(strict_types=1);

/**
 * policy.database only offers what the image build verifies, and the release
 * smoke test and the GitLab pipeline fall back to the default ckinit renders.
 *
 * Usage: php database-policy.php <schema.json> <build-dev-images.yml> <extension-release.yml> <gitlab extension-ci.yml>
 */

require_once __DIR__ . '/workflow-jobs.php';
require_once dirname(__DIR__, 2) . '/toolbelt/ckconform/src/Policy.php';

use CiviKitchen\Ckconform\Policy;

[, $schemaFile, $buildFile, $releaseFile, $gitlabFile] = $argv + [null, null, null, null, null];
if ($gitlabFile === null) {
    fwrite(STDERR, "usage: database-policy.php <schema.json> <build-dev-images.yml> <extension-release.yml> <gitlab extension-ci.yml>\n");
    exit(2);
}

$problems = [];
$schema = json_decode((string) file_get_contents($schemaFile), true, 512, JSON_THROW_ON_ERROR);
$offered = $schema['$defs']['policy']['properties']['database']['enum'] ?? [];
$verified = ck_jobs_parsed($buildFile)['database-compat-standalone']['strategy']['matrix']['database'] ?? [];
foreach (array_diff($offered, $verified) as $image) {
    $problems[] = "$schemaFile: policy.database offers $image, which the database-compat-standalone matrix does not verify";
}
if (!in_array(Policy::DEFAULT_DATABASE, $offered, true)) {
    $problems[] = "$schemaFile: policy.database does not offer the default " . Policy::DEFAULT_DATABASE;
}

$resolver = null;
$smokeDatabase = null;
foreach (ck_jobs_parsed($releaseFile) as $job) {
    foreach ((array) ($job['steps'] ?? []) as $step) {
        $run = is_array($step) && is_string($step['run'] ?? null) ? $step['run'] : '';
        if (str_contains($run, 'CIVIKITCHEN_DB_IMAGE=')) {
            // Comments are part of the run scalar; only commands count.
            $resolver = preg_replace('/^\s*#.*$/m', '', $run);
        }
        if (preg_match("/<<'YAML'\n(.*?)\n\s*YAML\n/s", $run, $heredoc) === 1) {
            $compose = Symfony\Component\Yaml\Yaml::parse($heredoc[1]);
            $smokeDatabase = is_array($compose) ? ($compose['services']['db']['image'] ?? null) : null;
        }
    }
}
$read = '/^\s*database=\$\(php \S+\/ckconform --policy database\)\s*$/m';
$export = '/^\s*echo "CIVIKITCHEN_DB_IMAGE=\$\{database:-([^}]*)\}" >> "\$GITHUB_ENV"\s*$/m';
if ($resolver === null || preg_match($read, $resolver) !== 1) {
    $problems[] = "$releaseFile: no step reads database from ckconform --policy database";
} elseif (preg_match($export, $resolver, $exported) !== 1) {
    $problems[] = "$releaseFile: CIVIKITCHEN_DB_IMAGE is not exported as \${database:-<default>}";
} elseif ($exported[1] !== Policy::DEFAULT_DATABASE) {
    $problems[] = "$releaseFile: the database fallback is {$exported[1]}, not " . Policy::DEFAULT_DATABASE;
}
if ($smokeDatabase !== '${CIVIKITCHEN_DB_IMAGE}') {
    $problems[] = "$releaseFile: the smoke stack's db image is " . var_export($smokeDatabase, true) . ', not ${CIVIKITCHEN_DB_IMAGE}';
}

$gitlabDefault = ck_workflow_parsed($gitlabFile)['variables']['CK_DB_IMAGE'] ?? null;
if ($gitlabDefault !== Policy::DEFAULT_DATABASE) {
    $problems[] = "$gitlabFile: CK_DB_IMAGE defaults to " . var_export($gitlabDefault, true) . ', not ' . Policy::DEFAULT_DATABASE;
}

foreach ($problems as $problem) {
    fwrite(STDERR, "$problem\n");
}
exit($problems === [] ? 0 : 1);
