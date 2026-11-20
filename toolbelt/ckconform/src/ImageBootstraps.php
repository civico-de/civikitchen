<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/**
 * The PHPUnit and phpstan bootstraps the image ships under /opt/civikitchen,
 * and the copies of them the extension template once managed. ckconform and
 * ckinit share it: a copy is told apart from a repository's own bootstrap by
 * the sha256 of every version the template shipped.
 */
final class ImageBootstraps
{
    public const PHPUNIT = '/opt/civikitchen/toolbelt/phpunit/bootstrap.php';

    public const PHPSTAN = '/opt/civikitchen/toolbelt/phpstan-config/bootstrap.php';

    public const HEADLESS = '/opt/civikitchen/toolbelt/phpunit/ckHeadless.php';

    /** Files that may require the ckHeadless.php copy, besides the bootstrap a phpunit config names. */
    private const HEADLESS_LOADERS = ['tests/phpunit/bootstrap.php', 'tests/phpunit/bootstrap.local.php'];

    /** The retired path a phpstan config names. */
    public const PHPSTAN_COPY = 'phpstanBootstrap.php';

    /**
     * Retired template path => the image file that replaces it and the sha256 of
     * every version the template shipped (git log --follow of each file).
     *
     * @var array<string, array{string, list<string>}>
     */
    public const RETIRED = [
        'phpstanBootstrap.php' => [self::PHPSTAN, [
            '2ee5a57fe0e0587a2cb01a6be0a1c4368c5c89c56c3051236b6d5576eaddcbd9',
            '3e525e09ed5adc7cc2a69aea5266ae544109061b4699dd1d1f2651fc2d0e28c1',
            '5dc78ab56add0efeac0a65193fdffc0b1b82ddf32ae0d419afcdf4e47dc8c929',
            '68d7a1195e88d2796e76392f38fe1625fc4feb5106eef09f1c77cbebdcbb215d',
            '6bfca325ed32c215b9ced0260f225ad680c0ee4407a1bc0a1807d7937e696993',
            '6ea6c69eb552de46f811388d9685a3ceb713cf61080747fc994489f54655ca53',
            '80a0f1d38318f390b3b77868e329ba39d5bea740e91d68766eba1b9951f84655',
            'b01eec1a56c098f09d29b2a3fee608651971a42bb51420e72e80fbafbd92f1e9',
            'd6c04cc3d86ca25d2cfd98465968d87a7aa483d6e1fbc627e59a6c349de850ab',
            'f161035201e872d2dbd072e7d436a0e522258484cc2ad48b66bf4166bd2734aa',
            'f31ecbb9e917a56ccad7450913e061f99e923d8e14c64a320e833879974c5533',
            'fc840c99a4c0a425980682d39c6a27c9ab06bb78b21844cbf4c0f99359ad9a16',
        ]],
        'tests/phpunit/bootstrap.php' => [self::PHPUNIT, [
            '3c13c5d5a96d04127f6a5e1eab09fdf8db385a04b2f95225fb3cbe2fa20a7468',
            '484366040dea147c3f244251cc6376854b1fe00bd71de293680b5cc43be4ddde',
            '50226a75d8ce88765dfa8b1897584babe5934c77150f64cdaf59ccfb7267278e',
            '52abe71cdd09eedec820f1d3e42a7ea43c0557f27e0d2a631e9321e63add1875',
            '572282f3ec8bac141f5abb85c1077171d2418f70f5b305bd8fc142af9b1b52f1',
            '682fe0982551053bd9d2ee21f60422f3028837d40c6351aa383fe65df288e6c7',
            '855b9df8e678d769327509c71fd9a6eee22bafcb77ccde377689c345bfb3256b',
            'adfff30bd6af6c444faccf5e8fb2eaf891484b0bc8711b445f746ef9e51715e1',
            'b3815056ae9dae50071804e21953ace3d2723fcc870b7edd523536a00b231d3d',
            'e1e7a6f59be550eb5b91120621658e003f8fe841c1c80b9a799192821714e9e8',
        ]],
        'tests/phpunit/ckHeadless.php' => [self::HEADLESS, [
            '18e8451293ceca64efeeafa27955ddc9687215debd411088339b329bf4177126',
            '1c4fea41c861c7d10dd5e133ed7a313a41a35511006e61c920c330e902d0f4ae',
            '2ac840520970d937b558e7c8b542510a37e62420907f98d3de47f41c21066933',
            '3cdccea34e273bebfd38ffce637e054ae1aec0fe064d67cf3719d40ec97ffd9a',
            '413fc0642b16b81cf1ee864e19cd032d25a504dcd9f1f6216dcdab629c1c98d6',
            '42536f669075c9d8b70714b516792db1e28a37ae44ca27e472210793b92859a0',
            '46894975e799bbb42207182baeabf4214b6208ad6797af8f6641aca30a010337',
            '4a768f7b4df0b3907c5efda3e44af4665b4c19d7abc719509add218fc52694d0',
            '6705b3a3271765f1921e1a14786ab85287af275bc93f6465e0db3b63c7e3a9a9',
            '67443af4614f9662e0a39a9e5a3178060028789ce539ee3ba73754155d549fb6',
            '6dd20a25b3e9b64b4602786a42d9d27098f212b516afc3eb7f46f675205bb600',
            '9eba1c8ad6527068886153b98610b24bbad5b02f104d483e6d3c269e6281c668',
            'a303a8ef648ee702f4dc18850c84d53d99c2497accb413b226eaa593adc84ebb',
            'ad2b03f7be800241c20589eeac7378c906c05badc7f5a83204794e7d71256e51',
            'b22a2664459d9b225ff14eb5ac178a094026e903941937037d903be15bcca49f',
            'b97a1051ed2507b6ce43f34976b34dbb73dfb32c8d9efc167271b70263071a3e',
            'bd7369d7daff7eb1145397a609285a56effd67610801f475b2c04ec036efe707',
            'c045b85d69cfb2e3b3fb3340ab18226d4def97ede6542fd463f7252cd18be986',
            'c1265d6e08916cb2ff6ea0ff3c99d9e1924bb2ce5ba4e09efed3c7686118aeef',
            'f99a3275288ba33a699f8073620e992549e6ddc956f7a6675555a23c76fa6323',
            'fbba1f7a9a53811407d7b323e92720ab21519a9bddf2d27ab6e49a9227584cd4',
            'ffd576457342b037fda55e188b4d7f7fc9d5a562a4e4ad24fab02fd3f2c2d637',
        ]],
    ];

