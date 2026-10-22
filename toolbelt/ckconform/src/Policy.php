<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

use Composer\Semver\VersionParser;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The `policy:` section of civikitchen.yaml: its single parser and the
 * inventory of what may be in it. Shell tools read it only through
 * `ckconform --policy-env` / `--policy <key>`.
 *
 * The public YAML uses typed nested objects. This class normalizes them to the
 * stable scalar/list view consumed by existing PHP and shell checks.
 */
final class Policy
{
    public const CONFIG_FILE = 'civikitchen.yaml';

    public const LEGACY_FILE = '.ckconform';

    /** The stacks' database image when policy.database is absent. */
    public const DEFAULT_DATABASE = 'mariadb:11.4';

    /**
     * Every normalized key a consumer may read, and its owner. Public YAML
     * property names are enforced by the JSON Schema before normalization.
     *
     * @var array<string, string>
     */
    public const KEYS = [
        // read by ckconform's own checks
        'ignore_checks' => 'ckconform: skip whole checks, comma-separated',
        'min_coverage' => 'ckconform + ckcoverage: the coverage floor in percent',
        'tests' => "ckconform + ckcoverage: 'optional -- <reason>' for a repo with no PHP suite",
        'license' => 'ckconform: the licence this repo ships under',
        'copyright' => 'ckconform: the copyright holder in file headers',
        'vendor' => 'ckconform: the composer vendor name',
        'hook_style' => 'ckconform: which hook declaration style this repo uses',
        'known_hooks' => 'ckconform: hook names this repo defines itself',
        'known_api4_entities' => 'ckconform: APIv4 entities supplied by required third-party extensions, reason mandatory',
        'bundles' => 'ckconform: vendored front-end bundles that are not repo source',
        'deploy_hygiene' => 'ckconform: deploy-only files deliberately shipped, reason mandatory',
        'vendored_paths' => 'cklint + ckfmt + ckeslint: third-party source this repo carries verbatim, reason mandatory',
        'smarty_skip_templates' => 'cksmarty: managed MessageTemplates this repo renders without Smarty, reason mandatory',
        'npm_license' => 'ckconform: accepted npm licence identifiers',
        'release' => "ckconform: 'none -- <reason>' for a repo that deliberately cuts no releases",
        'untagged_versions' => 'ckconform: versions the repo moved past that deliberately stay untagged, reason mandatory',
        'max_unreleased_days' => 'ckconform: days of unreleased shipped changes before it is reported',
        // read by the ck* tools
        'mutation_min_msi' => 'ckmutate: mutation score floor (--min-msi)',
        'mutation_min_covered_msi' => 'ckmutate: covered-code mutation floor (--min-covered-msi)',
        'mutation_paths' => 'ckmutate: what to mutate, comma-separated',
        'dist_exclude' => 'ckrelease + ckconform: additionally kept out of the release zip',
        'dist_include' => 'ckrelease + ckconform: kept IN the zip despite the central exclude list',
        'dist_build_tool' => 'ckrelease + extension-release.yml: the toolchain that builds the release-only output',
        'dist_build_output' => 'ckrelease + ckconform: untracked build output staged into the release zip, one path per value',
        'lifecycle_log_ignore' => 'cklifecycle: log patterns to ignore, reason mandatory',
        'javascript_type_check' => 'ckeslint: TypeScript compiler diagnostics over the repo\'s JavaScript, true or false',
        // read by ckinit
        'template_custom' => 'ckinit: template-managed files this repo owns instead',
        'renovate_preset' => 'ckinit: the Renovate preset the managed renovate.json extends',
        'ci' => "ckinit: 'gitlab' for a repository whose CI runs on GitLab",
        // read by the reusable workflows
        'civicrm' => 'extension-ci.yml + extension-release.yml + ckup: the CiviCRM minor whose :standalone-<minor> image the stacks run',
        'database' => 'ckinit + extension-release.yml: the database image of the compose stacks and the release smoke test',
        // read by the image entrypoint (docker/runtime/provision.sh)
        'extension_source' => 'entrypoint: key@HTTPS-URL#sha256=digest for a dependency, one per source',
        'extension_release' => 'entrypoint + extension-release.yml: key repo tag asset sha256 for a dependency staged from a GitHub release',
        'extension_version' => 'entrypoint: key@Composer-version-constraint for a pinned dependency',
    ];

