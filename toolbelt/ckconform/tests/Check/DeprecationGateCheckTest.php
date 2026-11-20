<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\DeprecationGateCheck;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class DeprecationGateCheckTest extends CheckTestCase
{
    private const GOOD_CONFIG = '<?xml version="1.0"?>'
        . '<phpunit convertDeprecationsToExceptions="true" bootstrap="tests/phpunit/bootstrap.php"/>';

    private const GOOD_BOOTSTRAP = "<?php\nerror_reporting(E_ALL);\n";

    public function testSaysNothingWithoutATestsPhpunitDirectory(): void
    {
        $this->assertSilent($this->run_(new DeprecationGateCheck(), $this->repo([])));
    }

    /** PhpunitConfigCheck owns the missing-config finding; two voices on one file is noise. */
    public function testSaysNothingWithoutAPhpunitConfig(): void
    {
        $context = $this->repo(['tests/phpunit/bootstrap.php' => self::GOOD_BOOTSTRAP]);
        $this->assertSilent($this->run_(new DeprecationGateCheck(), $context));
    }

    public function testPassesWithBothIngredients(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => self::GOOD_CONFIG,
            'tests/phpunit/bootstrap.php' => self::GOOD_BOOTSTRAP,
        ]);
        $this->assertSilent($this->run_(new DeprecationGateCheck(), $context));
    }

    public function testWarnsWhenTheAttributeIsMissing(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit bootstrap="tests/phpunit/bootstrap.php"/>',
            'tests/phpunit/bootstrap.php' => self::GOOD_BOOTSTRAP,
        ]);
        $this->assertWarns(
            $this->run_(new DeprecationGateCheck(), $context),
            'does not set convertDeprecationsToExceptions="true"',
        );
    }

    public function testWarnsWhenTheAttributeIsFalse(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit convertDeprecationsToExceptions="false"/>',
            'tests/phpunit/bootstrap.php' => self::GOOD_BOOTSTRAP,
        ]);
        $this->assertWarns($this->run_(new DeprecationGateCheck(), $context), 'convertDeprecationsToExceptions');
    }

    /**
     * A commented-out attribute is not an attribute.
     */
    public function testACommentedAttributeDoesNotCount(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => "<?xml version=\"1.0\"?>\n"
                . "<!-- convertDeprecationsToExceptions=\"true\" -->\n<phpunit/>",
            'tests/phpunit/bootstrap.php' => self::GOOD_BOOTSTRAP,
        ]);
        $this->assertWarns($this->run_(new DeprecationGateCheck(), $context), 'convertDeprecationsToExceptions');
    }

    /**
     * The attribute alone is not the gate: PHPUnit skips errors outside
     * error_reporting(), and the CLI default masks E_DEPRECATED.
     */
    public function testWarnsWhenTheBootstrapDoesNotWidenErrorReporting(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => self::GOOD_CONFIG,
            'tests/phpunit/bootstrap.php' => "<?php\nrequire 'autoload.php';\n",
        ]);
        $this->assertWarns($this->run_(new DeprecationGateCheck(), $context), 'error_reporting(E_ALL)');
    }

    public function testAnErrorReportingIniInTheConfigAlsoCounts(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit convertDeprecationsToExceptions="true">'
                . '<php><ini name="error_reporting" value="-1"/></php></phpunit>',
            'tests/phpunit/bootstrap.php' => "<?php\nrequire 'autoload.php';\n",
        ]);
        $this->assertSilent($this->run_(new DeprecationGateCheck(), $context));
    }

    /** E_ALL minus notices still carries E_DEPRECATED — that is the ingredient. */
    public function testEAllMinusNoticesStillCounts(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => self::GOOD_CONFIG,
            'tests/phpunit/bootstrap.php' => "<?php\nerror_reporting(E_ALL & ~E_NOTICE);\n",
        ]);
        $this->assertSilent($this->run_(new DeprecationGateCheck(), $context));
    }

    public function testBothIngredientsCanBeMissingAtOnce(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit/>',
            'tests/phpunit/bootstrap.php' => "<?php\nrequire 'autoload.php';\n",
        ]);
        self::assertCount(2, $this->run_(new DeprecationGateCheck(), $context)->messages('warn'));
    }

    /** An untracked local tests/phpunit is not a suite the repo ships. */
    public function testAnUntrackedSuiteDirectoryIsIgnored(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit/>',
        ], git: true);
        mkdir($context->root . '/tests/phpunit', 0777, true);
        file_put_contents($context->root . '/tests/phpunit/bootstrap.php', '<?php');
        $this->assertSilent($this->run_(new DeprecationGateCheck(), $context));
    }

    /** phpunit.xml is checked too — a repo may keep the non-dist form. */
    public function testThePlainPhpunitXmlIsChecked(): void
    {
        $context = $this->repo([
            'phpunit.xml' => '<?xml version="1.0"?><phpunit/>',
            'tests/phpunit/bootstrap.php' => self::GOOD_BOOTSTRAP,
        ]);
        $this->assertWarns($this->run_(new DeprecationGateCheck(), $context), 'phpunit.xml does not set');
    }

    public function testFullyQualifiedNamesCount(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => self::GOOD_CONFIG,
            'tests/phpunit/bootstrap.php' => "<?php\n\\error_reporting(\\E_ALL);\n",
        ]);
        $this->assertSilent($this->run_(new DeprecationGateCheck(), $context));
    }

    /** @return iterable<string, array{string}> */
    public static function healthyMasks(): iterable
    {
        yield 'minus one' => ['-1'];
        yield 'named argument' => ['error_level: E_ALL'];
        yield 'xor a notice' => ['E_ALL ^ E_NOTICE'];
        yield 'parenthesised' => ['(E_ALL | E_STRICT) & ~(E_NOTICE)'];
        yield 'explicit deprecations' => ['E_DEPRECATED | E_USER_DEPRECATED | E_ERROR'];
    }

    /** @dataProvider healthyMasks */
    public function testAMaskKeepingDeprecationsCounts(string $mask): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => self::GOOD_CONFIG,
            'tests/phpunit/bootstrap.php' => "<?php\nerror_reporting($mask);\n",
        ]);
        $this->assertSilent($this->run_(new DeprecationGateCheck(), $context));
    }

    /** @return iterable<string, array{string}> */
    public static function maskingBootstraps(): iterable
    {
        yield 'commented out' => ["// error_reporting(E_ALL);\n"];
        yield 'deprecations masked' => ["error_reporting(E_ALL & ~E_DEPRECATED);\n"];
        yield 'a lowercase constant, undefined in PHP 8' => ["error_reporting(e_all);\n"];
        yield 'digit separators keep their value' => ["error_reporting(32_767 & ~E_DEPRECATED);\n"];
        yield 'user deprecations xored' => ["error_reporting(E_ALL ^ E_USER_DEPRECATED);\n"];
        yield 'a read, not a write' => ["\$level = error_reporting();\n"];
        yield 'a non-constant mask' => ["error_reporting(\$level);\n"];
        yield 'a method of the same name' => ["\$ini->error_reporting(E_ALL);\n"];
    }

    /** @dataProvider maskingBootstraps */
    public function testAMaskWithoutDeprecationsWarns(string $code): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => self::GOOD_CONFIG,
            'tests/phpunit/bootstrap.php' => "<?php\n" . $code,
        ]);
        $this->assertWarns($this->run_(new DeprecationGateCheck(), $context), 'no error_reporting(E_ALL)');
    }

    /** @return iterable<string, array{string, bool}> */
    public static function iniValues(): iterable
    {
        yield 'minus one' => ['-1', true];
        yield 'a constant name PHPUnit resolves' => ['E_ALL', true];
        yield 'an integer keeping deprecations' => ['32767', true];
        yield 'zero' => ['0', false];
        yield 'an expression ini_set reads as 0' => ['E_ALL &amp; ~E_DEPRECATED', false];
    }

    /** @dataProvider iniValues */
    public function testTheIniValueIsEvaluated(string $value, bool $widens): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit convertDeprecationsToExceptions="true">'
                . '<php><ini name="error_reporting" value="' . $value . '"/></php></phpunit>',
            'tests/phpunit/bootstrap.php' => "<?php\nrequire 'autoload.php';\n",
        ]);
        $reporter = $this->run_(new DeprecationGateCheck(), $context);
        $widens ? $this->assertSilent($reporter) : $this->assertWarns($reporter, 'error_reporting');
    }

    /** @return iterable<string, array{string, bool}> */
    public static function iniSetCalls(): iterable
    {
        yield 'cast constant' => ["ini_set('error_reporting', (string) E_ALL);\n", true];
        yield 'numeric string' => ["ini_set('error_reporting', '-1');\n", true];
        yield 'an integer constant' => ["ini_set('error_reporting', E_ALL);\n", true];
        yield 'every bit set' => ["error_reporting(PHP_INT_MAX);\n", true];
        yield 'digit separators' => ["error_reporting(32_767);\n", true];
        yield 'named value' => ["ini_set(option: 'error_reporting', value: (string) E_ALL);\n", true];
        yield 'a constant name as a string' => ["ini_set('error_reporting', 'E_ALL');\n", false];
        yield 'another option' => ["ini_set('display_errors', (string) E_ALL);\n", false];
        yield 'deprecations masked' => ["ini_set('error_reporting', (string) (E_ALL & ~E_DEPRECATED));\n", false];
    }

    /** @dataProvider iniSetCalls */
    public function testIniSetInTheBootstrapIsEvaluated(string $code, bool $widens): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => self::GOOD_CONFIG,
            'tests/phpunit/bootstrap.php' => "<?php\n" . $code,
        ]);
        $reporter = $this->run_(new DeprecationGateCheck(), $context);
        $widens ? $this->assertSilent($reporter) : $this->assertWarns($reporter, 'no error_reporting(E_ALL)');
    }

    /** The image's bootstrap widens error_reporting; it is read from this toolbelt. */
    public function testTheTemplatesImageBootstrapPasses(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => (string) file_get_contents(dirname(__DIR__, 4) . '/scaffold/template/extension/phpunit.xml.dist'),
            'tests/phpunit/SomeTest.php' => '<?php',
        ]);
        $this->assertSilent($this->run_(new DeprecationGateCheck(), $context));
    }
}
