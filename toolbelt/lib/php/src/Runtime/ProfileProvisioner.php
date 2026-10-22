<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Runtime;

use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Repository\Files;
use RuntimeException;

/**
 * A profile's `repo`, `registry` and `enable` dependencies, as the site's web user. A swapped-out
 * packaged copy is recorded in the replaced-state file as target<TAB>backup, for the caller to restore.
 */
final class ProfileProvisioner
{
    private const DISCOVER = '$c=CRM_Extension_System::singleton()->getFullContainer(); foreach ($c->getKeys() as $k) { echo $k . "\t" . $c->getPath($k) . PHP_EOL; }';

    private readonly ExtensionInspector $inspector;

    private readonly Files $git;

    /** @var resource */
    private $stderr;

    /** @var array<string, string> every extension the site can see, across all scanned paths: key => path */
    private array $local = [];

    /** @param resource|null $stderr where a failed composer run's output goes */
    public function __construct(
        string $checkoutRoot,
        private readonly string $extDir,
        private readonly string $replacedState,
        private readonly ExtensionProvisioner $extensions,
        private readonly Runner $runner = new Runner(),
        $stderr = null,
    ) {
        $this->inspector = new ExtensionInspector($checkoutRoot . '/packages/civikitchen-scenario-schema/vendor/autoload.php');
        $this->git = new Files($checkoutRoot, $runner);
        $this->stderr = $stderr ?? STDERR;
    }

    /** The dependencies of PROFILE (a profile.json) that do not skip UF, the CIVICRM_UF of this site. */
    public function provision(string $profile, string $uf): void
    {
        $name = basename(dirname($profile));
        $dependencies = (new ProfileData())->dependencies($profile, $uf);
        $this->local = $this->discover();

        echo "==> [{$name}] cloning extensions into {$this->extDir}\n";
        $targets = [];
        $placed = false;
        foreach ($dependencies as $dependency) {
            if (isset($dependency['repo'])) {
                [$target, $changed] = $this->checkout(self::text($dependency, 'repo'), self::text($dependency, 'name'), self::text($dependency, 'version'));
                $targets[] = $target;
                $placed = $placed || $changed;
            }
        }
        // A registry dependency's <requires> would miss a checkout the cached extension map predates.
        if ($placed) {
            $this->extensions->refresh();
        }

        if ($targets !== []) {
            echo "==> [{$name}] resolving composer dependencies of git-sourced extensions\n";
            foreach ($targets as $target) {
                $this->composer($target);
            }
        }

        echo "==> [{$name}] downloading registry extensions\n";
        foreach ($dependencies as $dependency) {
            $key = self::text($dependency, 'name');
            if (($dependency['registry'] ?? false) !== true) {
                continue;
            }
            if (isset($this->local[$key]) || is_dir("{$this->extDir}/{$key}")) {
                echo "  {$key} already present\n";
                continue;
            }
            echo "  downloading {$key}\n";
            $this->extensions->fetch($key);
        }

        // One at a time, in profile order: an installer may need what an earlier one created.
        echo "==> [{$name}] enabling extensions\n";
        foreach ($dependencies as $dependency) {
            $key = self::text($dependency, 'name');
            if (($dependency['enable'] ?? false) === true && $this->runner->passthrough(['cv', 'ext:enable', $key]) !== 0) {
                throw new RuntimeException("cv ext:enable {$key} failed");
            }
        }
    }

    /** @return array<string, string> */
    private function discover(): array
    {
        $result = $this->runner->captureStdout(['cv', 'ev', self::DISCOVER]);
        if ($result['status'] !== 0) {
            throw new RuntimeException('could not list the extensions the site can see');
        }
        $local = [];
        foreach (self::lines($result['output']) as $line) {
            [$key, $path] = array_pad(explode("\t", $line, 2), 2, '');
            $local[$key] ??= $path;
        }
        return $local;
    }

    /**
     * KEY at VERSION from REPO in the ext dir, or converged where another scanned path has it;
     * a key the site has without a path is left alone.
     *
     * @return array{string, bool} the checkout, and whether this run put it in place
     */
    private function checkout(string $repo, string $key, string $version): array
    {
        $target = "{$this->extDir}/{$key}";
        if (!is_dir($target) && ($this->local[$key] ?? '') !== '') {
            $target = $this->local[$key];
        }
        if (!is_dir($target)) {
            if (isset($this->local[$key])) {
                echo "  {$key} already present\n";
                return [$target, false];
            }
            echo "  cloning {$key} @ {$version}\n";
            $temporary = $this->clone($repo, $key, $version);
            if (!$this->move($temporary, $target)) {
                ExtensionArchiveInstaller::removeTree($temporary);
                throw new RuntimeException("could not install pinned {$key}");
            }
            return [$target, true];
        }
        if (!is_dir("{$target}/.git")) {
            $this->replace($repo, $key, $version, $target);
            return [$target, true];
        }
        $expected = $this->revision($target, "{$version}^{commit}");
        $actual = $this->revision($target, 'HEAD');
        if ($expected === '' || $actual !== $expected) {
            throw new RuntimeException("{$target} exists at " . ($actual === '' ? 'unknown' : $actual) . ", not requested ref {$version}");
        }
        echo "  {$key} already present at {$version}\n";
        return [$target, false];
    }

