<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Runtime;

use CiviKitchen\Toolbelt\Filesystem\Directory;
use CiviKitchen\Toolbelt\Process\Runner;
use Closure;
use FilesystemIterator;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Installs a site's extensions with their <requires>, as the site's web user: a dependency comes
 * from a release staged in the archive dir, a digest-pinned URL, or the registry.
 */
final class ExtensionProvisioner
{
    private const REFRESH = 'CRM_Extension_System::singleton()->getFullContainer()->refresh();';
    private const REQUIRES = 'echo implode("\n", CRM_Extension_Info::loadFromString(file_get_contents("php://stdin"))->requires);';
    private const ATTEMPTS = 3;
    private const NO_PIN = ['source' => '', 'release' => [], 'version' => ''];

    private readonly ExtensionInspector $inspector;
    private readonly ExtensionArchiveInstaller $installer;
    private readonly string $ckconform;
    private readonly Closure $pause;

    /** @var resource */
    private $stderr;

    /** @var array<string, array<string, list<string>>> ckconform --policy output per directory and policy */
    private array $policies = [];

    /**
     * @param list<string> $mounted the extension directories mounted into the ext dir, in pin precedence
     * @param (Closure(int): void)|null $pause the wait between attempts, in seconds
     * @param resource|null $stderr where warnings go
     */
    public function __construct(
        string $checkoutRoot,
        private readonly string $extDir,
        private readonly string $archiveDir,
        private readonly array $mounted,
        private readonly Runner $runner = new Runner(),
        ?Closure $pause = null,
        $stderr = null,
    ) {
        $this->inspector = new ExtensionInspector($checkoutRoot . '/packages/civikitchen-scenario-schema/vendor/autoload.php');
        $this->installer = new ExtensionArchiveInstaller($this->inspector);
        $this->ckconform = $checkoutRoot . '/toolbelt/bin/ckconform';
        $this->pause = $pause ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $this->stderr = $stderr ?? STDERR;
    }

    /**
     * CIVIKITCHEN_EXTRA_EXTENSIONS, each installed and enabled in order, so a later one finds an earlier
     * one in place. A bare key takes a mounted extension's pin; key@URL is the operator's own source.
     */
    public function extras(string $list): void
    {
        if ($list === '') {
            return;
        }
        echo "[civikitchen] Installing configured extra extensions\n";
        foreach (self::entries($list) as $spec) {
            $key = self::key($spec);
            $pin = $spec === $key ? $this->pin('', $key) : self::NO_PIN;
            $how = match (true) {
                $pin['release'] !== [] => " (staged {$pin['release'][0]} release {$pin['release'][1]})",
                $pin['source'] !== '' => ' (digest-pinned)',
                default => '',
            };
            echo "[civikitchen]   - {$key}{$how}\n";
            $this->install($key, $pin['source'] ?: $spec, $pin, '', []);
            $this->cv(['ext:enable', $key]);
        }
    }

    /**
     * The keys of CIVIKITCHEN_ENABLE_EXTENSIONS (first, in their order, from <ext-dir>/<key>)
     * and every mounted extension, each enabled after its <requires>, pinned by its own directory.
     */
    public function enable(string $list): void
    {
        $directories = [];
        foreach (self::entries($list) as $key) {
            $directories[] = [$key, "{$this->extDir}/{$key}"];
        }
        $listed = array_column($directories, 0);
        foreach ($this->mounted as $directory) {
            // A folder of extensions or an empty workspace is mounted, but no extension.
            if ($directory === '' || !file_exists("{$directory}/info.xml")) {
                continue;
            }
            $key = $this->inspector->key("{$directory}/info.xml");
            if ($key === '') {
                $this->warn("{$directory} is mounted into the ext dir but has no readable info.xml key — not enabled");
                continue;
            }
            if (!in_array($key, $listed, true)) {
                $listed[] = $key;
                $directories[] = [$key, $directory];
            }
        }
        if ($directories === []) {
            return;
        }
        echo '[civikitchen] Enabling extensions: ' . implode(',', $listed) . "\n";
        foreach ($directories as [$key, $directory]) {
            $this->resolve($key, $directory, $directory, []);
            $this->cv(['ext:enable', $key]);
        }
    }

