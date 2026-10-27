<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/**
 * Everything a check may want to know about the extension under inspection,
 * parsed once and parsed properly: structured formats through structured
 * parsers, files found recursively — a sed or a fixed-depth glob makes a
 * check pass silently.
 */
final class Context
{
    private ?\SimpleXMLElement $infoXml = null;

    /** @var array<string, array<mixed>|null> */
    private array $json = [];

    /** @var list<string>|null */
    private ?array $trackedFiles = null;

    /** @var array<string, string>|null */
    private ?array $policy = null;

    /** @var list<string>|null */
    private ?array $versionHistory = null;

    /** @var array<string, \SimpleXMLElement>|null */
    private ?array $repositoryExtensions = null;

    /** @var array<string, array<mixed>|null> */
    private array $workflowData = [];

    /** @var array<string, string|null> */
    private array $workflowDataError = [];

    /** @var array{scoped: array<string, string>, jobs: array<string, array<mixed>>, unparsed: list<string>, unreadable: list<string>}|null */
    private ?array $workflowScope = null;

    public function __construct(
        public readonly string $root,
        public readonly ?string $coreDir = null,
    ) {
    }

    public function path(string $relative): string
    {
        return rtrim($this->root, '/') . '/' . ltrim($relative, '/');
    }

    public function exists(string $relative): bool
    {
        return file_exists($this->path($relative));
    }

    public function read(string $relative): ?string
    {
        $file = $this->path($relative);
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $contents = file_get_contents($file);

        return $contents === false ? null : $contents;
    }

    /**
     * Concatenation of whichever of these files exist, for "config may live in
     * either name" cases (phpstan.neon.dist / phpstan.neon).
     */
    public function readAny(string ...$relatives): ?string
    {
        $parts = [];
        foreach ($relatives as $relative) {
            $contents = $this->read($relative);
            if ($contents !== null) {
                $parts[] = $contents;
            }
        }

        return $parts === [] ? null : implode("\n", $parts);
    }

    public function infoXml(): ?\SimpleXMLElement
    {
        if ($this->infoXml === null) {
            $raw = $this->read('info.xml');
            if ($raw === null) {
                return null;
            }
            $previous = libxml_use_internal_errors(true);
            $parsed = simplexml_load_string($raw);
            libxml_use_internal_errors($previous);
            if ($parsed === false) {
                return null;
            }
            $this->infoXml = $parsed;
        }

        return $this->infoXml;
    }

    /**
     * The extension key from info.xml's key attribute (`org.example.myext`),
     * or null when there is no info.xml or no key.
     */
    public function extensionKey(): ?string
    {
        $info = $this->infoXml();
        if ($info === null) {
            return null;
        }
        $key = trim((string) ($info['key'] ?? ''));

        return $key === '' ? null : $key;
    }

    /**
     * The extension's own CamelCase-ish name: the last dot-segment of the key
     * (`org.example.myext` -> `myext`), used only as an "is this ours" hint.
     */
    public function shortName(): ?string
    {
        $key = $this->extensionKey();
        if ($key === null) {
            return null;
        }
        $parts = explode('.', $key);
        $last = (string) end($parts);

        return $last === '' ? null : $last;
    }

    /** info.xml's `<file>`, which civix writes into E::SHORT_NAME; null when absent. */
    public function fileName(): ?string
    {
        $file = trim((string) ($this->infoXml()?->file ?? ''));

        return $file === '' ? null : $file;
    }

    /** The `<version>` declared in info.xml, '' when absent. */
    public function infoVersion(): string
    {
        $info = $this->infoXml();
        if ($info === null || !isset($info->version)) {
            return '';
        }

        return trim((string) $info->version);
    }

    /** The lowest `<compatibility><ver>` from info.xml, or null when absent. */
    public function compatibilityFloor(): ?string
    {
        $info = $this->infoXml();
        if ($info === null) {
            return null;
        }
        $floor = null;
        foreach ($info->xpath('//compatibility/ver') ?: [] as $ver) {
            $value = trim((string) $ver);
            if ($value === '' || preg_match('/^\d+(\.\d+)*$/', $value) !== 1) {
                continue;
            }
            if ($floor === null || version_compare($value, $floor, '<')) {
                $floor = $value;
            }
        }

        return $floor;
    }

    /** The `<license>` declared in info.xml, '' when absent. */
    public function infoLicense(): string
    {
        $info = $this->infoXml();
        if ($info === null || !isset($info->license)) {
            return '';
        }

        return (string) $info->license;
    }

    /**
     * Raw mixin declarations from info.xml, e.g. 'mgd-php@1.0.0'. Callers that
     * want bare names strip the '@' suffix themselves.
     *
     * @return list<string>
     */
    public function declaredMixins(): array
    {
        $info = $this->infoXml();
        if ($info === null) {
            return [];
        }
        $mixins = [];
        foreach ($info->xpath('//mixins/mixin') ?: [] as $mixin) {
            $value = trim((string) $mixin);
            if ($value !== '') {
                $mixins[] = $value;
            }
        }

        return $mixins;
    }