    /**
     * Keys where every occurrence counts, rather than the first one winning.
     *
     * `template_custom` is deliberately NOT one: its value is a comma-separated
     * list on one line and ckinit stops at the first occurrence, so a second
     * line does nothing. It stays scalar and PolicyKeyCheck reports the repeat.
     *
     * @var list<string>
     */
    public const REPEATABLE = ['dist_exclude', 'dist_include', 'dist_build_output', 'lifecycle_log_ignore', 'vendored_paths', 'smarty_skip_templates', 'untagged_versions', 'extension_source', 'extension_release', 'extension_version'];

    /** @var list<string> */
    public const PERCENT = ['min_coverage', 'mutation_min_msi', 'mutation_min_covered_msi'];

    /**
     * Environment variable naming an organisation-wide defaults file in the
     * same format. Its keys apply to every repo whose tools see the variable;
     * the repo's own `civikitchen.yaml` overrides per key.
     */
    public const DEFAULTS_ENV = 'CK_DEFAULT_CONFIG';

    /**
     * The keys a defaults file may set: those read by ckconform and ckinit
     * only. In CI the variable reaches the template check and the `ci` job's
     * gates, not the mutation, lifecycle or compat runs; a key another gate reads
     * (min_coverage, mutation_*, lifecycle_log_ignore, ...) would apply in one
     * job and silently not in another, so it stays per repo.
     *
     * @var list<string>
     */
    public const SHARED = ['license', 'npm_license', 'copyright', 'vendor', 'hook_style', 'max_unreleased_days', 'renovate_preset'];

    /**
     * The defaults file's contents, null when the variable is unset. A variable
     * that names a file which cannot be read is an error, not an absent layer:
     * a fleet whose licence policy silently stopped applying is the exact
     * failure the layer exists to prevent.
     */
    public static function defaultsRaw(): ?string
    {
        $file = getenv(self::DEFAULTS_ENV);
        if ($file === false || $file === '') {
            return null;
        }
        $raw = is_file($file) ? file_get_contents($file) : false;
        if ($raw === false) {
            throw new \RuntimeException(self::DEFAULTS_ENV . " names '{$file}', which is not a readable file");
        }

        return $raw;
    }

    /**
     * Defaults overlaid by the repo's own file, per key: a key the repo sets
     * REPLACES the default's values — for repeatable keys too, so a repo's
     * `vendored_paths` lines never silently inherit a fleet-wide one. Keys the
     * repo does not mention keep the default. The defaults' keys come first,
     * so "first occurrence wins" ordering is preserved within each source.
     * Only SHARED keys are taken from the defaults; PolicyKeyCheck reports the
     * others, and dropping them here keeps a gate honest even where it does
     * not run.
     *
     * @return array<string, list<string>>
     */
    public static function layered(?string $repoRaw, ?string $defaultsRaw): array
    {
        $defaults = array_intersect_key(self::parse($defaultsRaw), array_flip(self::SHARED));

        return array_merge($defaults, self::parse($repoRaw));
    }

    /**
     * The view every reader gets: the repo config over the CK_DEFAULT_CONFIG
     * layer. Context::policy() and the CLI read-outs go through here, so the
     * merge happens in exactly one place.
     *
     * @return array<string, list<string>>
     */
    public static function effective(?string $repoRaw): array
    {
        return self::layered($repoRaw, self::defaultsRaw());
    }

