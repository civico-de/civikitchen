<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/**
 * One conformance rule. It must run against an arbitrary directory, so a
 * passing and a failing fixture repo can test it.
 */
interface Check
{
    /** Stable identifier, used by tests and by --only. */
    public function name(): string;

    public function run(Context $context, Reporter $reporter): void;
}