    /**
     * The <ext> children of info.xml's <requires>, as trimmed extension keys.
     * Read via SimpleXML because attributes are legal on the element
     * (`<ext version="3.32">org.civicoop.civirules</ext>`), and
     * empty/whitespace-only elements are dropped: '' is not a key, and letting
     * it through makes an in_array() dependency test silently unmatchable.
     *
     * @return list<string>
     */
    public function requiredExtensions(): array
    {
        $info = $this->infoXml();
        if ($info === null) {
            return [];
        }
        $keys = [];
        foreach ($info->xpath('//requires/ext') ?: [] as $ext) {
            $key = trim((string) $ext);
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /** The image's extension directory, where every installed extension lives. */
    public const EXT_DIR = '/var/www/html/ext';

    /**
     * Where a dependency sits in the image: the extension directory — CK_EXT_DIR
     * when the environment names another one — over the extension KEY. That is
     * where the template's phpstan bootstrap resolves a `<requires>` and where
     * the shared CI mounts a sibling; an extension's own `<file>` names only its
     * own mount.
     */
    public function installPath(string $key): string
    {
        return (getenv('CK_EXT_DIR') ?: self::EXT_DIR) . '/' . $key;
    }

    /**
     * Where a required extension's code is, for the checks that read a
     * dependency rather than this repo: under the image's extension directory
     * (CK_EXT_DIR, the entrypoint's convention — every <requires> is there once
     * the site booted), else another extension of this repository, else as a
     * sibling checkout next to this repo. Only dependencies actually present
     * are returned; a missing one is not an error here, the check that needs it
     * says what it could not judge.
     *
     * @return array<string, string> extension key => directory
     */
    public function requiredExtensionDirs(): array
    {
        // A neighbour's directory name is free, so the key it declares is what
        // identifies it, not the path.
        $neighbours = [];
        foreach ($this->isMonorepo() ? $this->repositoryExtensions() : [] as $directory => $info) {
            $neighbourKey = trim((string) ($info['key'] ?? ''));
            if ($neighbourKey !== '') {
                $neighbours[$neighbourKey] = $this->repositoryRoot() . '/' . $directory;
            }
        }

        $dirs = [];
        foreach ($this->requiredExtensions() as $key) {
            $candidates = [
                $this->installPath($key),
                $neighbours[$key] ?? null,
                dirname(rtrim($this->root, '/')) . '/' . $key,
            ];
            foreach (array_filter($candidates) as $candidate) {
                if (is_file($candidate . '/info.xml')) {
                    $dirs[$key] = $candidate;
                    break;
                }
            }
        }

        return $dirs;
    }

    /**
     * @return array<mixed>|null
     */
    public function json(string $relative): ?array
    {
        if (!array_key_exists($relative, $this->json)) {
            $raw = $this->read($relative);
            $decoded = $raw === null ? null : json_decode($raw, true);
            $this->json[$relative] = is_array($decoded) ? $decoded : null;
        }

        return $this->json[$relative];
    }

    /**
     * Repo policy from civikitchen.yaml over the organisation-wide defaults
     * file CK_DEFAULT_CONFIG names.
     * The mechanism is public (it ships in this image), the values are not —
     * they live in the consuming repo, so a private licence policy stays private.
     *
     * @return array<string, string>
     */
    public function policy(): array
    {
        if ($this->policy === null) {
            $this->policy = array_map(
                static fn (array $values): string => $values[0],
                Policy::effective($this->read(Policy::CONFIG_FILE)),
            );
        }

        return $this->policy;
    }

    /** @var list<string> */
    private array $skippedChecks = [];

    /**
     * The checks this run never executed, because `ignore_checks=` in the
     * policy skipped them. Set by the runner, since only it knows the outcome
     * of that parse.
     *
     * SuppressionHygieneCheck needs it: an inline ignore for a skipped check
     * cannot be called unused, because nothing ever looked for the finding it
     * would have silenced.
     *
     * @param list<string> $names
     */
    public function skipChecks(array $names): void
    {
        $this->skippedChecks = array_values($names);
    }

    /** @return list<string> */
    public function skippedChecks(): array
    {
        return $this->skippedChecks;
    }

    public function policyValue(string $key): ?string
    {
        $value = $this->policy()[$key] ?? null;

        return ($value === null || $value === '') ? null : $value;
    }

    /** @return list<string> */
    public function policyValues(string $key): array
    {
        return Policy::effective($this->read(Policy::CONFIG_FILE))[$key] ?? [];
    }

    public function isGitRepo(): bool
    {
        return $this->trackedFiles() !== [];
    }

    /**
     * Git-tracked files under the extension directory, relative to it. Tracked
     * rather than on-disk on purpose:
     * an untracked file cannot break anyone else's build.
     *
     * @return list<string>
     */
    public function trackedFiles(): array
    {
        if ($this->trackedFiles === null) {
            $this->trackedFiles = [];
            $output = $this->git(['ls-files', '-z']);
            if ($output !== null) {
                foreach (explode("\0", $output) as $file) {
                    if ($file !== '') {
                        $this->trackedFiles[] = $file;
                    }
                }
            }
        }

        return $this->trackedFiles;
    }

    /**
     * @param  callable(string): bool|null $filter
     * @return list<string>
     */
    public function tracked(string $glob, ?callable $filter = null): array
    {
        $matches = [];
        foreach ($this->trackedFiles() as $file) {
            if (!fnmatch($glob, $file) && !fnmatch($glob, basename($file))) {
                continue;
            }
            if ($filter !== null && !$filter($file)) {
                continue;
            }
            $matches[] = $file;
        }

        return $matches;
    }

    /**
     * Git-tracked files under a directory, by extension — the tracked-only twin
     * of findFiles().
     *
     * findFiles() walks the disk, which contradicts the rule the rest of this
     * class follows: an untracked local file cannot break anyone else's build,
     * so it must not decide a check. A repo check that asks "does this repo ship
     * X" has to read what the repo ships, i.e. what is committed. An empty
     * directory string means the whole tree.
     *
     * @param  list<string> $extensions
     * @return list<string>
     */
    public function trackedUnder(string $directory, array $extensions = []): array
    {
        $prefix = $directory === '' ? '' : rtrim($directory, '/') . '/';
        $found = [];
        foreach ($this->trackedFiles() as $file) {
            if ($prefix !== '' && !str_starts_with($file, $prefix)) {
                continue;
            }
            if ($extensions !== []) {
                $matched = false;
                foreach ($extensions as $extension) {
                    if (str_ends_with($file, $extension)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue;
                }
            }
            $found[] = $file;
        }
        sort($found);

        return $found;
    }

    /**
     * The shared reusable CI workflow, by the filename repos name in `uses:`.
     * A repo whose workflow delegates to it is running everything that workflow
     * runs — cklint, ckconform, phpstan, phpunit under ckcoverage — even though
     * none of those tokens appears in the repo's own thin caller.
     */
    public const SHARED_CI = 'extension-ci.yml';

    /**
     * The shared GitLab pipeline a `.gitlab-ci.yml` includes — the GitLab
     * counterpart to SHARED_CI, run inside the image instead of a compose stack.
     */
    public const SHARED_GITLAB_CI = 'ci/gitlab/extension-ci.yml';

    /** The GitLab pipeline file; GitLab reads it from the repository root only. */
    public const GITLAB_PIPELINE = '.gitlab-ci.yml';

    /**
     * The shared reusable release workflow, by the filename repos name in
     * `uses:` — the release-side counterpart to SHARED_CI.
     */
    public const SHARED_RELEASE = 'extension-release.yml';

    /**
     * The jobs that publish through the shared release workflow, workflow =>
     * job name => job. A `dry_run: true` job publishes nothing.
     *
     * @return array<string, array<string, array<mixed>>>
     */
    public function releaseCallers(): array
    {
        return array_filter(array_map(
            static fn (array $jobs): array => array_filter($jobs, [self::class, 'publishesRelease']),
            $this->jobsCalling(self::SHARED_RELEASE),
        ));
    }

    /** @param array<mixed> $job */
    public static function publishesRelease(array $job): bool
    {
        $uses = $job['uses'] ?? null;
        $dryRun = is_array($job['with'] ?? null) ? ($job['with']['dry_run'] ?? false) : false;

        return is_string($uses) && basename(explode('@', $uses, 2)[0]) === self::SHARED_RELEASE
            && $dryRun !== true && $dryRun !== 'true';
    }

    /**
     * A workflow's parsed jobs, name => job; empty when it does not parse.
     *
     * @return array<array-key, array<mixed>>
     */
    public function jobsOf(string $workflow): array
    {
        $jobs = $this->workflowData($workflow)['jobs'] ?? null;

        return is_array($jobs) ? array_filter($jobs, 'is_array') : [];
    }

    /**
     * Every job of every workflow whose parsed `uses:` calls the reusable
     * workflow $workflowFile, whatever extension it runs: workflow => job name => job.
     *
     * @return array<string, array<string, array<mixed>>>
     */
    public function jobsCalling(string $workflowFile): array
    {
        $callers = [];
        foreach ($this->workflows() as $workflow) {
            $jobs = $this->workflowData($workflow)['jobs'] ?? null;
            foreach (is_array($jobs) ? $jobs : [] as $name => $job) {
                $uses = is_array($job) ? ($job['uses'] ?? null) : null;
                if (is_string($uses) && basename(explode('@', $uses, 2)[0]) === $workflowFile) {
                    $callers[$workflow][(string) $name] = $job;
                }
            }
        }

        return $callers;
    }

    /**
     * The newest `v*` tag reachable from HEAD, null when there is none.
     *
     * Reachable, not "newest by version": what the release checks compare
     * against is the last release cut on this line of history. A checkout made
     * without tags answers null too, so callers ask isShallowClone() and treat
     * a bare null as "cannot tell" rather than "never released".
     */
    public function newestTag(): ?string
    {
        $tag = trim((string) $this->git(['describe', '--tags', '--abbrev=0', '--match', 'v[0-9]*']));

        return $tag === '' ? null : $tag;
    }

    /**
     * Every `v*` tag in the checkout, unordered. The release-tags rule asks
     * whether a given version was ever tagged, which is a set question, not
     * the "last release on this line of history" newestTag() answers.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        $output = $this->git(['tag', '--list', 'v[0-9]*']);
        if ($output === null) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode("\n", $output)),
            static fn (string $tag): bool => $tag !== '',
        ));
    }

    /**
     * The `v*` tags whose commit is an ancestor of HEAD, unordered — the
     * releases this line of history has already published.
     *
     * @return list<string>
     */
    public function reachableTags(): array
    {
        $output = $this->git(['tag', '--merged', 'HEAD', '--list', 'v[0-9]*']);
        if ($output === null) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode("\n", $output)),
            static fn (string $tag): bool => $tag !== '',
        ));
    }

