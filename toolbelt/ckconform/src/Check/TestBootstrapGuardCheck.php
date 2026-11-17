<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * A Civi\Test headless suite whose tests/ tree has no TEST_DB_DSN guard, which
 * can wipe the developer's own CiviCRM; pure unit suites are exempt.
 */
final class TestBootstrapGuardCheck implements Check
{
    private const MARKERS = [
        'HeadlessInterface',
        'TransactionalInterface',
        'Civi\Test\\',
    ];

    public function name(): string
    {
        return 'test-bootstrap-guard';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        if ($context->trackedUnder('tests/phpunit') === []) {
            return;
        }

        if (!$context->isTracked('tests/phpunit/bootstrap.php')) {
            $reporter->fail('no tests/phpunit/bootstrap.php');

            return;
        }

        if (!$this->usesCiviTest($context)) {
            $reporter->ok('unit-only suite (no Civi\\Test) — no test-database guard needed');

            return;
        }

        if ($this->guardsInCode($context->read('tests/phpunit/bootstrap.php') ?? '')) {
            $reporter->ok('test bootstrap has the TEST_DB_DSN guard');
        } else {
            $reporter->fail('tests/phpunit/bootstrap.php lacks the TEST_DB_DSN guard — headless runs can wipe the dev DB');
        }
    }

    private function usesCiviTest(Context $context): bool
    {
        foreach ($context->trackedUnder('tests') as $file) {
            $contents = $context->read($file);
            if ($contents === null) {
                continue;
            }
            foreach (self::MARKERS as $marker) {
                if (str_contains($contents, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether code (comments stripped) names TEST_DB_DSN and reaches a throw,
     * exit or die; a bare getenv() read is no guard.
     */
    private function guardsInCode(string $source): bool
    {
        if ($source === '') {
            return false;
        }
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        if (!str_contains($code, 'TEST_DB_DSN')) {
            return false;
        }

        return preg_match('/\b(throw\s+new\b|exit\s*\(|die\s*\()/', $code) === 1;
    }
}