    /** The absent <requires> of the extension in DIRECTORY (default <ext-dir>/KEY), pinned by its civikitchen.yaml. */
    public function requires(string $key, string $directory = ''): void
    {
        $directory = $directory === '' ? "{$this->extDir}/{$key}" : $directory;
        $this->resolve($key, $directory, $directory, []);
    }

    /** KEY downloaded from the registry but not installed, after its <requires> are installed and enabled. */
    public function fetch(string $key): void
    {
        $this->download($key, '', '', [$key]);
    }

    /** Rescan the extension dirs: cv answers from a cached map that lacks directories created since. */
    public function refresh(): void
    {
        $this->cv(['ev', self::REFRESH]);
    }

    /**
     * Install and enable the absent <requires> DIRECTORY's info.xml declares.
     *
     * @param list<string> $chain the extensions whose <requires> wait for KEY's
     */
    private function resolve(string $key, string $directory, string $pinDir, array $chain): void
    {
        $file = "{$directory}/info.xml";
        if (!is_file($file)) {
            return;
        }
        $info = file_get_contents($file);
        if ($info === false) {
            throw new RuntimeException("could not read {$file}");
        }
        $this->installRequirements($key, $pinDir, $this->coreRequires($info, $key), [...$chain, $key]);
    }

    /**
     * @param list<string> $required
     * @param list<string> $chain the extensions whose <requires> wait for these, KEY last
     */
    private function installRequirements(string $key, string $pinDir, array $required, array $chain): void
    {
        foreach ($required as $requirement) {
            // Core refuses to enable any extension of a <requires> cycle.
            if (in_array($requirement, $chain, true)) {
                throw new RuntimeException('<requires> cycle: ' . implode(' ', [...array_slice($chain, (int) array_search($requirement, $chain, true)), $requirement]));
            }
        }
        foreach ($required as $requirement) {
            $pin = $this->pin($pinDir, $requirement);
            if ($this->present($requirement)) {
                if ($pin['version'] !== '') {
                    $info = $this->inspector->load("{$this->extDir}/{$requirement}/info.xml", $requirement);
                    $this->inspector->assertVersion($info, $pin['version']);
                }
                // Core enables a present requirement's own <requires> with it, and refuses an absent one.
                $this->resolve($requirement, $this->directory($requirement), $pinDir, $chain);
                continue;
            }
            $how = match (true) {
                $pin['release'] !== [] => "the staged {$pin['release'][0]} release {$pin['release'][1]}",
                $pin['source'] !== '' => 'the configured digest-pinned source',
                default => 'it from the registry',
            };
            echo "[civikitchen]   {$key} requires {$requirement} — installing {$how}\n";
            $this->install($requirement, $pin['source'] ?: $requirement, $pin, $pinDir, $chain);
            $this->cv(['ext:enable', $requirement]);
        }
    }

    /**
     * KEY from its staged release, else from SPEC, then the <requires> of the installed copy.
     *
     * @param array{source: string, release: list<string>, version: string} $pin
     * @param list<string> $chain
     */
    private function install(string $key, string $spec, array $pin, string $pinDir, array $chain): void
    {
        if ($pin['release'] !== []) {
            $this->installStaged($key, $pin['release'], $pin['version']);
        } else {
            $this->download($spec, $pin['version'], $pinDir, [...$chain, $key]);
        }
        $this->resolve($key, $this->directory($key), $pinDir, $chain);
    }