    /**
     * The distinct `<version>` values info.xml has carried, oldest first.
     *
     * Ordered by history, not by version_compare: what a release rule needs to
     * know is which numbers this repo actually published its way through. Each
     * historical blob is parsed as XML, because a diff-scraping variant reads a
     * `<version>` out of a comment or a neighbouring element.
     *
     * @return list<string>
     */
    public function infoVersionHistory(): array
    {
        if ($this->versionHistory === null) {
            $this->versionHistory = [];
            $log = $this->git(['log', '--format=%H', '--', 'info.xml']);
            $hashes = $log === null ? [] : array_filter(array_map('trim', explode("\n", $log)));
            // git log is newest first and this list is oldest first.
            foreach (array_reverse($hashes) as $hash) {
                $version = $this->versionAt($hash);
                if ($version === null || $version === end($this->versionHistory)) {
                    continue;
                }
                $this->versionHistory[] = $version;
            }
            $this->versionHistory = array_values(array_unique($this->versionHistory));
        }

        return $this->versionHistory;
    }

    /** info.xml's `<version>` at one commit, null when it is absent or unparsable. */
    private function versionAt(string $hash): ?string
    {
        // ./info.xml, not info.xml: a blob path is repo-root-relative unless it
        // is explicitly relative to the working directory, which a monorepo
        // extension root is not.
        $raw = $this->git(['show', $hash . ':./info.xml']);
        if ($raw === null) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($raw);
        libxml_use_internal_errors($previous);
        if ($parsed === false || !isset($parsed->version)) {
            return null;
        }
        $version = trim((string) $parsed->version);

        return $version === '' ? null : $version;
    }

