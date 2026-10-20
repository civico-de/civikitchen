<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Cli;

use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Seed\Calendar;
use CiviKitchen\Toolbelt\Seed\Context;
use CiviKitchen\Toolbelt\Seed\Database;
use CiviKitchen\Toolbelt\Seed\DateShift;
use CiviKitchen\Toolbelt\Seed\DumpPolicy;
use CiviKitchen\Toolbelt\Seed\Store;
use RuntimeException;

/**
 * Seed snapshots: a profile set with `snapshot` blocks is generated once, dumped
 * into the store, and restored with its dates shifted on every later boot.
 */
final class SeedCommand implements Command
{
    /** Exit status of `restore` when there is nothing to restore: the caller generates. */
    public const MISS = 3;

    private const USAGE = <<<'TXT'
ck seed — generate seed data once, restore it from a snapshot afterwards

  ck seed restore <profile-dir>     before a profile's content: restores the
                                    selected set's snapshot at its first snapshot
                                    profile; exits 3 when the profile is to be
                                    applied (no snapshot, or not covered by it)
  ck seed save <profile-dir>        after a profile's content: dumps a freshly
                                    generated set after its last snapshot profile
  ck seed status [--generated]      key, generation date, validity and dump size;
                                    --generated prints the key only when this
                                    boot generated and stored the snapshot
  ck seed rebuild                   drop this site's snapshot, so the next fresh
                                    boot generates it again
  ck seed prune [--keep=N]          keep the newest N (2) per profile and size
  ck seed today                     the generation date in force (CK_SEED_TODAY)

The profile driver runs restore and save at first boot; the selected profiles
come from CK_SEED_PROFILES. status and rebuild use this site's state.
Store: CK_SEED_STORE (/var/lib/civikitchen/snapshots). Size: CK_SEED_SIZE (demo).
TXT;