    /**
     * SPEC — a key, key@URL, or key@URL#sha256=<hex> — downloaded into the ext dir, uninstalled.
     *
     * @param list<string> $chain the extensions whose <requires> wait for this one, its key last
     */
    private function download(string $spec, string $version, string $pinDir, array $chain): void
    {
        $key = self::key($spec);
        $source = substr($spec, strlen($key) + 1);
        if (str_contains($source, '#sha256=')) {
            [$url, $digest] = explode('#sha256=', $source, 2);
            $this->downloadPinned($key, $url, $digest, $version);
            return;
        }
        // Core's download queue lands an extension only once its <requires> are
        // installed — not merely present, as a core-bundled one is until enabled.
        $required = $this->archiveRequires($spec, $key);
        if ($required !== []) {
            $this->installRequirements($key, $pinDir, $required, $chain);
            $this->cv(['ext:enable', ...$required]);
        }
        // Core bounds the whole archive transfer by http_timeout (5 s default),
        // which a multi-MB release from a slow mirror overruns.
        $this->cvOutput(['vset', 'http_timeout=120']);
        try {
            for ($attempt = 1; $this->runner->passthrough(['cv', 'ext:download', '-n', '--no-install', $spec]) !== 0; $attempt++) {
                if ($attempt === self::ATTEMPTS) {
                    throw new RuntimeException("download of {$key} failed after {$attempt} attempts");
                }
                $this->warn("download of {$key} failed (attempt {$attempt}/" . self::ATTEMPTS . '); retrying in 5s...');
                ($this->pause)(5);
            }
        } catch (Throwable $e) {
            // A reset that fails as well is a warning: the failed download is the error to report.
            try {
                $this->cvOutput(['vdel', 'http_timeout']);
            } catch (RuntimeException $reset) {
                $this->warn($reset->getMessage());
            }
            throw $e;
        }
        $this->cvOutput(['vdel', 'http_timeout']);
        // An earlier ext:enable left the next cv process a cached extension map without this key.
        $this->refresh();
    }

