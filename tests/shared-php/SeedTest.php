<?php

declare(strict_types=1);

use CiviKitchen\Toolbelt\Cli\Application;
use CiviKitchen\Toolbelt\Cli\SeedCommand;
use CiviKitchen\Toolbelt\Process\Runner;
use CiviKitchen\Toolbelt\Runtime\ProfileData;
use CiviKitchen\Toolbelt\Seed\Calendar;
use CiviKitchen\Toolbelt\Seed\Context;
use CiviKitchen\Toolbelt\Seed\Database;
use CiviKitchen\Toolbelt\Seed\DateShift;
use CiviKitchen\Toolbelt\Seed\DumpPolicy;
use CiviKitchen\Toolbelt\Seed\Store;
use PHPUnit\Framework\TestCase;

final class SeedTest extends TestCase
{
    private string $temporary;

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir() . '/civikitchen-seed-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->temporary, 0700));
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->temporary, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->temporary);
    }

    public function testTodayIsTheOverrideOrTheContainerDate(): void
    {
        $this->setEnvironment('CK_SEED_TODAY', '2027-03-04');
        self::assertSame('2027-03-04', Calendar::today()->format('Y-m-d'));
        $this->setEnvironment('CK_SEED_TODAY', '');
        self::assertSame(date('Y-m-d'), Calendar::today()->format('Y-m-d'));
        $this->expectException(RuntimeException::class);
        Calendar::day('2027-02-30');
    }

    /** @return iterable<string, array{string}> */
    public static function daysOfTheYear(): iterable
    {
        foreach (['2027-01-02', '2027-06-30', '2027-12-30', '2028-01-01', '2028-02-29', '2028-12-31'] as $day) {
            yield $day => [$day];
        }
    }

    /**
     * A record of an earlier year, shifted by the most a restore of this snapshot shifts, stays in that year.
     *
     * @dataProvider daysOfTheYear
     */
    public function testYearBucketsSurviveEveryShiftTheSnapshotAllows(string $day): void
    {
        $calendar = new Calendar('year');
        $today = Calendar::day($day);
        $until = $calendar->validUntil($today);
        self::assertNotNull($until);
        self::assertSame($today->format('Y'), $until->format('Y'), 'a snapshot never outlives its year');
        $slack = Calendar::days($today, $until);
        foreach ([0, 1, 2] as $ago) {
            [$first, $last] = $calendar->window($today, $ago);
            self::assertLessThanOrEqual($last, $first);
            self::assertSame($calendar->start($today, $ago)->format('Y-m-d'), $first->format('Y-m-d'));
            self::assertSame($first->format('Y'), $last->modify("+{$slack} days")->format('Y'), "{$day}, {$ago} years back");
        }
        self::assertSame($day, $calendar->window($today, 0)[1]->format('Y-m-d'));
    }

    public function testMonthBucketsSurviveTheShortestMonth(): void
    {
        $calendar = new Calendar('month');
        foreach (['2027-01-31', '2027-02-01', '2027-03-15', '2028-02-29'] as $day) {
            $today = Calendar::day($day);
            $until = $calendar->validUntil($today);
            self::assertNotNull($until);
            $slack = Calendar::days($today, $until);
            foreach ([1, 2, 12] as $ago) {
                [$first, $last] = $calendar->window($today, $ago);
                self::assertSame($first->format('Y-m'), $last->modify("+{$slack} days")->format('Y-m'), "{$day}, {$ago} months back");
            }
        }
    }

    public function testValidityEndsWithThePeriod(): void
    {
        $year = new Calendar('year');
        $generated = Calendar::day('2027-01-02');
        self::assertTrue($year->covers($generated, Calendar::day('2027-12-31')));
        self::assertFalse($year->covers($generated, Calendar::day('2028-01-01')), 'next year invalidates');
        self::assertFalse($year->covers($generated, Calendar::day('2027-01-01')), 'never restored before it was made');
        self::assertSame('2027', $year->period($generated));
        self::assertSame('2027-01', (new Calendar('month'))->period($generated));
        $none = new Calendar('none');
        self::assertNull($none->validUntil($generated));
        self::assertTrue($none->covers($generated, Calendar::day('2040-01-01')));
        self::assertSame('', $none->period($generated));
        $this->expectException(RuntimeException::class);
        new Calendar('week');
    }

    public function testDateShiftMovesOnlyDumpedCiviTables(): void
    {
        $statements = DateShift::statements([
            ['civicrm_contribution', 'receive_date'],
            ['civicrm_contribution', 'cancel_date'],
            ['civicrm_value_extra_1', 'visit'],
            ['civicrm_cache', 'expired_date'],
            ['civicrm_queue_item', 'submit_time'],
            ['civicrm_sk_members', 'joined'],
            ['log_civicrm_contact', 'log_date'],
            ['cms_users', 'created'],
        ], 7);
        self::assertSame([
            'civicrm_contribution' => 'UPDATE `civicrm_contribution` SET `receive_date` = `receive_date` + INTERVAL 7 DAY, '
                . '`cancel_date` = `cancel_date` + INTERVAL 7 DAY WHERE `receive_date` IS NOT NULL OR `cancel_date` IS NOT NULL',
            'civicrm_value_extra_1' => 'UPDATE `civicrm_value_extra_1` SET `visit` = `visit` + INTERVAL 7 DAY WHERE `visit` IS NOT NULL',
        ], $statements);
    }

    public function testDumpKeepsStructureButNotRuntimeRows(): void
    {
        $arguments = DumpPolicy::dumpArguments('site', ['civicrm_contact', 'civicrm_cache', 'civicrm_queue', 'civicrm_queue_item',
            'civicrm_sk_members', 'log_civicrm_contact', 'civicrm_group_contact_cache', 'civicrm_job_log']);
        self::assertContains('--skip-triggers', $arguments);
        self::assertNotContains('--ignore-table-data=site.civicrm_contact', $arguments);
        foreach (['civicrm_cache', 'civicrm_queue', 'civicrm_queue_item', 'civicrm_sk_members', 'log_civicrm_contact',
            'civicrm_group_contact_cache', 'civicrm_job_log'] as $table) {
            self::assertContains("--ignore-table-data=site.{$table}", $arguments);
        }
        self::assertFalse(DumpPolicy::skipsData('civicrm_contribution'));
    }

    public function testKeyFollowsEveryInput(): void
    {
        $profile = $this->snapshotProfile('sample-data', ['calendar' => 'year', 'sizes' => ['demo', 'bench']]);
        $extension = $this->temporary . '/ext';
        mkdir($extension . '/schema', 0700, true);
        file_put_contents($extension . '/info.xml', '<extension key="org.example.sample"><version>1.0</version></extension>');
        file_put_contents($extension . '/schema/Thing.entityType.php', '<?php return [];');
        $library = $this->temporary . '/lib.php';
        file_put_contents($library, '<?php');
        $key = fn(string $day, string $size = 'demo'): string => $this->context($profile, $size, [$extension], [$library])
            ->key(Calendar::day($day))['key'];

        $base = $key('2027-01-02');
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $base);
        self::assertSame($base, $key('2027-12-30'), 'one key per year');
        self::assertNotSame($base, $key('2028-01-01'), 'next year');
        self::assertNotSame($base, $key('2027-01-02', 'bench'), 'size');
        file_put_contents($profile . '/scripts/10-data.php', '<?php echo 2;');
        $script = $key('2027-01-02');
        self::assertNotSame($base, $script, 'profile script');
        file_put_contents($extension . '/schema/Thing.entityType.php', '<?php return [1];');
        $schema = $key('2027-01-02');
        self::assertNotSame($script, $schema, 'extension schema');
        file_put_contents($extension . '/info.xml', '<extension key="org.example.sample"><version>1.1</version></extension>');
        $version = $key('2027-01-02');
        self::assertNotSame($schema, $version, 'extension version');
        file_put_contents($extension . '/info.xml', '<extension key="org.example.sample"><version>1.1</version><requires><ext>org.example.base</ext></requires></extension>');
        $requires = $key('2027-01-02');
        self::assertNotSame($version, $requires, 'extension requires');
        file_put_contents($extension . '/civikitchen.yaml', "extension_source: []\n");
        $pins = $key('2027-01-02');
        self::assertNotSame($requires, $pins, 'dependency pins');
        file_put_contents($library, '<?php // changed');
        self::assertNotSame($pins, $key('2027-01-02'), 'shared helper');
        $this->setEnvironment('CIVIKITCHEN_DEFAULT_LOCALE', 'de_DE');
        self::assertNotSame($key('2027-01-02'), $this->context($profile, 'demo', [$extension], [])->key(Calendar::day('2027-01-02'))['key']);
    }

    public function testContextRefusesAnUndeclaredSize(): void
    {
        $profile = $this->snapshotProfile('sample-data', []);
        $this->expectExceptionMessage("no snapshot profile of sample-data has the size 'bench' (sizes: demo)");
        $this->context($profile, 'bench', [], []);
    }

    public function testContextRoundTripsThroughTheState(): void
    {
        $profile = $this->snapshotProfile('sample-data', ['calendar' => 'month']);
        $context = $this->context($profile, 'demo', [], []);
        $again = Context::fromState($context->toArray(), []);
        self::assertSame($context->key(Calendar::day('2027-05-05')), $again->key(Calendar::day('2027-05-05')));
        self::assertSame('month', $again->calendar()->unit);
        self::assertSame('sample-data', $again->name());
    }

    public function testStoreCommitsFindsAndPrunes(): void
    {
        $store = new Store($this->temporary . '/store');
        foreach (['aaaa', 'bbbb', 'cccc'] as $i => $key) {
            $partial = $store->prepare('sample-data', 'demo', $key);
            file_put_contents($partial, 'dump');
            self::assertNull($store->find('sample-data', 'demo', $key), 'a dump without metadata is incomplete');
            $store->commit($partial, $this->meta($key, "2027-01-0{$i}T00:00:00Z"));
            self::assertSame(4, $store->find('sample-data', 'demo', $key)['bytes'] ?? null);
        }
        // Two stacks generating one key write apart; the last commit publishes a whole dump.
        [$first, $second] = [$store->prepare('sample-data', 'demo', 'aaaa'), $store->prepare('sample-data', 'demo', 'aaaa')];
        self::assertNotSame($first, $second);
        file_put_contents($first, 'first');
        file_put_contents($second, 'second dump');
        $store->commit($first, $this->meta('aaaa', '2027-01-00T00:00:00Z'));
        $store->commit($second, $this->meta('aaaa', '2027-01-00T00:00:00Z'));
        self::assertSame('second dump', file_get_contents($store->dump('sample-data', 'demo', 'aaaa')));
        self::assertSame(11, $store->find('sample-data', 'demo', 'aaaa')['bytes'] ?? null);
        self::assertSame([], glob($this->temporary . '/store/*.partial'));
        $partial = $store->prepare('other-data', 'demo', 'dddd');
        file_put_contents($partial, 'dump');
        $store->commit($partial, ['profile' => 'other-data'] + $this->meta('dddd', '2026-01-01T00:00:00Z'));
        self::assertSame(['cccc', 'bbbb', 'aaaa', 'dddd'], array_column($store->all(), 'key'));
        self::assertSame([$store->dump('sample-data', 'demo', 'aaaa')], $store->prune(2));
        self::assertFileDoesNotExist($store->dump('sample-data', 'demo', 'aaaa'));
        self::assertTrue($store->remove('sample-data', 'demo', 'bbbb'));
        self::assertFalse($store->remove('sample-data', 'demo', 'bbbb'));
        self::assertSame(['cccc', 'dddd'], array_column($store->all(), 'key'));
        file_put_contents($this->temporary . '/store/broken.sql.zst.json', '{"profile":"x"}');
        file_put_contents($this->temporary . '/store/broken.sql.zst', '');
        $this->expectExceptionMessage('is not snapshot metadata');
        $store->all();
    }

    public function testStoreNamesAnUnwritableDirectory(): void
    {
        mkdir($this->temporary . '/locked', 0500);
        $store = new Store($this->temporary . '/locked');
        try {
            $this->expectExceptionMessage('is not writable');
            $store->prepare('sample-data', 'demo', 'aaaa');
        } finally {
            chmod($this->temporary . '/locked', 0700);
        }
    }

    public function testRunnerPipeFailsWhenEitherSideFails(): void
    {
        $runner = new Runner();
        $output = $this->temporary . '/piped';
        self::assertSame(0, $runner->pipe(['printf', 'abc'], ['tr', 'a-c', 'A-C'], null, $output));
        self::assertSame('ABC', file_get_contents($output));
        self::assertSame(3, $runner->pipe(['sh', '-c', 'printf x; exit 3'], ['cat'], null, $output));
        self::assertSame(4, $runner->pipe(['printf', 'x'], ['sh', '-c', 'cat >/dev/null; exit 4'], null, $output));
        self::assertSame(2, $runner->pipe(['ck-no-such-tool'], ['cat'], null, $output));
        self::assertTrue($runner->available('sh'));
        self::assertFalse($runner->available('ck-no-such-tool'));
        self::assertFalse($runner->available(''));
    }

    public function testProfileSnapshotDefaultsAndScripts(): void
    {
        $data = new ProfileData();
        $profile = $this->snapshotProfile('sample-data', []);
        self::assertSame(['calendar' => 'year', 'sizes' => ['demo'], 'after_restore' => []], $data->snapshot($profile . '/profile.json'));
        file_put_contents($profile . '/scripts/90-after.php', '<?php');
        $this->writeProfile($profile, ['calendar' => 'none', 'after_restore' => ['scripts/90-after.php']]);
        self::assertSame(['calendar' => 'none', 'sizes' => ['demo'], 'after_restore' => [realpath($profile . '/scripts/90-after.php')]],
            $data->snapshot($profile . '/profile.json'));
        $plain = $this->temporary . '/plain';
        mkdir($plain);
        file_put_contents($plain . '/profile.json', '{"description":"plain","dependencies":[]}');
        self::assertNull($data->snapshot($plain . '/profile.json'));
        $this->writeProfile($profile, ['after_restore' => ['scripts/missing.php']]);
        self::assertNotSame([], $data->problems($profile . '/profile.json'));
        $this->expectExceptionMessage('is no file inside the profile directory');
        $data->snapshot($profile . '/profile.json');
    }

    public function testInternalCommandTellsSnapshotProfiles(): void
    {
        $application = new Application(dirname(__DIR__, 2) . '/toolbelt/bin', dirname(__DIR__, 2));
        $profile = $this->snapshotProfile('sample-data', []);
        self::assertSame(0, $application->run(['internal', 'profile-has-snapshot', $profile . '/profile.json'], 'ck'));
        $plain = $this->temporary . '/plain.json';
        file_put_contents($plain, '{"description":"plain","dependencies":[]}');
        self::assertSame(1, $application->run(['internal', 'profile-has-snapshot', $plain], 'ck'));
    }

    public function testSeedCommandHelpAndToday(): void
    {
        $application = new Application(dirname(__DIR__, 2) . '/toolbelt/bin', dirname(__DIR__, 2));
        ob_start();
        self::assertSame(0, $application->run(['seed', '--help'], 'ck'));
        self::assertStringContainsString('ck seed restore', (string) ob_get_clean());
        $this->setEnvironment('CK_SEED_TODAY', '2027-08-09');
        [$status, $output] = $this->seed(['today']);
        self::assertSame([0, "2027-08-09\n"], [$status, $output]);
        self::assertSame(2, $this->seed(['unknown'])[0]);
        self::assertSame(1, $this->seed(['prune', '--keep=0'])[0]);
        self::assertSame(1, $this->seed(['save'])[0]);
        self::assertSame(1, $this->seed(['rebuild'])[0], 'nothing to rebuild on a site without a snapshot');
        self::assertSame(1, $this->seed(['restore'])[0], 'restore without a profile needs a seeded site');
    }

    public function testRestoreAsksForAGenerationWhenNothingFits(): void
    {
        $profile = $this->snapshotProfile('sample-data', []);
        $store = new Store($this->temporary . '/store');
        $this->setEnvironment('CK_SEED_TODAY', '2027-04-01');

        [$status, $output] = $this->seed(['restore', $profile], $store, '6.19.1');
        self::assertSame(SeedCommand::MISS, $status);
        self::assertStringContainsString('no snapshot for sample-data', $output);

        $key = $this->context($profile, 'demo', [], $this->libraries())->key(Calendar::day('2027-04-01'))['key'];
        $partial = $store->prepare('sample-data', 'demo', $key);
        file_put_contents($partial, 'dump');
        $store->commit($partial, ['key' => $key, 'generated_on' => '2027-04-02', 'core_version' => '6.19.1'] + $this->meta($key, ''));
        [$status, $output] = $this->seed(['restore', $profile], $store, '6.19.1');
        self::assertSame([SeedCommand::MISS, true], [$status, str_contains($output, 'is valid until 2027-12-31, not on 2027-04-01')]);

        $store->commit($this->rewrite($store, $key), ['key' => $key, 'generated_on' => '2027-03-01', 'core_version' => '6.20.0'] + $this->meta($key, ''));
        [$status, $output] = $this->seed(['restore', $profile], $store, '6.19.1');
        self::assertSame(SeedCommand::MISS, $status);
        self::assertStringContainsString('newer than this site\'s 6.19.1', $output);

        [$status] = $this->seed(['restore', $profile], $store, '');
        self::assertSame(1, $status, 'no site to restore over');
    }

    public function testTheSelectedSetHasOneSnapshot(): void
    {
        $base = $this->plainProfile('base');
        $first = $this->snapshotProfile('first-data', ['calendar' => 'none', 'after_restore' => ['scripts/10-data.php']]);
        $middle = $this->plainProfile('middle');
        $last = $this->snapshotProfile('last-data', ['calendar' => 'year', 'sizes' => ['demo', 'bench']]);
        $after = $this->plainProfile('after');
        $this->setEnvironment('CK_SEED_PROFILES', implode("\n", [$base, $first, $middle, $last, $after]));
        $this->setEnvironment('CK_SEED_TODAY', '2027-04-01');
        $this->setEnvironment('CK_SEED_SIZE', 'bench');
        $context = Context::fromEnvironment($middle, $this->libraries());
        self::assertSame(['first-data+last-data', $first, $last, 'year', [$first . '/scripts/10-data.php']],
            [$context->name(), $context->first(), $context->last(), $context->calendar()->unit, $context->afterRestore()]);
        self::assertSame([true, true, true, false], array_map($context->covers(...), [$first, $middle, $last, $after]));

        $store = new Store($this->temporary . '/store');
        self::assertSame(SeedCommand::MISS, $this->seed(['restore', $base], $store)[0], 'a profile before the set applies');
        [$status, $output] = $this->seed(['restore', $first], $store);
        self::assertSame(SeedCommand::MISS, $status);
        self::assertStringContainsString('no snapshot for first-data+last-data (bench', $output);
        self::assertSame([SeedCommand::MISS, ''], $this->seed(['restore', $middle], $store), 'a generating set applies every profile');
        self::assertSame([0, ''], $this->seed(['save', $first], $store), 'the dump waits for the last snapshot profile');
        self::assertSame([0, ''], $this->seed(['status'], $store), 'a set still generating reports nothing');
        self::assertSame([], $store->all());

        $state = json_decode((string) file_get_contents($this->temporary . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
        file_put_contents($this->temporary . '/state.json', json_encode(['mode' => 'restored'] + $state, JSON_THROW_ON_ERROR));
        self::assertSame([0, ''], $this->seed(['restore', $middle], $store), 'a restored set holds its middle profile');
        self::assertSame([0, ''], $this->seed(['restore', $last], $store));
        self::assertSame(SeedCommand::MISS, $this->seed(['restore', $after], $store)[0], 'a profile after the set applies');
        self::assertSame(0, $this->seed(['save', $last], $store)[0]);
        self::assertSame([], $store->all(), 'a restored set is not dumped again');

        $this->setEnvironment('CK_SEED_PROFILES', implode("\n", [$base, $after]));
        self::assertSame([SeedCommand::MISS, ''], $this->seed(['restore', $base], $store), 'a set without snapshots applies');
        self::assertSame([0, ''], $this->seed(['save', $after], $store));
        $this->expectException(RuntimeException::class);
        Context::fromEnvironment($first, $this->libraries());
    }

    public function testStatusWithoutStateSaysSo(): void
    {
        $this->setEnvironment('CK_SEED_STATE', $this->temporary . '/state.json');
        self::assertSame([0, ''], $this->seed(['status']));
        file_put_contents($this->temporary . '/state.json', json_encode([
            'profiles' => [$this->snapshotProfile('sample-data', [])], 'extensions' => [], 'size' => 'demo',
            'profile_name' => 'sample-data', 'key' => 'abcdabcdabcdabcd', 'mode' => 'restored', 'restored_on' => '2027-04-02',
            'shift_days' => 3, 'generated_on' => '2027-03-30', 'core_version' => '6.19.1', 'valid_until' => '2027-12-31',
        ], JSON_THROW_ON_ERROR));
        self::assertSame([0, ''], $this->seed(['status', '--generated']), 'a restored set stores nothing');
        [$status, $output] = $this->seed(['status']);
        self::assertSame(0, $status);
        self::assertStringContainsString('restored on 2027-04-02, shifted 3 days', $output);
        self::assertStringContainsString('profiles      sample-data', $output);
        self::assertStringContainsString('current key', $output);
        [$status, $output] = $this->seed(['rebuild'], new Store($this->temporary . '/store'));
        self::assertSame(0, $status);
        self::assertStringContainsString('no stored snapshot sample-data-demo-abcdabcdabcdabcd', $output);
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string}
     */
    private function seed(array $arguments, ?Store $store = null, string $coreVersion = ''): array
    {
        if (!isset($this->environment['CK_SEED_STATE'])) {
            $this->setEnvironment('CK_SEED_STATE', $this->temporary . '/state.json');
        }
        $command = new SeedCommand(dirname(__DIR__, 2), new Runner(), new FakeSiteDatabase($coreVersion),
            $store ?? new Store($this->temporary . '/store'));
        ob_start();
        $status = $command->run($arguments);
        return [$status, (string) ob_get_clean()];
    }

    private function rewrite(Store $store, string $key): string
    {
        $partial = $store->prepare('sample-data', 'demo', $key);
        file_put_contents($partial, 'dump');
        return $partial;
    }

    /** @return list<string> */
    private function libraries(): array
    {
        $root = dirname(__DIR__, 2);
        return [$root . '/docker/profiles/lib.php', $root . '/docker/profiles/seed-lib.php', $root . '/toolbelt/lib/php/src/Seed/Calendar.php'];
    }

    /** @return array<string, mixed> */
    private function meta(string $key, string $createdAt): array
    {
        return ['profile' => 'sample-data', 'size' => 'demo', 'key' => $key, 'generated_on' => '2027-01-02',
            'core_version' => '6.19.1', 'created_at' => $createdAt];
    }

    /**
     * @param list<string> $extensions
     * @param list<string> $libraries
     */
    private function context(string $profile, string $size, array $extensions, array $libraries): Context
    {
        $this->setEnvironment('CK_SEED_PROFILES', $profile);
        $this->setEnvironment('CK_SEED_EXTENSION_DIRS', implode("\n", $extensions));
        $this->setEnvironment('CK_SEED_SIZE', $size);
        return Context::fromEnvironment($profile, $libraries);
    }

    private function plainProfile(string $name): string
    {
        mkdir($this->temporary . '/' . $name, 0700);
        file_put_contents($this->temporary . '/' . $name . '/profile.json', '{"description": "fixture", "dependencies": []}');
        return (string) realpath($this->temporary . '/' . $name);
    }

    /** @param array<string, mixed> $snapshot */
    private function snapshotProfile(string $name, array $snapshot): string
    {
        $directory = $this->temporary . '/' . $name;
        if (!is_dir($directory . '/scripts')) {
            mkdir($directory . '/scripts', 0700, true);
            file_put_contents($directory . '/scripts/10-data.php', '<?php echo 1;');
        }
        $this->writeProfile($directory, $snapshot);
        return (string) realpath($directory);
    }

    /** @param array<string, mixed> $snapshot */
    private function writeProfile(string $directory, array $snapshot): void
    {
        file_put_contents($directory . '/profile.json', json_encode([
            'description' => 'fixture', 'dependencies' => [], 'snapshot' => (object) $snapshot, 'scripts' => ['scripts/10-data.php'],
        ], JSON_THROW_ON_ERROR));
    }

    private function setEnvironment(string $name, string $value): void
    {
        if (!array_key_exists($name, $this->environment)) {
            $this->environment[$name] = getenv($name);
        }
        putenv("{$name}={$value}");
    }
}

/** A site database that only reports its core version. */
final class FakeSiteDatabase extends Database
{
    public function __construct(private readonly string $version)
    {
        parent::__construct(['host' => 'db', 'port' => '3306', 'name' => 'site', 'user' => 'u', 'password' => 'p']);
    }

    public function coreVersion(): string
    {
        return $this->version;
    }
}
