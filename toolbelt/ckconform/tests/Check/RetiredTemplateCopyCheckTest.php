<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\RetiredTemplateCopyCheck;
use CiviKitchen\Ckconform\ImageBootstraps;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class RetiredTemplateCopyCheckTest extends CheckTestCase
{
    private const OLD_PHPUNIT = '<?xml version="1.0"?>' . "\n"
        . '<phpunit bootstrap="tests/phpunit/bootstrap.php"><testsuites/></phpunit>' . "\n";

    private const OLD_PHPSTAN = "parameters:\n\texcludePaths:\n\t\tanalyseAndScan:\n\t\t\t- phpstanBootstrap.php\n"
        . "\tbootstrapFiles:\n\t\t- phpstanBootstrap.php\n";

    /** The last version each retired file had in the template. */
    private static function copy(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/fixtures/retired-template/' . $name);
    }

    private static function template(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4) . '/scaffold/template/extension/' . $name);
    }

    public function testTheTemplatesConfigsAreSilent(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => self::template('phpunit.xml.dist'),
            'phpstan.neon.dist' => self::template('phpstan.neon.dist'),
            'phpstan-tests.neon.dist' => self::template('phpstan-tests.neon.dist'),
            'tests/phpunit/Civi/WidgetTest.php' => '<?php',
        ], git: true);
        $this->assertSilent($this->run_(new RetiredTemplateCopyCheck(), $context));
    }

    public function testTheLastTemplateVersionsAreKnownCopies(): void
    {
        foreach (['phpstanBootstrap.php', 'tests/phpunit/bootstrap.php', 'tests/phpunit/ckHeadless.php'] as $path) {
            self::assertTrue(ImageBootstraps::isRetiredCopy($path, self::copy(basename($path))), $path);
        }
        self::assertFalse(ImageBootstraps::isRetiredCopy('tests/phpunit/bootstrap.php', self::copy('bootstrap.php') . "\n"));
    }

    public function testTheImageFilesExistInThisToolbelt(): void
    {
        foreach (ImageBootstraps::RETIRED as [$image]) {
            self::assertFileExists((string) ImageBootstraps::toolbeltFile($image));
        }
    }

    public function testAnUnmigratedRepositoryNamesEveryCopyAndConfigLine(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => self::OLD_PHPUNIT,
            'phpstan.neon.dist' => self::OLD_PHPSTAN,
            'phpstanBootstrap.php' => self::copy('phpstanBootstrap.php'),
            'tests/phpunit/bootstrap.php' => self::copy('bootstrap.php'),
            'tests/phpunit/ckHeadless.php' => self::copy('ckHeadless.php'),
        ], git: true);
        $reporter = $this->run_(new RetiredTemplateCopyCheck(), $context);
        self::assertSame([
            'phpstanBootstrap.php is a copy of the civikitchen bootstrap the image ships as '
            . '/opt/civikitchen/toolbelt/phpstan-config/bootstrap.php — delete it (ckinit --update does, once nothing names it)',
            'tests/phpunit/bootstrap.php is a copy of the civikitchen bootstrap the image ships as '
            . '/opt/civikitchen/toolbelt/phpunit/bootstrap.php — delete it (ckinit --update does, once nothing names it)',
            'tests/phpunit/ckHeadless.php is a copy of the civikitchen bootstrap the image ships as '
            . '/opt/civikitchen/toolbelt/phpunit/ckHeadless.php — delete it (ckinit --update does, once nothing names it)',
            'phpstan.neon.dist:4: names phpstanBootstrap.php, the retired template copy — list '
            . '/opt/civikitchen/toolbelt/phpstan-config/bootstrap.php under bootstrapFiles instead, and drop phpstanBootstrap.php from excludePaths where listed',
            'phpstan.neon.dist:6: names phpstanBootstrap.php, the retired template copy — list '
            . '/opt/civikitchen/toolbelt/phpstan-config/bootstrap.php under bootstrapFiles instead, and drop phpstanBootstrap.php from excludePaths where listed',
            'phpunit.xml.dist:2: bootstrap="tests/phpunit/bootstrap.php" names the retired template copy — set '
            . 'bootstrap="/opt/civikitchen/toolbelt/phpunit/bootstrap.php"; repository setup goes into tests/phpunit/bootstrap.local.php',
        ], $reporter->messages('FAIL'));
    }

    public function testACopyNamedFromAnIncludedPhpstanConfigIsNamed(): void
    {
        $context = $this->repo([
            'phpstan.neon.dist' => "includes:\n\t- phpstan/base.neon\n",
            'phpstan/base.neon' => "parameters:\n\tbootstrapFiles:\n\t\t- ../phpstanBootstrap.php\n",
            'phpstanBootstrap.php' => self::copy('phpstanBootstrap.php'),
        ], git: true);
        $this->assertFails(
            $this->run_(new RetiredTemplateCopyCheck(), $context),
            'phpstan/base.neon:3: names phpstanBootstrap.php, the retired template copy',
        );
    }

    public function testAPathToAnotherPhpstanBootstrapIsSilent(): void
    {
        $context = $this->repo([
            'phpstan.neon.dist' => "includes:\n\t- phpstan/base.neon\n",
            'phpstan/base.neon' => "parameters:\n\tbootstrapFiles:\n\t\t- phpstanBootstrap.php\n",
            'phpstan/phpstanBootstrap.php' => '<?php',
        ], git: true);
        $this->assertSilent($this->run_(new RetiredTemplateCopyCheck(), $context));
    }

    public function testAPhpunitXmlOverridesTheDistConfigLikeTheRunners(): void
    {
        $context = $this->repo([
            'phpunit.xml' => '<?xml version="1.0"?>' . "\n" . '<phpunit bootstrap="tests/own.php"/>' . "\n",
            'phpunit.xml.dist' => self::template('phpunit.xml.dist'),
            'tests/own.php' => '<?php // own',
        ], git: true);
        self::assertSame(['tests/own.php', '<?php // own'], ImageBootstraps::suiteBootstrap($context));
    }

    public function testABootstrapAtAnotherPathRequiringTheHeadlessCopyIsNamed(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => '<?xml version="1.0"?>' . "\n" . '<phpunit bootstrap="tests/bootstrap.php"/>' . "\n",
            'tests/bootstrap.php' => "<?php\nrequire __DIR__ . '/phpunit/ckHeadless.php';\n",
            'tests/phpunit/ckHeadless.php' => self::copy('ckHeadless.php'),
        ], git: true);
        $this->assertFails(
            $this->run_(new RetiredTemplateCopyCheck(), $context),
            'tests/bootstrap.php:2: requires the retired template copy tests/phpunit/ckHeadless.php',
        );
    }

    public function testAConfigNamingADeletedCopyFails(): void
    {
        $context = $this->repo(['phpunit.xml.dist' => str_replace('"tests/', '"./tests/', self::OLD_PHPUNIT)], git: true);
        $this->assertFails($this->run_(new RetiredTemplateCopyCheck(), $context), 'phpunit.xml.dist:2: bootstrap="tests/phpunit/bootstrap.php"');
    }

    public function testACopyLeftBehindAfterTheConfigsMovedFails(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => self::template('phpunit.xml.dist'),
            'tests/phpunit/ckHeadless.php' => self::copy('ckHeadless.php'),
        ], git: true);
        $reporter = $this->run_(new RetiredTemplateCopyCheck(), $context);
        self::assertCount(1, $reporter->messages('FAIL'));
        $this->assertFails($reporter, 'tests/phpunit/ckHeadless.php is a copy');
    }

    /** A repository's own bootstrap at the old path stays its own, and so does the config naming it. */
    public function testARepositoryOwnedBootstrapIsSilent(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => self::OLD_PHPUNIT,
            'phpstan.neon.dist' => self::OLD_PHPSTAN,
            'phpstanBootstrap.php' => "<?php\nrequire '/var/www/html/core/vendor/autoload.php';\n",
            'tests/phpunit/bootstrap.php' => "<?php\nif (!getenv('TEST_DB_DSN')) { exit(1); }\n",
        ], git: true);
        $this->assertSilent($this->run_(new RetiredTemplateCopyCheck(), $context));
    }

    public function testCommentsAndOtherFilesNamedLikeTheCopyAreSilent(): void
    {
        $context = $this->repo([
            'phpstan.neon.dist' => "parameters:\n\t# was: - phpstanBootstrap.php\n\tbootstrapFiles:\n"
                . "\t\t- tools/phpstanBootstrap.php\n\t\t- /opt/civikitchen/toolbelt/phpstan-config/bootstrap.php\n",
            'tools/phpstanBootstrap.php' => '<?php',
            'tests/phpunit.xml' => self::OLD_PHPUNIT,
        ], git: true);
        $this->assertSilent($this->run_(new RetiredTemplateCopyCheck(), $context));
    }

    public function testUntrackedCopiesAreNotTheRepositorys(): void
    {
        $context = $this->repo(['README.md' => "x\n"], git: true);
        file_put_contents($context->path('phpstanBootstrap.php'), self::copy('phpstanBootstrap.php'));
        $this->assertSilent($this->run_(new RetiredTemplateCopyCheck(), $context));
    }

    /** A repository's own bootstrap that requires the ckHeadless.php copy keeps it alive until it requires the image's. */
    public function testAnOwnBootstrapRequiringTheHeadlessCopyIsNamed(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => self::OLD_PHPUNIT,
            'tests/phpunit/bootstrap.php' => "<?php\n// loads ckHeadless.php\nrequire __DIR__ . '/ckHeadless.php';\n",
            'tests/phpunit/ckHeadless.php' => self::copy('ckHeadless.php'),
        ], git: true);
        $reporter = $this->run_(new RetiredTemplateCopyCheck(), $context);
        self::assertSame([
            'tests/phpunit/ckHeadless.php is a copy of the civikitchen bootstrap the image ships as '
            . '/opt/civikitchen/toolbelt/phpunit/ckHeadless.php — delete it (ckinit --update does, once nothing names it)',
            'tests/phpunit/bootstrap.php:3: requires the retired template copy tests/phpunit/ckHeadless.php — '
            . 'require /opt/civikitchen/toolbelt/phpunit/ckHeadless.php instead',
        ], $reporter->messages('FAIL'));
    }

    public function testAnOwnBootstrapRequiringTheImagesHeadlessIsSilent(): void
    {
        $context = $this->repo([
            'phpunit.xml.dist' => self::OLD_PHPUNIT,
            'tests/phpunit/bootstrap.php' => "<?php\nrequire '/opt/civikitchen/toolbelt/phpunit/ckHeadless.php';\n",
        ], git: true);
        $this->assertSilent($this->run_(new RetiredTemplateCopyCheck(), $context));
    }
}
