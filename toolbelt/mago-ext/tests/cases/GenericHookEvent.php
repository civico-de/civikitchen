<?php

declare(strict_types=1);

// Core's GenericHookEvent carries every hook parameter as a magic property; a
// subclass documents its own, and other magic classes keep the finding.
return [[
    'name' => 'generic-hook-event: magic properties of GenericHookEvent itself are its contract',
    'workspace' => 'mago-ext/tests/fixtures/generic-hook-event',
    'command' => 'analyze',
    'code' => 'non-documented-property',
    'paths' => ['events.php'],
    'expect' => [
        'events.php:23 Ambiguous property access: $panels on class `Civi\Core\Event\TypedEvent`.',
        'events.php:24 Ambiguous property access: $panels on class `Civi\Core\Event\Bag`.',
        'events.php:25 Ambiguous property access: $builder on class `Civi\Core\Event\TypedEvent`.',
        'events.php:25 Ambiguous property access: $builder on class `Civi\Core\Event\Bag`.',
    ],
]];
