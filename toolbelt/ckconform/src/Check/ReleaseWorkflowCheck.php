<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Reporter;

/**
 * An extension without the template's release caller (opt-out: `release: none`
 * with a reason). In a multi-extension repository all extensions release in
 * lockstep under one tag.
 */
final class ReleaseWorkflowCheck implements Check
{
    public function name(): string
    {
        return 'release-workflow';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        $declared = $context->policyValue('release');
        if ($declared !== null) {
            // Only the documented form opts out, and the reason is mandatory.
            if (preg_match('/^none\s+--\s+\S/', $declared) === 1) {
                $publishers = $this->publishingCallers($context);
                if ($publishers !== []) {
                    $reporter->fail(
                        'civikitchen.yaml declares release: none, but ' . implode(', ', $publishers) . ' publishes a '
                        . 'release on every tag push — delete that caller (ckinit --update does), or drop the declaration'
                    );

                    return;
                }
                $reporter->ok("no releases — declared deliberate in civikitchen.yaml ({$declared})");

                return;
            }
            $reporter->fail("unrecognised release= policy '{$declared}' — only 'release=none -- <reason>' opts out");

            return;
        }

        $callers = $this->publishingCallers($context);
        if (count($callers) > 1) {
            $reporter->fail(
                'more than one job calls ' . Context::SHARED_RELEASE . ' (' . implode(', ', $callers)
                . ') — one tag push would publish the release twice; keep the managed .github/workflows/release.yml'
            );

            return;
        }
        if ($callers !== []) {
            if ($context->isMonorepo()) {
                $this->judgeLockstep($context, $reporter, $callers[0]);
            }

            return;
        }
        if ($context->isMonorepo()) {
            $reporter->fail(
                'no job calls ' . Context::SHARED_RELEASE . ' with working_directory: ' . $context->extensionDirectory()
                . ' — the repository\'s release leaves this extension out; run ckinit --update on the repository root, '
                . 'or declare release: none with a reason; see docs/extension-releases.md'
            );

            return;
        }

        $reporter->fail(
            'no release workflow (' . Context::SHARED_RELEASE . ') — nothing cuts a tagged, verified archive, '
            . 'so a consumer has no immutable ref to pin and installs a moving branch instead; '
            . 'run ckinit --update, or declare release: none with a reason; see docs/extension-releases.md'
        );
    }

    /**
     * One tag, one release: this extension's job only builds, needs the build
     * jobs of the same-repository extensions it requires, and a publish job needs it.
     */
    private function judgeLockstep(Context $context, Reporter $reporter, string $label): void
    {
        $at = (int) strrpos($label, ':');
        [$workflow, $name] = [substr($label, 0, $at), substr($label, $at + 1)];
        $stage = self::input($context->scopedJobs()[$label], 'stage') ?? 'release';
        if ($stage !== 'build') {
            $reporter->fail(
                "{$label} runs stage: " . (is_scalar($stage) ? (string) $stage : '?') . ' — in a repository of several '
                . 'extensions each extension\'s job runs stage: build, and one stage: publish job releases them together'
            );

            return;
        }
        $jobs = $context->jobsOf($workflow);
        $callers = $context->releaseCallers()[$workflow] ?? [];
        foreach ($this->requiredSiblings($context) as $key => $directory) {
            $builders = array_keys(array_filter(
                $callers,
                static fn (array $job): bool => self::input($job, 'stage') === 'build' && $context->jobDirectory($job) === $directory,
            ));
            if ($builders === []) {
                $reporter->fail(
                    "{$name} requires {$key}, but no stage: build job in {$workflow} builds {$directory} — "
                    . 'the smoke test installs it from this run, so it has to release in lockstep'
                );
            } elseif (!self::reaches($jobs, $name, (string) $builders[0])) {
                $reporter->fail(
                    "{$label} does not need {$builders[0]}, which builds {$key} it requires — "
                    . 'its smoke test would look for that archive before it exists'
                );
            }
        }
        foreach ($callers as $publisher => $job) {
            if (self::input($job, 'stage') === 'publish' && self::reaches($jobs, (string) $publisher, $name)) {
                return;
            }
        }
        $reporter->fail(
            "no stage: publish job in {$workflow} needs {$name} — this extension's archive would be missing from the release"
        );
    }

    /**
     * Labels of this extension's jobs that publish through the shared release.
     *
     * @return list<string>
     */
    private function publishingCallers(Context $context): array
    {
        return array_values(array_filter(
            $context->scopedJobsCalling(Context::SHARED_RELEASE),
            static fn (string $label): bool => Context::publishesRelease($context->scopedJobs()[$label]),
        ));
    }

    /**
     * Same-repository extensions this one requires: key => directory.
     *
     * @return array<string, string>
     */
    private function requiredSiblings(Context $context): array
    {
        $own = $context->extensionDirectory();
        $directories = [];
        foreach ($context->repositoryExtensions() as $directory => $info) {
            if ((string) $directory !== $own) {
                $directories[trim((string) $info['key'])] = (string) $directory;
            }
        }

        return array_intersect_key($directories, array_flip($context->requiredExtensions()));
    }

    /**
     * Whether $from needs $to, directly or through the jobs it needs.
     *
     * @param array<array-key, array<mixed>> $jobs
     */
    private static function reaches(array $jobs, string $from, string $to): bool
    {
        $seen = [];
        $queue = [$from];
        while ($queue !== []) {
            foreach ((array) ($jobs[array_shift($queue)]['needs'] ?? []) as $need) {
                if (!is_string($need) || isset($seen[$need])) {
                    continue;
                }
                if ($need === $to) {
                    return true;
                }
                $seen[$need] = true;
                $queue[] = $need;
            }
        }

        return false;
    }

    /** @param array<mixed> $job */
    private static function input(array $job, string $name): mixed
    {
        return is_array($job['with'] ?? null) ? ($job['with'][$name] ?? null) : null;
    }
}