    /**
     * <requires> of a registry or key@URL download, from its archive — over whichever protocol cv uses.
     *
     * @return list<string>
     */
    private function archiveRequires(string $spec, string $key): array
    {
        $archive = $this->temporaryFile();
        try {
            for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
                if ($attempt > 1) {
                    ($this->pause)(5);
                }
                $url = $this->downloadUrl($spec);
                if ($url !== null && $this->curl($url, $archive, '=http,https')) {
                    return $this->coreRequires($this->installer->info($archive, $key), $key);
                }
            }
        } finally {
            self::remove($archive);
        }
        throw new RuntimeException("could not fetch {$key} to read its <requires>");
    }

    /**
     * Where cv ext:download fetches SPEC from: its key@URL, else the feed cv reads. Null only
     * when the feed could not be read, which another attempt may fix.
     */
    private function downloadUrl(string $spec): ?string
    {
        $key = self::key($spec);
        if ($key !== $spec) {
            return substr($spec, strlen($key) + 1);
        }
        $listing = $this->runner->captureStdout(['cv', 'ext:list', '-R', '-r', '--columns=key,name,downloadUrl', '--out=json-strict']);
        if ($listing['status'] !== 0) {
            return null;
        }
        try {
            $extensions = self::json($listing['output'], 'the extension feed');
        } catch (RuntimeException $e) {
            $this->warn($e->getMessage());
            return null;
        }
        $named = [];
        foreach ($extensions as $extension) {
            if (!is_array($extension)) {
                continue;
            }
            $url = $extension['downloadUrl'] ?? '';
            if (($extension['key'] ?? null) === $key && is_string($url) && $url !== '') {
                return $url;
            }
            if (($extension['name'] ?? null) === $key && is_string($extension['key'] ?? null)) {
                $named[] = $extension['key'];
            }
        }
        // A short name downloads under its key, which the <requires> and pins never match.
        throw new RuntimeException($named === [] ? "{$key} is not in the extension feed" : "{$key} is not an extension key; use " . implode(' or ', $named));
    }

    private function downloadPinned(string $key, string $url, string $digest, string $version): void
    {
        $archive = $this->temporaryFile();
        try {
            for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
                if ($attempt > 1) {
                    ($this->pause)(5);
                }
                if (!$this->curl($url, $archive, '=https')) {
                    continue;
                }
                // Outside the retry: wrong bytes stay wrong, another fetch gets them again.
                self::verify($archive, $key, $digest);
                try {
                    $this->installArchive($archive, $key, $version);
                    return;
                } catch (RuntimeException $e) {
                    $this->warn("could not install {$key}: {$e->getMessage()}");
                }
            }
        } finally {
            self::remove($archive);
        }
        throw new RuntimeException("digest-pinned download of {$key} failed");
    }

    /**
     * A dependency whose release archive was staged by the runner: no network and no credential
     * in here, and a missing archive is an error, never a fallback to the registry.
     *
     * @param list<string> $release repository, tag, asset, sha256
     */
    private function installStaged(string $key, array $release, string $version): void
    {
        [, $tag, $asset, $digest] = $release;
        if ($this->archiveDir === '') {
            throw new RuntimeException("{$key} is pinned to release {$tag}, which has to be staged — set CK_DEP_ARCHIVE_DIR, or mount {$key} into the ext dir");
        }
        $staged = "{$this->archiveDir}/{$asset}";
        if (!is_file($staged)) {
            throw new RuntimeException("{$key} is pinned to release {$tag}, but {$staged} is not there");
        }
        // Verified and unpacked from a private copy, so the checked bytes are the installed ones.
        $archive = $this->temporaryFile();
        try {
            if (!copy($staged, $archive)) {
                throw new RuntimeException("could not copy {$staged}");
            }
            self::verify($archive, $key, $digest);
            $this->installArchive($archive, $key, $version);
        } finally {
            self::remove($archive);
        }
    }

    /** A verified archive unpacked into <ext-dir>/KEY, readable by the web server, and the extension map refreshed. */
    private function installArchive(string $archive, string $key, string $version): void
    {
        $target = "{$this->extDir}/{$key}";
        $this->installer->install($archive, $key, $target, $version);
        try {
            self::normalizeModes($target);
            // A prior Extension.get cached the directory map before this key existed.
            $this->refresh();
        } catch (RuntimeException $e) {
            Directory::remove($target);
            throw $e;
        }
    }

    /**
     * The site has KEY — core, downloaded or mounted — per APIv4, which reads the local extension
     * container only. Files gone (*-missing: a kept database in a new container) count as absent.
     */
    private function present(string $key): bool
    {
        $result = $this->runner->captureStdout(['cv', 'api4', 'Extension.get', '+w', "key={$key}", '+s', 'key,status', '--out=json-strict']);
        if ($result['status'] !== 0) {
            throw new RuntimeException("could not query the extension list for {$key}");
        }
        foreach (self::json($result['output'], 'the extension list') as $extension) {
            if (is_array($extension) && ($extension['key'] ?? null) === $key
                && !(is_string($extension['status'] ?? null) && str_ends_with($extension['status'], '-missing'))) {
                return true;
            }
        }
        return false;
    }

    /**
     * <requires> as core reads them, without the keys its extension-compatibility.json declares obsolete.
     *
     * @return list<string>
     */
    private function coreRequires(string $info, string $key): array
    {
        $result = $this->runner->captureStdout(['cv', 'ev', self::REQUIRES], $info);
        if ($result['status'] !== 0) {
            throw new RuntimeException("could not read the <requires> of {$key}");
        }
        return self::lines($result['output']);
    }

    /**
     * KEY's pin in PIN_DIR's civikitchen.yaml, read through ckconform, its one parser; without a
     * PIN_DIR, the first mounted extension's that pins KEY.
     *
     * @return array{source: string, release: list<string>, version: string}
     */
    private function pin(string $pinDir, string $key): array
    {
        foreach ($pinDir === '' ? $this->mounted : [$pinDir] as $directory) {
            $source = $this->policy($directory, 'extension_source', $key, '@');
            $release = $this->policy($directory, 'extension_release', $key, ' ');
            if ($source !== '' || $release !== '') {
                return [
                    'source' => $source === '' ? '' : "{$key}@{$source}",
                    'release' => $release === '' ? [] : array_pad(preg_split('/\s+/', $release, 4) ?: [], 4, ''),
                    'version' => $this->policy($directory, 'extension_version', $key, '@'),
                ];
            }
        }
        return self::NO_PIN;
    }

    /** What follows KEY and SEPARATOR in a value of POLICY, without its ` -- reason`. */
    private function policy(string $directory, string $policy, string $key, string $separator): string
    {
        if (!is_file("{$directory}/civikitchen.yaml")) {
            return '';
        }
        if (!isset($this->policies[$directory][$policy])) {
            $result = $this->runner->captureStdout([$this->ckconform, '--policy', $policy], null, $directory);
            if ($result['status'] !== 0) {
                throw new RuntimeException("could not read {$directory}/civikitchen.yaml");
            }
            $this->policies[$directory][$policy] = self::lines($result['output']);
        }
        foreach ($this->policies[$directory][$policy] as $line) {
            [$name, $value] = array_pad(explode($separator, explode(' -- ', $line, 2)[0], 2), 2, '');
            if ($name === $key) {
                return $value;
            }
        }
        return '';
    }

    /** URL to FILE over PROTOCOLS, with at most three redirects; timed out, so three attempts bound the job. */
    private function curl(string $url, string $file, string $protocols): bool
    {
        return $this->runner->passthrough([
            'curl', '--fail', '--silent', '--show-error', '--location', '--proto', $protocols, '--proto-redir', $protocols,
            '--max-redirs', '3', '--max-filesize', '134217728', '--connect-timeout', '20', '--max-time', '300', $url, '-o', $file,
        ]) === 0;
    }

    /** Where the site has KEY, by default <ext-dir>/KEY. */
    private function directory(string $key): string
    {
        $directory = $this->cvOutput(['path', '-x', $key]);
        return $directory === '' ? "{$this->extDir}/{$key}" : $directory;
    }

    /** @param list<string> $arguments */
    private function cv(array $arguments): void
    {
        if ($this->runner->passthrough(['cv', ...$arguments]) !== 0) {
            throw new RuntimeException('cv ' . implode(' ', $arguments) . ' failed');
        }
    }

    /** @param list<string> $arguments */
    private function cvOutput(array $arguments): string
    {
        $result = $this->runner->captureStdout(['cv', ...$arguments]);
        if ($result['status'] !== 0) {
            throw new RuntimeException('cv ' . implode(' ', $arguments) . ' failed');
        }
        return rtrim($result['output'], "\n");
    }

    private function warn(string $message): void
    {
        fwrite($this->stderr, "[civikitchen] WARN: {$message}\n");
    }

    private function temporaryFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'civikitchen-extension.');
        if ($file === false) {
            throw new RuntimeException('could not create a temporary extension archive');
        }
        return $file;
    }

    private static function verify(string $archive, string $key, string $digest): void
    {
        if (hash_file('sha256', $archive) !== $digest) {
            throw new RuntimeException("checksum mismatch for {$key}");
        }
    }

    /** chmod -R u=rwX,go=rX */
    private static function normalizeModes(string $directory): void
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ([new SplFileInfo($directory), ...iterator_to_array($entries, false)] as $entry) {
            $mode = $entry->isDir() || ($entry->getPerms() & 0111) !== 0 ? 0755 : 0644;
            if (!chmod($entry->getPathname(), $mode)) {
                throw new RuntimeException("could not set the mode of {$entry->getPathname()}");
            }
        }
    }

    private static function remove(string $file): void
    {
        if (is_file($file)) {
            unlink($file);
        }
    }

    private static function key(string $spec): string
    {
        return explode('@', $spec, 2)[0];
    }

    /** @return list<string> the entries of a comma-separated list, whitespace removed */
    private static function entries(string $list): array
    {
        return array_values(array_filter(explode(',', (string) preg_replace('/\s+/', '', $list)), static fn (string $entry): bool => $entry !== ''));
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        return preg_split('/\R/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @return array<mixed> */
    private static function json(string $text, string $what): array
    {
        try {
            $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("{$what} is not JSON: {$e->getMessage()}", 0, $e);
        }
        return is_array($data) ? $data : [];
    }
}