    private readonly Database $database;
    private readonly Store $store;

    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner = new Runner(),
        ?Database $database = null,
        ?Store $store = null,
    ) {
        $this->database = $database ?? Database::fromEnvironment();
        $this->store = $store ?? Store::fromEnvironment();
    }

    public function run(array $arguments): int
    {
        $command = array_shift($arguments) ?? 'help';
        try {
            return match ($command) {
                'help', '-h', '--help' => $this->help(),
                'today' => $this->today(),
                'restore' => $this->restore($arguments[0] ?? ''),
                'save' => $this->save($arguments[0] ?? ''),
                'status' => $this->status(in_array('--generated', $arguments, true)),
                'rebuild' => $this->rebuild(),
                'prune' => $this->prune($arguments),
                default => $this->unknown($command),
            };
        } catch (RuntimeException $e) {
            fwrite(STDERR, "ck seed: {$e->getMessage()}\n");
            return 1;
        }
    }

    private function help(): int
    {
        echo self::USAGE, "\n";
        return 0;
    }

    private function unknown(string $command): int
    {
        fwrite(STDERR, "ck seed: unknown command: {$command}\n" . self::USAGE . "\n");
        return 2;
    }

    private function today(): int
    {
        echo Calendar::today()->format('Y-m-d'), "\n";
        return 0;
    }

    /**
     * Called before each selected profile's content. At the first snapshot
     * profile it restores the set's snapshot; later profiles up to the last
     * snapshot profile are then already in it. MISS means: apply the profile.
     */
    private function restore(string $profile): int
    {
        if ($profile === '') {
            throw new RuntimeException('restore needs the profile directory');
        }
        $context = Context::fromEnvironment($profile, $this->libraries());
        if (!$context->hasSnapshot()) {
            return self::MISS;
        }
        $today = Calendar::today();
        $key = $context->key($today)['key'];
        if (Context::directory($profile) !== $context->first()) {
            $state = $this->readState();
            $restored = $state !== null && $state['mode'] === 'restored' && $state['key'] === $key && $context->covers($profile);
            return $restored ? 0 : self::MISS;
        }
        $status = $this->restoreSet($context, $today, $key);
        if ($status === self::MISS) {
            $this->writeState($context, $key, 'generating', []);
        }
        return $status;
    }

    private function restoreSet(Context $context, \DateTimeImmutable $today, string $key): int
    {
        $label = "{$context->name()} ({$context->size}, key {$key})";
        $meta = $this->store->find($context->name(), $context->size, $key);
        if ($meta === null) {
            $this->say("no snapshot for {$label} in {$this->store->directory}; generating one");
            return self::MISS;
        }
        $generated = Calendar::day((string) $meta['generated_on']);
        if (!$context->calendar()->covers($generated, $today)) {
            $until = $context->calendar()->validUntil($generated)?->format('Y-m-d') ?? 'any day';
            $this->say("snapshot {$label} from {$generated->format('Y-m-d')} is valid until {$until}, not on {$today->format('Y-m-d')}; generating a new one");
            return self::MISS;
        }
        $site = $this->database->coreVersion();
        if ($site === '') {
            throw new RuntimeException("database {$this->database->name()} holds no CiviCRM site to restore over");
        }
        $made = (string) $meta['core_version'];
        if (version_compare($made, $site, '>')) {
            $this->say("snapshot {$label} was made on CiviCRM {$made}, newer than this site's {$site}; a dump cannot be downgraded, generating a new one");
            return self::MISS;
        }
        foreach (['zstd', 'mysql'] as $tool) {
            if (!$this->runner->available($tool)) {
                throw new RuntimeException("{$tool} is not installed; the image needs it to restore {$label}");
            }
        }
        $dump = $this->store->dump($context->name(), $context->size, $key);
        $days = Calendar::days($generated, $today);
        $this->say("restoring {$label}, generated on {$generated->format('Y-m-d')}, shifted by {$days} days");
        $this->timed('load', function () use ($dump): void {
            $this->database->dropEverything();
            [$client, $environment] = $this->database->client();
            if ($this->runner->pipe(['zstd', '-dcq', $dump], ['mysql', ...$client, $this->database->name()], $environment) !== 0) {
                throw new RuntimeException("could not load {$dump}; the site database is incomplete, recreate the stack");
            }
        });
        $this->timed('shift', function () use ($days): string {
            $shifted = (new DateShift($this->database))->run($days);
            return "{$shifted['rows']} rows in {$shifted['tables']} tables";
        });
        if (version_compare($made, $site, '<')) {
            $this->timed("upgrade {$made} -> {$site}", fn() => $this->cv(['upgrade:db', '-n']));
        }
        $this->timed('after-restore', fn() => $this->cv(['scr', '--user=admin', $this->checkoutRoot . '/toolbelt/lib/seed-after-restore.php'],
            ['CK_SEED_AFTER_RESTORE' => implode("\n", $context->afterRestore())]));
        $this->writeState($context, $key, 'restored', $meta + ['restored_on' => $today->format('Y-m-d'), 'shift_days' => $days]);
        return 0;
    }

    /** Called after each selected profile's content; dumps after the last snapshot profile of a generated set. */
    private function save(string $profile): int
    {
        if ($profile === '') {
            throw new RuntimeException('save needs the profile directory');
        }
        $context = Context::fromEnvironment($profile, $this->libraries());
        if (!$context->hasSnapshot() || Context::directory($profile) !== $context->last()) {
            return 0;
        }
        $today = Calendar::today();
        ['key' => $key, 'inputs' => $inputs] = $context->key($today);
        $state = $this->readState();
        if ($state === null || $state['mode'] !== 'generating' || $state['key'] !== $key) {
            $this->say("{$context->name()} was not generated under key {$key} on this boot; nothing stored");
            return 0;
        }
        $partial = $this->store->prepare($context->name(), $context->size, $key);
        $this->timed('dump', function () use ($partial): void {
            [$client, $environment] = $this->database->client();
            $arguments = DumpPolicy::dumpArguments($this->database->name(), $this->database->tables());
            $status = $this->runner->pipe(['mysqldump', ...$client, ...$arguments, $this->database->name()],
                ['zstd', '-q', '-3', '-T0', '-c'], $environment, $partial);
            if ($status !== 0) {
                @unlink($partial);
                throw new RuntimeException('mysqldump | zstd failed; no snapshot stored');
            }
        });
        $meta = [
            'profile' => $context->name(),
            'size' => $context->size,
            'key' => $key,
            'calendar' => $context->calendar()->unit,
            'generated_on' => $today->format('Y-m-d'),
            'valid_until' => $context->calendar()->validUntil($today)?->format('Y-m-d'),
            'core_version' => $this->database->coreVersion(),
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'inputs' => $inputs,
        ];
        $this->store->commit($partial, $meta);
        $dump = $this->store->dump($context->name(), $context->size, $key);
        $this->say(sprintf('stored %s (%.1f MB)', $dump, (int) filesize($dump) / 1e6));
        foreach ($this->store->prune(2, $context->name(), $context->size) as $removed) {
            $this->say("pruned {$removed}");
        }
        $this->writeState($context, $key, 'generated', $meta);
        return 0;
    }

    private function status(bool $generatedKey): int
    {
        $state = $this->readState();
        if ($generatedKey) {
            // A restored, expired or unsaved set gives nothing to store, even under an unchanged key.
            echo $state !== null && $state['mode'] === 'generated' ? $state['key'] . "\n" : '';
            return 0;
        }
        if ($state === null || $state['mode'] === 'generating') {
            fwrite(STDERR, "ck seed: this site was not seeded from a snapshot profile\n");
            return 0;
        }
        $dump = $this->store->dump((string) $state['profile_name'], (string) $state['size'], (string) $state['key']);
        $lines = [
            'profiles' => implode(', ', array_map('basename', is_array($state['profiles']) ? $state['profiles'] : [])),
            'size' => $state['size'],
            'key' => $state['key'],
            'seeded by' => $state['mode'] . (isset($state['restored_on']) ? " on {$state['restored_on']}, shifted {$state['shift_days']} days" : ''),
            'generated on' => $state['generated_on'] . ' (CiviCRM ' . $state['core_version'] . ')',
            'valid until' => $state['valid_until'] ?? 'no calendar limit',
            'dump' => is_file($dump) ? sprintf('%s (%.1f MB)', $dump, (int) filesize($dump) / 1e6) : "{$dump} (not in the store)",
        ];
        $current = Context::fromState($state, $this->libraries())->key(Calendar::today())['key'];
        if ($current !== $state['key']) {
            $lines['current key'] = "{$current}: the profile, size, extensions or period changed; a fresh boot generates it";
        }
        foreach ($lines as $name => $value) {
            printf("%-13s %s\n", $name, (string) $value);
        }
        return 0;
    }

    private function rebuild(): int
    {
        $state = $this->readState() ?? throw new RuntimeException('this site was not seeded from a snapshot profile');
        $found = $this->store->remove((string) $state['profile_name'], (string) $state['size'], (string) $state['key']);
        $this->say(($found ? 'removed' : 'no stored') . " snapshot {$state['profile_name']}-{$state['size']}-{$state['key']}; "
            . 'recreate the stack (docker compose down -v && docker compose up -d) to generate it again');
        return 0;
    }

    /** @param list<string> $arguments */
    private function prune(array $arguments): int
    {
        $keep = 2;
        foreach ($arguments as $argument) {
            if (preg_match('/^--keep=(\d+)$/', $argument, $match) !== 1 || (int) $match[1] < 1) {
                throw new RuntimeException("prune takes --keep=N with N >= 1, not {$argument}");
            }
            $keep = (int) $match[1];
        }
        $removed = $this->store->prune($keep);
        foreach ($removed as $file) {
            $this->say("pruned {$file}");
        }
        $this->say(count($removed) . " snapshots removed, the newest {$keep} per profile and size kept");
        return 0;
    }

    /** @return list<string> */
    private function libraries(): array
    {
        return [
            $this->checkoutRoot . '/docker/profiles/lib.php',
            $this->checkoutRoot . '/docker/profiles/seed-lib.php',
            $this->checkoutRoot . '/toolbelt/lib/php/src/Seed/Calendar.php',
        ];
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $extra
     */
    private function cv(array $arguments, array $extra = []): void
    {
        $status = $this->runner->passthrough(['cv', ...$arguments], [...getenv(), ...$extra]);
        if ($status !== 0) {
            throw new RuntimeException('cv ' . implode(' ', $arguments) . " failed ({$status})");
        }
    }

    private function timed(string $step, callable $work): void
    {
        $start = microtime(true);
        $detail = $work();
        $this->say(sprintf('  %-14s %5.1f s%s', $step, microtime(true) - $start, is_string($detail) ? ", {$detail}" : ''));
    }

    private function say(string $message): void
    {
        echo "[ck seed] {$message}\n";
    }

    private function statePath(): string
    {
        $path = getenv('CK_SEED_STATE');
        return is_string($path) && $path !== '' ? $path : '/var/www/html/private/.civikitchen-seed.json';
    }

    /** @param array<string, mixed> $meta */
    private function writeState(Context $context, string $key, string $mode, array $meta): void
    {
        unset($meta['inputs']);
        $state = [...$meta, ...$context->toArray(), 'profile_name' => $context->name(), 'key' => $key, 'mode' => $mode];
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->statePath(), $json . "\n") === false) {
            throw new RuntimeException("cannot write {$this->statePath()}");
        }
    }

    /** @return array<string, mixed>|null */
    private function readState(): ?array
    {
        if (!is_file($this->statePath())) {
            return null;
        }
        $state = json_decode((string) file_get_contents($this->statePath()), true, 512, JSON_THROW_ON_ERROR);
        return is_array($state) && isset($state['key'], $state['profiles'], $state['mode']) ? $state : null;
    }
}
