<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A container service definition naming an own class the extension does not
 * ship, which breaks the container compile and so the whole site. Only literal
 * names are judged; foreign classes are passed over.
 */
final class ContainerServiceReferenceCheck implements Check
{
    public function name(): string
    {
        return 'container-service-reference';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $namespaces = ExtensionNamespace::all($context);

        foreach ($context->sourceFiles('', ['.php']) as $relative) {
            $source = $context->read($relative);
            if ($source === null) {
                continue;
            }
            foreach ($this->phpClassLiterals($source) as $class) {
                $this->judge($context, $reporter, $namespaces, $relative, $class);
            }
        }

        foreach ($context->sourceFiles('', ['services.yml', 'services.yaml']) as $relative) {
            $source = $context->read($relative);
            if ($source === null) {
                continue;
            }
            foreach ($this->yamlClassLiterals($source) as $class) {
                $this->judge($context, $reporter, $namespaces, $relative, $class);
            }
        }
    }

    /**
     * Class names handed to a container registration call. Deliberately narrow:
     * the argument must be a quoted literal or a ::class constant.
     *
     * @return list<string>
     */
    private function phpClassLiterals(string $source): array
    {
        $argument = '(?:\'([^\']+)\'|"([^"]+)"|([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::class)';
        $patterns = [
            // new Definition('Civi\Foo\Bar') — also covers ->setDefinition(id, new Definition(...)).
            '/new\s+(?:\\\\?Symfony\\\\[A-Za-z\\\\]+\\\\)?Definition\s*\(\s*' . $argument . '/',
            // ->register('id', 'Civi\Foo\Bar')
            '/->register\s*\(\s*(?:\'[^\']*\'|"[^"]*")\s*,\s*' . $argument . '/',
            // ->setClass('Civi\Foo\Bar')
            '/->setClass\s*\(\s*' . $argument . '/',
            // ->addSubscriber(new Civi\Foo\Bar()) / ->addSubscriber(Civi\Foo\Bar::class)
            '/->addSubscriber\s*\(\s*(?:new\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*\(|' . $argument . ')/',
        ];

        $classes = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER) === false) {
                continue;
            }
            foreach ($matches as $match) {
                foreach (array_slice($match, 1) as $candidate) {
                    if ($candidate !== '') {
                        $classes[] = $candidate;
                    }
                }
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * `class: Civi\Foo\Bar` from a services.yml, by line: no YAML component
     * ships in this image.
     *
     * @return list<string>
     */
    private function yamlClassLiterals(string $source): array
    {
        $classes = [];
        foreach (explode("\n", $source) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^-?\s*class:\s*[\'"]?([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)[\'"]?\s*$/', $line, $match) === 1) {
                $classes[] = $match[1];
            }
        }

        return array_values(array_unique($classes));
    }

    /** @param list<string> $namespaces */
    private function judge(
        Context $context,
        Reporter $reporter,
        array $namespaces,
        string $relative,
        string $class,
    ): void {
        $expected = ExtensionNamespace::ownClassFiles($context, $class, $namespaces);
        if ($expected === [] || array_filter($expected, $context->ships(...)) !== []) {
            return;
        }
        $reporter->fail(
            "$relative: service class " . ltrim(str_replace('\\\\', '\\', $class), '\\')
            . ' has no file ' . implode(' or ', $expected) . ' — the container rebuild throws and the whole site is down'
        );
    }
}
