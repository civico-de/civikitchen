<?php

declare(strict_types=1);

namespace CiviKitchen\Toolbelt\Repository;

use CiviKitchen\Toolbelt\Process\Runner;
use RuntimeException;

/** civikitchen.yaml, read through `ckconform --policy`, the format's one parser. */
final class Policy
{
    public function __construct(
        private readonly string $checkoutRoot,
        private readonly Runner $runner = new Runner(),
    ) {
    }

    /**
     * Every value of $key, each with its ` -- reason`. An unreadable file
     * throws: a coverage floor that reads as unset is no floor. A warning
     * PHP logs to stderr is no value.
     *
     * @return list<string>
     */
    public function values(string $key, ?string $directory = null): array
    {
        $result = $this->runner->captureSeparate([$this->checkoutRoot . '/toolbelt/bin/ckconform', '--policy', $key], null, $directory);
        if ($result['status'] !== 0) {
            $file = $directory === null ? 'civikitchen.yaml' : "{$directory}/civikitchen.yaml";
            throw new RuntimeException("cannot read policy.{$key} from {$file}:\n" . trim($result['stderr']));
        }
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $result['stdout']) ?: []), 'strlen'));
    }

    /** The first value of $key, '' when unset. */
    public function value(string $key): string
    {
        return $this->values($key)[0] ?? '';
    }
}