    /**
     * A packaged copy without Git metadata converged to the pin, only if CiviCRM knows it under KEY
     * and never installed it: swapping files back does not undo a database upgrade.
     */
    private function replace(string $repo, string $key, string $version, string $target): void
    {
        if (!isset($this->local[$key]) || $this->inspector->key("{$target}/info.xml") !== $key) {
            throw new RuntimeException("{$target} already exists but is not a verifiable git checkout");
        }
        if (in_array($key, $this->keysWithStatus('installed,disabled', "could not determine database lifecycle status for {$key}"), true)) {
            throw new RuntimeException("refusing to replace database-installed packaged extension {$key}; its lifecycle cannot be rolled back safely");
        }
        if (!in_array($key, $this->keysWithStatus('uninstalled', "could not determine uninstalled extension status for {$key}"), true)) {
            throw new RuntimeException("refusing to replace {$key} with unknown extension lifecycle status");
        }
        echo "  replacing packaged {$key} with pinned commit {$version}\n";
        $temporary = $this->clone($repo, $key, $version);
        $backup = dirname($target) . '/.' . basename($target) . '.packaged.' . getmypid();
        if (file_exists($backup) || is_link($backup)) {
            ExtensionArchiveInstaller::removeTree($temporary);
            throw new RuntimeException("{$backup} already exists");
        }
        // Recorded before the first move, so a crash between the moves stays restorable; a crash while
        // mv copies across overlay layers leaves a partial backup that the restore would put back.
        if (@file_put_contents($this->replacedState, "{$target}\t{$backup}\n", FILE_APPEND) === false) {
            ExtensionArchiveInstaller::removeTree($temporary);
            throw new RuntimeException("could not record the replacement of {$key} in {$this->replacedState}");
        }
        if (!$this->move($target, $backup)) {
            ExtensionArchiveInstaller::removeTree($temporary);
            throw new RuntimeException("could not install pinned {$key}");
        }
        if (!$this->move($temporary, $target)) {
            $this->move($backup, $target);
            ExtensionArchiveInstaller::removeTree($temporary);
            throw new RuntimeException("could not install pinned {$key}");
        }
    }

    /**
     * FROM renamed to TO, which must not exist: mv copies a directory rename() refuses across
     * filesystems, as an overlay's lower layer is to its upper one.
     */
    private function move(string $from, string $to): bool
    {
        return !file_exists($to) && !is_link($to) && $this->runner->passthrough(['mv', $from, $to]) === 0;
    }

    /** REPO at VERSION, declaring KEY, in a hidden directory of the ext dir: a failure leaves no extension behind. */
    private function clone(string $repo, string $key, string $version): string
    {
        $temporary = "{$this->extDir}/.{$key}.clone." . bin2hex(random_bytes(3));
        if (!mkdir($temporary, 0700)) {
            throw new RuntimeException("could not create {$temporary}");
        }
        if ($this->runner->passthrough($this->git->gitCommand(['clone', '--quiet', $repo, $temporary])) !== 0
            || $this->runner->passthrough($this->git->gitCommand(['-C', $temporary, 'checkout', '--quiet', $version])) !== 0) {
            ExtensionArchiveInstaller::removeTree($temporary);
            throw new RuntimeException("could not clone {$key} at requested ref {$version}");
        }
        if ($this->inspector->key("{$temporary}/info.xml") !== $key) {
            ExtensionArchiveInstaller::removeTree($temporary);
            throw new RuntimeException("cloned repository does not declare extension key {$key}");
        }
        return $temporary;
    }

    /** The commit REF names in CHECKOUT, or nothing. */
    private function revision(string $checkout, string $ref): string
    {
        $result = $this->runner->captureSeparate($this->git->gitCommand(['-C', $checkout, 'rev-parse', '--verify', $ref]));
        return $result['status'] === 0 ? trim($result['stdout']) : '';
    }

    /** @return list<string> */
    private function keysWithStatus(string $statuses, string $failure): array
    {
        $result = $this->runner->captureSeparate(['cv', 'ext:list', "--statuses={$statuses}", '--columns=key', '--out=list']);
        if ($result['status'] !== 0) {
            throw new RuntimeException($failure);
        }
        return self::lines($result['stdout']);
    }

    /** A checkout ships no vendor/, so a composer.json with runtime requirements would fatal on enable. */
    private function composer(string $extension): void
    {
        $name = basename($extension);
        if (!is_file("{$extension}/composer.json")) {
            return;
        }
        if (is_dir("{$extension}/vendor")) {
            echo "  {$name} composer dependencies already present\n";
            return;
        }
        // Without a lock file `install` has nothing to install.
        $mode = is_file("{$extension}/composer.lock") ? 'install' : 'update';
        if (!$this->runner->available('composer')) {
            throw new RuntimeException("composer is required to resolve {$name} but is not on PATH");
        }
        echo "  composer {$mode} for {$name}\n";
        $environment = getenv();
        $environment['COMPOSER_HOME'] = ($environment['COMPOSER_HOME'] ?? '') === '' ? '/tmp/composer' : $environment['COMPOSER_HOME'];
        $result = $this->runner->capture(['composer', $mode, '--no-dev', '--no-interaction', '--no-progress', "--working-dir={$extension}"], $environment);
        if ($result['status'] !== 0) {
            fwrite($this->stderr, rtrim($result['output'], "\n") . "\n");
            throw new RuntimeException("composer {$mode} failed for {$name}");
        }
    }

    /** @param array<string, mixed> $dependency */
    private static function text(array $dependency, string $field): string
    {
        return is_string($dependency[$field] ?? null) ? $dependency[$field] : '';
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        return preg_split('/\R/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