    /**
     * A clone whose history is truncated — `actions/checkout` makes one by
     * default (depth 1). Anything reasoning about commits since a tag reports
     * itself unevaluated here instead of finding nothing.
     */
    public function isShallowClone(): bool
    {
        return trim((string) $this->git(['rev-parse', '--is-shallow-repository'])) === 'true';
    }

    /**
     * Commits in `<ref>..HEAD`, newest first, with committer date and the files
     * each touched. Null when git could not answer at all — an unreadable
     * history is not an empty one.
     *
     * Merge commits carry no file list (`--name-only` shows no diff for them),
     * which is right: the commits they merge are listed in their own right.
     *
     * Scoped to the extension directory: where a repository holds several
     * extensions, a commit to a neighbour changes nothing about this one.
     * `--relative` also reports the paths relative to that directory, which is
     * what every caller compares against.
     *
     * @return list<array{hash: string, date: string, files: list<string>}>|null
     */
    public function commitsSince(string $ref): ?array
    {
        // Record-separated rather than one commit per line: a commit's file
        // list is lines too, and \x1e occurs in neither.
        $output = $this->git([
            '-c', 'core.quotePath=false',
            'log', '--format=%x1e%H %cI', '--name-only', '--relative', $ref . '..HEAD', '--', '.',
        ]);
        if ($output === null) {
            return null;
        }

        $commits = [];
        foreach (explode("\x1e", $output) as $record) {
            $lines = explode("\n", trim($record, "\n"));
            $header = trim((string) array_shift($lines));
            if ($header === '') {
                continue;
            }
            [$hash, $date] = array_pad(explode(' ', $header, 2), 2, '');
            $commits[] = [
                'hash' => $hash,
                'date' => trim($date),
                'files' => array_values(array_filter(
                    array_map('trim', $lines),
                    static fn (string $file): bool => $file !== '',
                )),
            ];
        }

        return $commits;
    }