    public static function isRetiredCopy(string $relative, string $content): bool
    {
        return in_array(hash('sha256', $content), self::RETIRED[$relative][1] ?? [], true);
    }

    /** The file behind an image bootstrap path in the toolbelt this class runs from. */
    public static function toolbeltFile(string $imagePath): ?string
    {
        if (!in_array($imagePath, [self::PHPUNIT, self::PHPSTAN, self::HEADLESS], true)) {
            return null;
        }

        return dirname(__DIR__, 2) . substr($imagePath, strlen('/opt/civikitchen/toolbelt'));
    }

    /**
     * The bootstrap the shipped phpunit config names (tests/phpunit/bootstrap.php
     * without one) and its code: the toolbelt's file for an image path, else the repo's.
     *
     * @return array{string, ?string}
     */
    public static function suiteBootstrap(Context $context): array
    {
        $bootstrap = null;
        // The runners' precedence: a phpunit.xml overrides the dist config.
        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $config) {
            if ($context->ships($config)) {
                $bootstrap = self::phpunitBootstrap($context->read($config) ?? '');
                break;
            }
        }
        $bootstrap ??= 'tests/phpunit/bootstrap.php';
        $toolbelt = self::toolbeltFile($bootstrap);
        if ($toolbelt === null) {
            return [$bootstrap, $context->readShipped($bootstrap)];
        }
        $code = is_file($toolbelt) ? file_get_contents($toolbelt) : false;

