<?php

declare(strict_types=1);

use CiviKitchen\Toolbelt\Cli\ProfileCommand;
use CiviKitchen\Toolbelt\Filesystem\Directory;
use PHPUnit\Framework\TestCase;

final class ProfileTest extends TestCase
{
    private string $temporary;

    private string|false $profilePath;

    protected function setUp(): void
    {
        $this->profilePath = getenv('CIVIKITCHEN_PROFILE_PATH');
        putenv('CIVIKITCHEN_PROFILE_PATH');
        $this->temporary = (string) realpath(Directory::temporary('civikitchen-profile'));
    }

    protected function tearDown(): void
    {
        putenv($this->profilePath === false ? 'CIVIKITCHEN_PROFILE_PATH' : "CIVIKITCHEN_PROFILE_PATH={$this->profilePath}");
        Directory::remove($this->temporary);
    }

    public function testAProfileDirectoryOrItsJsonValidates(): void
    {
        $directory = $this->profile('widget', ['description' => 'Widgets', 'dependencies' => []]);

        self::assertSame([0, "{$directory}/profile.json: valid civicrm profile\n"], $this->command(['validate', $directory]));
        self::assertSame(0, $this->command(['validate', "{$directory}/profile.json"])[0]);
    }

    /** The schema decides first; the files a valid profile names are checked after it. */
    public function testASchemaViolationOrAMissingScriptFails(): void
    {
        self::assertSame(1, $this->command(['validate', $this->profile('bare', ['dependencies' => []])])[0]);
        $scripted = $this->profile('scripted', ['description' => 'Widgets', 'dependencies' => [], 'scripts' => ['missing.php']]);
        self::assertSame(1, $this->command(['validate', $scripted])[0]);
    }

    public function testInputItCannotReadIsAnError(): void
    {
        $directory = "{$this->temporary}/broken";
        mkdir($directory);
        file_put_contents("{$directory}/profile.json", '{"description": ');

        self::assertSame(2, $this->command(['validate', $directory])[0]);
        self::assertSame(2, $this->command(['validate', "{$this->temporary}/absent"])[0]);
        self::assertSame(2, $this->command(['validate'])[0]);
        self::assertSame(2, $this->command(['sync'])[0]);
        self::assertSame(0, $this->command(['--help'])[0]);
    }

    /** A name found in an earlier root hides the same name further down. */
    public function testListSearchesTheRootsInOrderAndNamesEachProfileOnce(): void
    {
        $first = "{$this->temporary}/first";
        $second = "{$this->temporary}/second";
        $this->profile('first/beta', ['description' => 'x', 'dependencies' => []]);
        $this->profile('first/alpha', ['description' => 'x', 'dependencies' => []]);
        $this->profile('second/beta', ['description' => 'x', 'dependencies' => []]);
        $this->profile('second/events', ['description' => 'x', 'dependencies' => []]);
        putenv("CIVIKITCHEN_PROFILE_PATH={$second}" . PATH_SEPARATOR . "{$this->temporary}/absent");

        [$status, $output] = $this->command(['list', $first]);

        self::assertSame(0, $status);
        $lines = explode("\n", rtrim($output, "\n"));
        self::assertSame(["alpha\t{$first}/alpha", "beta\t{$first}/beta", "events\t{$second}/events"], array_slice($lines, 0, 3));
        self::assertNotContains("beta\t{$second}/beta", $lines);
        self::assertSame(1, count(preg_grep('/^events\t/', $lines) ?: []), 'the bundled events profile is hidden by the earlier one');
        self::assertContains("fundraising\t" . dirname(__DIR__, 2) . '/docker/profiles/fundraising', $lines);
    }

    /** @param array<string, mixed> $profile */
    private function profile(string $name, array $profile): string
    {
        $directory = "{$this->temporary}/{$name}";
        mkdir($directory, 0777, true);
        file_put_contents("{$directory}/profile.json", json_encode($profile));
        return $directory;
    }

    /** @param list<string> $arguments @return array{int, string} */
    private function command(array $arguments): array
    {
        ob_start();
        try {
            $status = (new ProfileCommand(dirname(__DIR__, 2)))->run($arguments);
        } finally {
            $output = (string) ob_get_clean();
        }
        return [$status, $output];
    }
}