    /**
     * Parse the policy mapping from a civikitchen.yaml document. Unknown keys
     * are returned so PolicyKeyCheck can produce the focused diagnostic.
     *
     * @return array<string, list<string>> key => values, in file order
     */
    public static function parse(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        if (strlen($raw) > 1024 * 1024) {
            throw new \RuntimeException(self::CONFIG_FILE . ' exceeds the 1 MiB configuration limit');
        }
        self::loadYaml();
        $first = ltrim($raw)[0] ?? '';
        if ($first === '{' || $first === '[') {
            throw new \RuntimeException(self::CONFIG_FILE . ' must use YAML mapping syntax, not JSON syntax');
        }
        try {
            $document = Yaml::parse($raw, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            throw new \RuntimeException('invalid ' . self::CONFIG_FILE . ': ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($document) || array_is_list($document)) {
            throw new \RuntimeException(self::CONFIG_FILE . ' root must be a YAML mapping');
        }
        self::validateDocument($document);
        $policy = $document['policy'] ?? [];
        $out = [];
        foreach (['license', 'copyright', 'hook_style', 'npm_license', 'max_unreleased_days', 'renovate_preset', 'ci', 'civicrm', 'database'] as $key) {
            if (array_key_exists($key, $policy)) $out[$key] = [(string) $policy[$key]];
        }
        if (isset($policy['coverage']['minimum'])) $out['min_coverage'] = [(string) $policy['coverage']['minimum']];
        if (isset($policy['ignore_checks'])) $out['ignore_checks'] = [implode(',', $policy['ignore_checks']['checks']) . ' -- ' . $policy['ignore_checks']['reason']];
        if (isset($policy['tests'])) $out['tests'] = [$policy['tests']['mode'] . ' -- ' . $policy['tests']['reason']];
        if (isset($policy['vendor'])) $out['vendor'] = [is_array($policy['vendor']) ? $policy['vendor']['mode'] . ' -- ' . $policy['vendor']['reason'] : $policy['vendor']];
        if (isset($policy['known_hooks'])) $out['known_hooks'] = [implode(',', $policy['known_hooks'])];
        if (isset($policy['known_api4_entities'])) $out['known_api4_entities'] = [implode(',', $policy['known_api4_entities']['entities']) . ' -- ' . $policy['known_api4_entities']['reason']];
        if (isset($policy['bundles'])) $out['bundles'] = [$policy['bundles']['mode'] . ' -- ' . $policy['bundles']['reason']];
        if (isset($policy['deploy_hygiene'])) $out['deploy_hygiene'] = [implode(',', $policy['deploy_hygiene']['paths']) . ' -- ' . $policy['deploy_hygiene']['reason']];
        foreach ($policy['vendored_paths'] ?? [] as $item) $out['vendored_paths'][] = $item['path'] . ' -- ' . $item['reason'];
        foreach ($policy['smarty_skip_templates'] ?? [] as $item) $out['smarty_skip_templates'][] = $item['template'] . ' -- ' . $item['reason'];
        if (isset($policy['release'])) $out['release'] = [$policy['release']['mode'] . ' -- ' . $policy['release']['reason']];
        foreach ($policy['untagged_versions'] ?? [] as $item) $out['untagged_versions'][] = $item['version'] . ' -- ' . $item['reason'];
        if (isset($policy['javascript']['type_check'])) $out['javascript_type_check'] = [$policy['javascript']['type_check'] ? 'true' : 'false'];
        if (isset($policy['mutation']['minimum_msi'])) $out['mutation_min_msi'] = [(string) $policy['mutation']['minimum_msi']];
        if (isset($policy['mutation']['minimum_covered_msi'])) $out['mutation_min_covered_msi'] = [(string) $policy['mutation']['minimum_covered_msi']];
        if (isset($policy['mutation']['paths'])) $out['mutation_paths'] = [implode(',', $policy['mutation']['paths'])];
        foreach ($policy['dist']['exclude'] ?? [] as $path) $out['dist_exclude'][] = $path;
        if (isset($policy['dist']['include'])) {
            foreach ($policy['dist']['include'] as $item) $out['dist_include'][] = $item['path'] . ' -- ' . $item['reason'];
        }
        if (isset($policy['dist']['build'])) {
            $out['dist_build_tool'] = [$policy['dist']['build']['tool']];
            foreach ($policy['dist']['build']['outputs'] as $path) $out['dist_build_output'][] = $path;
        }
        foreach ($policy['lifecycle']['log_ignore'] ?? [] as $item) $out['lifecycle_log_ignore'][] = $item['pattern'] . ' -- ' . $item['reason'];
        if (isset($policy['template_custom'])) $out['template_custom'] = [implode(',', $policy['template_custom']['paths']) . ' -- ' . $policy['template_custom']['reason']];
        $sourceKeys = [];
        foreach ($policy['extension_sources'] ?? [] as $item) {
            if (isset($sourceKeys[$item['key']])) {
                throw new \RuntimeException(self::CONFIG_FILE . ': policy.extension_sources repeats key ' . $item['key']);
            }
            $sourceKeys[$item['key']] = true;
            try {
                (new VersionParser())->parseConstraints($item['version']);
            } catch (\UnexpectedValueException $e) {
                throw new \RuntimeException(self::CONFIG_FILE . ': policy.extension_sources has invalid Composer version constraint for ' . $item['key'] . ': ' . $item['version'], 0, $e);
            }
            if (isset($item['url'])) {
                $out['extension_source'][] = $item['key'] . '@' . $item['url'] . '#sha256=' . strtolower($item['sha256']) . ' -- ' . $item['reason'];
            } else {
                // Space-separated: the schema forbids a space in every field, and
                // both consumers (bash `read`, the release workflow) split on it.
                $out['extension_release'][] = implode(' ', [
                    $item['key'],
                    $item['release']['repository'],
                    $item['release']['tag'],
                    $item['release']['asset'],
                    strtolower($item['sha256']),
                ]) . ' -- ' . $item['reason'];
            }
            $out['extension_version'][] = $item['key'] . '@' . $item['version'];
        }
        return $out;
    }

    /** @param array<string, mixed> $document */
    private static function validateDocument(array $document): void
    {
        require_once dirname(__DIR__, 3) . '/packages/civicrm-profile-schema/validate.php';
        $schemaFile = dirname(__DIR__, 3) . '/packages/civikitchen-scenario-schema/scenario.schema.json';
        $schema = json_decode((string) file_get_contents($schemaFile), true, 512, JSON_THROW_ON_ERROR);
        $object = json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $errors = (new \CkProfileSchemaValidator($schema))->validate($object);
        if ($errors !== []) throw new \RuntimeException(implode("\n", $errors));
        // A patch, variant or digest pin of the same image is the same database.
        $database = $document['policy']['database'] ?? null;
        $scenarioDatabase = $document['scenario']['database']['image'] ?? null;
        if (is_string($database) && is_string($scenarioDatabase)
            && preg_match('/^' . preg_quote($database, '/') . '([.@-]|$)/', $scenarioDatabase) !== 1) {
            throw new \RuntimeException("policy.database is {$database}, but scenario.database.image is {$scenarioDatabase}: name the same image in both");
        }
    }

    /**
     * Any YAML document, through the one parser this tool carries — a workflow
     * or a compose file is structured and never read with a regex.
     *
     * Null on failure, with $error saying which: a document that does not parse,
     * or a parser that is not installed. Neither may abort the run or read as a
     * pass, so both come back as a message for the calling check to report.
     */
    public static function parseYaml(string $raw, ?string &$error = null, bool $customTags = false): mixed
    {
        $error = null;
        try {
            self::loadYaml();

            return Yaml::parse($raw, $customTags ? Yaml::PARSE_CUSTOM_TAGS : 0);
        } catch (ParseException $exception) {
            $error = 'does not parse as YAML: ' . rtrim($exception->getMessage());
        } catch (\RuntimeException $exception) {
            $error = 'could not be read: ' . rtrim($exception->getMessage());
        }

        return null;
    }

    private static function loadYaml(): void
    {
        if (class_exists(Yaml::class)) {
            return;
        }
        $package = dirname(__DIR__, 3) . '/packages/civikitchen-scenario-schema';
        if (!is_file($package . '/vendor/autoload.php')) {
            throw new \RuntimeException("CiviKitchen YAML parser dependency is missing - run composer install in {$package}");
        }
        require_once $package . '/vendor/autoload.php';
    }

    /**
     * The value without its ` -- <reason>` suffix. For the shell view only:
     * inside ckconform the reason stays part of the value, because checks match
     * on it (TestSuiteRequiredCheck accepts `optional -- <reason>` and nothing
     * else) and stripping it would make a mandatory reason optional.
     */
    public static function stripReason(string $value): string
    {
        $cut = strpos($value, ' -- ');

        return $cut === false ? $value : rtrim(substr($value, 0, $cut));
    }

    /**
     * `CK_POLICY_<KEY>='<value>'` lines for `eval` in a shell tool. Scalar keys
     * only, reason stripped; repeatable keys go through `--policy <key>`.
     * escapeshellarg, not quotes by hand: values are free text from a repo.
     * Takes the repo file's contents; the defaults layer is applied here.
     */
    public static function toShell(string $raw): string
    {
        $out = '';
        foreach (self::effective($raw) as $key => $values) {
            if (in_array($key, self::REPEATABLE, true) || !isset(self::KEYS[$key])) {
                continue;
            }
            $name = 'CK_POLICY_' . strtoupper($key);
            $out .= $name . '=' . escapeshellarg(self::stripReason($values[0])) . "\n";
        }

        return $out;
    }
}