        return [$bootstrap, $code === false ? null : $code];
    }

    /**
     * A phpunit config's bootstrap attribute without a leading `./`; null when
     * the XML does not parse, is no <phpunit> config or names no bootstrap.
     */
    public static function phpunitBootstrap(string $xml): ?string
    {
        $parsed = self::phpunitConfig($xml);
        $bootstrap = $parsed === null ? '' : trim((string) $parsed['bootstrap']);

        return $bootstrap === '' ? null : (string) preg_replace('#^(\./)+#', '', $bootstrap);
    }

    /**
     * The retired copies in an extension directory, and as [file, line, path]
     * the root configs and test bootstraps naming a retired path the repository
     * does not own.
     *
     * @param  list<string>               $rootFiles file names at the extension root
     * @param  callable(string): ?string  $read      contents by relative path, null when absent
     * @return array{list<string>, list<array{string, int, string}>}
     */
    public static function scan(array $rootFiles, callable $read): array
    {
        $copies = [];
        $owned = [];
        foreach (array_keys(self::RETIRED) as $path) {
            $content = $read($path);
            if ($content !== null) {
                if (self::isRetiredCopy($path, $content)) {
                    $copies[] = $path;
                } else {
                    $owned[] = $path;
                }
            }
        }
        $references = [];
        $loaders = self::HEADLESS_LOADERS;
        foreach ($rootFiles as $file) {
            $content = str_contains($file, '/') ? null : $read($file);
            if ($content === null) {
                continue;
            }
            if (preg_match('/\.xml(\.dist)?$/', $file) === 1) {
                $bootstrap = self::phpunitBootstrap($content);
                if ($bootstrap !== null && isset(self::RETIRED[$bootstrap]) && !in_array($bootstrap, $owned, true)) {
                    $references[] = [$file, self::phpunitLine($content), $bootstrap];
                }
                if ($bootstrap !== null && !str_starts_with($bootstrap, '/')) {
                    $loaders[] = $bootstrap;
                }
            } elseif (preg_match('/\.neon(\.dist)?$/', $file) === 1 && !in_array(self::PHPSTAN_COPY, $owned, true)) {
                foreach (self::neonTree($file, $content, $read) as $neonFile => $neon) {
                    foreach (self::phpstanReferences($neon, dirname($neonFile)) as $line) {
                        $references[] = [$neonFile, $line, self::PHPSTAN_COPY];
                    }
                }
            }
        }

        if (!in_array('tests/phpunit/ckHeadless.php', $owned, true)) {
            foreach (array_unique($loaders) as $file) {
                foreach (self::headlessRequires($read($file) ?? '') as $line) {
                    $references[] = [$file, $line, 'tests/phpunit/ckHeadless.php'];
                }
            }
        }

        return [$copies, $references];
    }

    /**
     * Lines of PHP code whose string literal names a ckHeadless.php other than
     * the image's, as the template's bootstrap required its copy.
     *
     * @return list<int>
     */
    public static function headlessRequires(string $php): array
    {
        $lines = [];
        foreach (\PhpToken::tokenize($php) as $token) {
            if ($token->is(T_CONSTANT_ENCAPSED_STRING)
                && preg_match('#(^|/)ckHeadless\.php$#', substr($token->text, 1, -1)) === 1
                && substr($token->text, 1, -1) !== self::HEADLESS
            ) {
                $lines[] = $token->line;
            }
        }

        return array_values(array_unique($lines));
    }

    /**
     * Lines of a NEON config in $dir naming the root phpstanBootstrap.php, as a
     * list item or value; comments are skipped.
     *
     * @return list<int>
     */
    public static function phpstanReferences(string $neon, string $dir = '.'): array
    {
        $lines = [];
        foreach (explode("\n", $neon) as $index => $line) {
            $code = (string) preg_replace('/(^|\s)#.*$/', '', $line);
            preg_match_all('#(?<![^\s\'"\[,:])[^\s\'"\[\],:]*phpstanBootstrap\.php(?![^\s\'",\]])#', $code, $matches);
            foreach ($matches[0] as $path) {
                if (self::resolve($dir, $path) === self::PHPSTAN_COPY) {
                    $lines[] = $index + 1;
                    break;
                }
            }
        }

        return $lines;
    }

    /**
     * A NEON config and the repository files it includes, recursively, by path.
     *
     * @param  callable(string): ?string $read
     * @return array<string, string>
     */
    private static function neonTree(string $file, string $neon, callable $read): array
    {
        $tree = [$file => $neon];
        $queue = [$file];
        while ($queue !== []) {
            $current = array_shift($queue);
            $inIncludes = false;
            foreach (explode("\n", $tree[$current]) as $line) {
                $code = rtrim((string) preg_replace('/(^|\s)#.*$/', '', $line));
                if (preg_match('/^(\S[^:]*):/', $code, $key) === 1) {
                    $inIncludes = $key[1] === 'includes';
                    continue;
                }
                if (!$inIncludes || preg_match('/^\s*-\s*[\'"]?([^\'"]+?)[\'"]?\s*$/', $code, $item) !== 1) {
                    continue;
                }
                $path = self::resolve(dirname($current), $item[1]);
                if ($path === null || isset($tree[$path])) {
                    continue;
                }
                $content = $read($path);
                if ($content !== null) {
                    $tree[$path] = $content;
                    $queue[] = $path;
                }
            }
        }

        return $tree;
    }

    /** A config path relative to the extension root; null outside the repository. */
    private static function resolve(string $dir, string $path): ?string
    {
        $path = (string) preg_replace('#^%currentWorkingDirectory%/#', '', $path, 1, $fromRoot);
        if (str_starts_with($path, '/') || str_contains($path, '%')) {
            return null;
        }
        $parts = [];
        foreach (explode('/', ($fromRoot > 0 || $dir === '.' ? '' : $dir . '/') . $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private static function phpunitConfig(string $xml): ?\SimpleXMLElement
    {
        if (trim($xml) === '') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        return $parsed === false || $parsed->getName() !== 'phpunit' ? null : $parsed;
    }

    private static function phpunitLine(string $xml): int
    {
        $parsed = self::phpunitConfig($xml);
        $node = $parsed === null ? null : dom_import_simplexml($parsed);

        return $node === null ? 1 : $node->getLineNo();
    }
}