    public function isTracked(string $relative): bool
    {
        return in_array(ltrim($relative, '/'), $this->trackedFiles(), true);
    }

    /**
     * Does the repo ship this file? Tracked when in git (repo principle: an
     * uncommitted local file must not sway a verdict), on disk otherwise.
     */
    public function ships(string $relative): bool
    {
        return $this->isGitRepo() ? $this->isTracked($relative) : $this->exists($relative);
    }

    /**
     * Does the repo ship an own APIv4 entity class Civi/Api4/<Entity>.php?
     * Source files only: a fixture under tests/fixtures/Civi/Api4 is not shipped.
     */
    public function shipsApi4Entity(string $entity): bool
    {
        foreach ($this->sourceFiles('', ['.php']) as $file) {
            if (str_ends_with($file, 'Civi/Api4/' . $entity . '.php')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the repo ship anything under this directory? Tracked files when in
     * git; outside git (tarball, exported build) whatever is on disk.
     */
    public function hasShippedUnder(string $directory): bool
    {
        return $this->isGitRepo()
            ? $this->trackedUnder($directory) !== []
            : $this->findFiles($directory) !== [];
    }

    /** read(), but only for files the repo ships — see ships(). */
    public function readShipped(string $relative): ?string
    {
        return $this->ships($relative) ? $this->read($relative) : null;
    }

    /**
     * Whether git would ignore this path — asked of git, not guessed from
     * .gitignore. git resolves patterns, precedence, negation and nested
     * .gitignore files; nothing else does. Outside a git repo (exit 128) the
     * answer is "not ignored".
     */
    public function isIgnored(string $relative): bool
    {
        return $this->git(['check-ignore', '-q', '--', $relative]) !== null;
    }

    /**
     * Shipped source files: tracked (or, outside git, on-disk) files under a
     * directory, by suffix, minus tests/, vendor/ and node_modules/ at any
     * depth — code that never runs on an install must not decide a check.
     * A subdirectory with its own info.xml is another extension and is left out.
     *
     * @param  list<string>                $extensions
     * @param  callable(string): bool|null $filter
     * @return list<string>
     */
    public function sourceFiles(string $directory = '', array $extensions = [], ?callable $filter = null): array
    {
        $files = $this->isGitRepo()
            ? $this->trackedUnder($directory, $extensions)
            : $this->findFiles($directory, $extensions);
        $nested = [];
        foreach ($this->isGitRepo() ? $this->trackedFiles() : $this->findFiles('', ['info.xml']) as $file) {
            if (basename($file) === 'info.xml' && $file !== 'info.xml') {
                $nested[] = dirname($file) . '/';
            }
        }

        return array_values(array_filter($files, static function (string $file) use ($filter, $nested): bool {
            foreach (['tests/', 'vendor/', 'node_modules/'] as $skip) {
                if (str_starts_with($file, $skip) || str_contains($file, '/' . $skip)) {
                    return false;
                }
            }
            foreach ($nested as $extension) {
                if (str_starts_with($file, $extension)) {
                    return false;
                }
            }

            return $filter === null || $filter($file);
        }));
    }

    /** tracked() filter for manifests outside any node_modules install. */
    public static function outsideNodeModules(string $file): bool
    {
        return !str_contains($file, 'node_modules');
    }

    /**
     * The compose stacks the repo ships, tracked only: an untracked local
     * override is nobody else's problem.
     *
     * @return list<string>
     */
    public function composeFiles(): array
    {
        $files = [];
        foreach ($this->trackedFiles() as $file) {
            if (preg_match('/^(docker-)?compose.*\.ya?ml$/', basename($file)) === 1) {
                $files[] = $file;
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Files under a directory, recursively — no fixed-depth globs.
     *
     * @param  list<string> $extensions
     * @return list<string>
     */
    public function findFiles(string $directory, array $extensions = []): array
    {
        $base = $this->path($directory);
        if (!is_dir($base)) {
            return [];
        }
        $found = [];
        // Pruned even in fallback mode: .git is never repo content, and
        // .civikitchen-siblings/ is where the shared CI checks out a sibling
        // extension — foreign code with its own CI, whose hooks and files must
        // not be judged as this repo's (CiviCRM's own scanner skips
        // dot-directories for the same reason).
        $directoryIterator = new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                $directoryIterator,
                static fn (\SplFileInfo $file): bool => !($file->isDir()
                    && in_array($file->getFilename(), ['.git', '.civikitchen-siblings'], true)),
            )
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $name = $file->getFilename();
            if ($extensions !== []) {
                $matched = false;
                foreach ($extensions as $extension) {
                    if (str_ends_with($name, $extension)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue;
                }
            }
            $found[] = substr($file->getPathname(), strlen(rtrim($this->root, '/')) + 1);
        }
        sort($found);

        return $found;
    }

    /**
     * Workflow files, sorted, relative to the extension directory (`../`-prefixed
     * in a monorepo, where they live at the repository root).
     *
     * @return list<string>
     */
    public function workflows(): array
    {
        $repositoryRoot = $this->repositoryRoot();
        if ($this->extensionDirectory() !== '.') {
            $directory = $repositoryRoot . '/.github/workflows';
            if (!is_dir($directory)) {
                return [];
            }
            $prefix = str_repeat('../', count(explode('/', $this->extensionDirectory())));
            $workflows = [];
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile() || !in_array($file->getExtension(), ['yml', 'yaml'], true)) {
                    continue;
                }
                $workflows[] = $prefix . substr($file->getPathname(), strlen($repositoryRoot) + 1);
            }
            sort($workflows);

            return $workflows;
        }

        return $this->findFiles('.github/workflows', ['.yml', '.yaml']);
    }

    /**
     * Whether a directory of the relative path $file is named one of $names.
     *
     * @param list<string> $names
     */
    public static function isUnder(string $file, array $names): bool
    {
        return array_intersect(array_slice(explode('/', $file), 0, -1), $names) !== [];
    }

    /**
     * The repo's files (tracked, or all outside git) and its workflows, minus
     * vendor/, node_modules/ and prose: a changelog naming an old value is history.
     *
     * @return list<string>
     */
    public function nonProseFiles(): array
    {
        $files = $this->isGitRepo() ? $this->trackedFiles() : $this->findFiles('');

        return array_values(array_filter(
            array_unique([...$files, ...$this->workflows()]),
            static fn (string $file): bool => !self::isUnder($file, ['vendor', 'node_modules'])
                && preg_match('/\.(md|markdown|rst|txt)$/i', $file) !== 1,
        ));
    }

    /**
     * The extension's directory relative to the repository root, '.' when the
     * extension is the repository root itself. Both sides are resolved through
     * realpath: a root reached through a symlink would otherwise measure
     * against a differently spelled repository root.
     */
    public function extensionDirectory(): string
    {
        $root = rtrim((string) realpath($this->root), '/');
        $repositoryRoot = $this->repositoryRoot();
        if ($root === '' || !str_starts_with($root, $repositoryRoot . '/')) {
            return '.';
        }

        return substr($root, strlen($repositoryRoot) + 1);
    }

    /**
     * The part of each workflow that judges THIS extension: the whole file
     * where the extension is the repository root, and in a monorepo the caller
     * job whose `with.working_directory` names the extension's directory — a
     * neighbour's job says nothing about this extension. Keyed by a label for
     * messages: the workflow path, plus the job name where one was selected.
     *
     * @return array<string, string>
     */
    public function scopedWorkflows(): array
    {
        return $this->workflowScope()['scoped'];
    }

    /**
     * The parsed twin of scopedWorkflows(): every job that judges this
     * extension, `<workflow>:<job>` => the job's mapping.
     *
     * @return array<string, array<mixed>>
     */
    public function scopedJobs(): array
    {
        return $this->workflowScope()['jobs'];
    }

    /**
     * Why no CI job judges this extension, null when one does. Three causes,
     * each named: a workflow the parser could not read at all, a job that names
     * this directory but is written in a form the text-reading checks cannot
     * split, and the plain case of no job naming the directory. A single
     * catch-all message would send the operator after the wrong thing.
     */
    public function workflowScopeFailure(): ?string
    {
        $scope = $this->workflowScope();
        if (!$this->isMonorepo() || $this->workflows() === [] || $scope['scoped'] !== []) {
            return null;
        }
        if ($scope['unreadable'] !== []) {
            return 'workflow job ' . implode(', ', $scope['unreadable']) . ' runs working_directory: '
                . $this->extensionDirectory() . ' but is not written as a block mapping — '
                . 'the checks read a job as it is written, so write its keys on their own lines';
        }
        if ($scope['unparsed'] !== []) {
            return 'workflow ' . implode(', ', $scope['unparsed'])
                . ' — so nothing can tell which of this repository\'s extensions its jobs run';
        }

        return 'no workflow job sets working_directory: ' . $this->extensionDirectory()
            . ' — this repository holds several extensions and none of its CI jobs runs this one';
    }

    /**
     * The scoped workflow texts and, where a workflow yielded none, why.
     * `unparsed` names files the parser could not read, `unreadable` the jobs
     * that do name this extension but whose text could not be split out.
     *
     * @return array{scoped: array<string, string>, jobs: array<string, array<mixed>>, unparsed: list<string>, unreadable: list<string>}
     */
    private function workflowScope(): array
    {
        if ($this->workflowScope !== null) {
            return $this->workflowScope;
        }
        $scope = ['scoped' => [], 'jobs' => [], 'unparsed' => [], 'unreadable' => []];
        foreach ($this->workflows() as $workflow) {
            $body = $this->read($workflow) ?? '';
            $error = null;
            $jobs = $this->workflowData($workflow, $error)['jobs'] ?? null;
            if (!$this->isMonorepo()) {
                $scope['scoped'][$workflow] = $body;
                foreach (is_array($jobs) ? $jobs : [] as $name => $job) {
                    if (is_array($job)) {
                        $scope['jobs'][$workflow . ':' . $name] = $job;
                    }
                }
                continue;
            }
            if (!is_array($jobs)) {
                $scope['unparsed'][] = $workflow . ': ' . ($error ?? 'declares no jobs');
                continue;
            }
            $texts = $this->workflowJobs($body);
            foreach ($jobs as $name => $job) {
                if ($this->jobDirectory(is_array($job) ? $job : []) !== $this->extensionDirectory()) {
                    continue;
                }
                if (!isset($texts[(string) $name])) {
                    $scope['unreadable'][] = $workflow . ':' . $name;
                    continue;
                }
                $scope['scoped'][$workflow . ':' . $name] = $texts[(string) $name];
                $scope['jobs'][$workflow . ':' . $name] = is_array($job) ? $job : [];
            }
        }

        return $this->workflowScope = $scope;
    }

    /**
     * The repository's GitLab pipeline, when it ships one and the extension is
     * the repository root — a monorepo's GitLab pipeline is not supported.
     */
    public function gitlabPipeline(): ?string
    {
        if ($this->isMonorepo() || !$this->ships(self::GITLAB_PIPELINE)) {
            return null;
        }

        return $this->read(self::GITLAB_PIPELINE);
    }

    /**
     * Whether the GitLab pipeline includes the shared civikitchen pipeline, in
     * any of GitLab's include forms (string, `remote:`, `project:` + `file:`).
     */
    public function includesSharedGitlabCi(): bool
    {
        $body = $this->gitlabPipeline();
        if ($body === null) {
            return false;
        }
        // GitLab's own tags (!reference) parse as tagged values, not as errors.
        $parsed = Policy::parseYaml($body, $error, customTags: true);
        $includes = is_array($parsed) ? ($parsed['include'] ?? []) : [];
        if (!is_array($includes) || !array_is_list($includes)) {
            $includes = [$includes];
        }
        foreach ($includes as $include) {
            $targets = is_array($include)
                ? [$include['remote'] ?? null, $include['local'] ?? null, ...(array) ($include['file'] ?? [])]
                : [$include];
            foreach ($targets as $target) {
                if (is_string($target) && str_ends_with('/' . ltrim($target, '/'), '/' . self::SHARED_GITLAB_CI)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether this extension's CI delegates to a shared civikitchen pipeline,
     * GitHub's reusable workflow or the GitLab include — and so runs cklint,
     * ckconform, phpstan and phpunit under ckcoverage.
     */
    public function runsSharedCi(): bool
    {
        return $this->scopedJobsCalling(self::SHARED_CI) !== [] || $this->includesSharedGitlabCi();
    }

    /**
     * Every CI definition text that judges this extension: the scoped GitHub
     * workflows plus the GitLab pipeline, label => text.
     *
     * @return array<string, string>
     */
    public function ciTexts(): array
    {
        $texts = $this->scopedWorkflows();
        $gitlab = $this->gitlabPipeline();
        if ($gitlab !== null) {
            $texts[self::GITLAB_PIPELINE] = $gitlab;
        }

        return $texts;
    }

    /**
     * The scoped jobs whose parsed `uses:` calls the reusable workflow
     * $workflowFile, as `<workflow>:<job>` labels.
     *
     * @return list<string>
     */
    public function scopedJobsCalling(string $workflowFile): array
    {
        $callers = [];
        foreach ($this->scopedJobs() as $label => $job) {
            $uses = $job['uses'] ?? null;
            if (is_string($uses) && basename(explode('@', $uses, 2)[0]) === $workflowFile) {
                $callers[] = $label;
            }
        }

        return $callers;
    }

    /**
     * A workflow body split into its jobs, name => raw text.
     *
     * Indentation-based, and text rather than the parsed document on purpose:
     * the checks that judge a job read its steps, their `if:` and their `run:`
     * as written. GitHub Actions fixes this layout, so a plain reader is enough;
     * which job to judge is decided on the parsed YAML instead.
     *
     * @return array<string, string>
     */
    public function workflowJobs(string $body): array
    {
        $jobs = [];
        $inJobs = false;
        $jobsIndent = null;
        $jobIndent = null;
        $current = null;
        $buffer = [];
        foreach (explode("\n", $body) as $line) {
            if (preg_match('/^(\s*)jobs:\s*$/', $line, $match) === 1) {
                $inJobs = true;
                $jobsIndent = strlen($match[1]);
                continue;
            }
            if (!$inJobs) {
                continue;
            }
            // A comment-only line carries no structure: ckinit's managed markers
            // sit at column 0 and between a job's lines, so ending the section or
            // starting a job on one loses every job of a generated root workflow.
            if (str_starts_with(ltrim($line), '#')) {
                if ($current !== null) {
                    $buffer[] = $line;
                }
                continue;
            }
            if (preg_match('/^(\s+)(?:([A-Za-z0-9_-]+)|[\'\"]([^\'\"]+)[\'\"]):\s*$/', $line, $match) === 1
                && strlen($match[1]) > (int) $jobsIndent
                && ($jobIndent === null || strlen($match[1]) === $jobIndent)
            ) {
                if ($current !== null) {
                    $jobs[$current] = implode("\n", $buffer);
                }
                $jobIndent = strlen($match[1]);
                $current = $match[2] !== '' ? $match[2] : $match[3];
                $buffer = [];
                continue;
            }
            $lineIndent = strlen($line) - strlen(ltrim($line));
            if (trim($line) !== '' && $lineIndent <= (int) $jobsIndent) {
                break;
            }
            if ($current !== null) {
                $buffer[] = $line;
            }
        }
        if ($current !== null) {
            $jobs[$current] = implode("\n", $buffer);
        }

        return $jobs;
    }

    /**
     * The directory a job works in: `with.working_directory` for a job that
     * calls a reusable workflow, `defaults.run.working-directory` for one with
     * steps of its own. The repository root when it declares neither.
     *
     * @param array<mixed> $job
     */
    public function jobDirectory(array $job): string
    {
        foreach ([['with', 'working_directory'], ['defaults', 'run', 'working-directory']] as $path) {
            $value = $job;
            foreach ($path as $step) {
                $value = is_array($value) ? ($value[$step] ?? null) : null;
            }
            if (is_string($value)) {
                return $this->relativeDirectory($value);
            }
        }

        return '.';
    }

    /** A declared directory in the spelling extensionDirectory() uses. */
    private function relativeDirectory(string $directory): string
    {
        $directory = trim(trim($directory), '/');
        if (str_starts_with($directory, './')) {
            $directory = substr($directory, 2);
        }

        return $directory === '' ? '.' : $directory;
    }

    /**
     * A workflow's parsed document, null when it is absent or does not parse,
     * with $error saying which of the two it was.
     *
     * @return array<mixed>|null
     */
    private function workflowData(string $workflow, ?string &$error = null): ?array
    {
        if (!array_key_exists($workflow, $this->workflowData)) {
            $parsed = Policy::parseYaml($this->read($workflow) ?? '', $failure);
            $this->workflowData[$workflow] = is_array($parsed) ? $parsed : null;
            $this->workflowDataError[$workflow] = $failure;
        }
        $error = $this->workflowDataError[$workflow];

        return $this->workflowData[$workflow];
    }

    /**
     * Every extension of the repository: each direct subdirectory of the
     * repository root that carries an info.xml, directory name => parsed
     * info.xml. Unparsable XML is dropped — the check that needs the file is
     * the one that reports it.
     *
     * @return array<string, \SimpleXMLElement>
     */
    public function repositoryExtensions(): array
    {
        if ($this->repositoryExtensions === null) {
            $this->repositoryExtensions = [];
            $repositoryRoot = $this->repositoryRoot();
            foreach (scandir($repositoryRoot) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || !is_file($repositoryRoot . '/' . $entry . '/info.xml')) {
                    continue;
                }
                $raw = (string) file_get_contents($repositoryRoot . '/' . $entry . '/info.xml');
                $previous = libxml_use_internal_errors(true);
                $parsed = simplexml_load_string($raw);
                libxml_use_internal_errors($previous);
                if ($parsed !== false) {
                    $this->repositoryExtensions[$entry] = $parsed;
                }
            }
            ksort($this->repositoryExtensions);
        }

        return $this->repositoryExtensions;
    }

    /**
     * A repository that is no extension itself but holds several: the layout
     * the monorepo checks apply to. Detected from the filesystem, because
     * nothing declares it.
     */
    public function isMonorepo(): bool
    {
        return !is_file($this->repositoryRoot() . '/info.xml') && $this->repositoryExtensions() !== [];
    }

    /** The git working tree this extension belongs to, absolute. */
    public function repositoryRoot(): string
    {
        $directory = rtrim((string) realpath($this->root), '/');
        $fallback = $directory;
        while ($directory !== '' && $directory !== dirname($directory)) {
            if (file_exists($directory . '/.git')) {
                return $directory;
            }
            $directory = dirname($directory);
        }

        return $fallback;
    }

    private function git(array $args): ?string
    {
        // safe.directory: in CI the checkout is owned by the runner user while
        // the tools run as www-data, and git then refuses the repo ("dubious
        // ownership") — which silently degraded every tracked-files check to
        // the disk-walk fallback. This tool only ever READS the repo, so
        // trusting the one directory it was pointed at is sound.
        $command = 'git -c ' . escapeshellarg('safe.directory=' . $this->repositoryRoot())
            . ' -C ' . escapeshellarg($this->root);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return null;
        }
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        return ($status === 0 && is_string($stdout)) ? $stdout : null;
    }
}
