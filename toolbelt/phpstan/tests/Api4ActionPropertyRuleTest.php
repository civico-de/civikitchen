<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan\Tests;

use CiviKitchen\PHPStan\Api4ActionPropertyRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Both spellings of a mandatory parameter, pinned against each other.
 *
 * `protected int $contactId;` with `@required` is reported — the kernel reads
 * the getter before it checks the requirement — while the untyped
 * `@var`+`@required` property next to it, which is core's own form, is not.
 * `?T` and `mixed` start uninitialized too; a constructor assignment does not.
 *
 * @extends RuleTestCase<Api4ActionPropertyRule>
 */
final class Api4ActionPropertyRuleTest extends RuleTestCase
{

    use ScansFixtures;

    public function testParametersThatFailAsPhpErrorsInsteadOfApiErrors(): void
    {
        $this->analyse(
            [__DIR__ . '/fixtures/generic-stubs.php', __DIR__ . '/fixtures/api4-action-properties.php'],
            [
                [
                    'APIv4 action parameter $contactId is typed int with no default — a caller that omits it gets '
                    . '"must not be accessed before initialization" instead of an API validation error, because '
                    . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks @required.'
                    . ' @required does not prevent this.'
                    . " Declare it untyped with an @var docblock and @required (core's own form for a mandatory "
                    . 'parameter), or give it a default.',
                    17,
                ],
                [
                    'APIv4 action parameter $channel is typed string with no default — a caller that omits it gets '
                    . '"must not be accessed before initialization" instead of an API validation error, because '
                    . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks @required.'
                    . " Declare it untyped with an @var docblock and @required (core's own form for a mandatory "
                    . 'parameter), or give it a default.',
                    33,
                ],
                [
                    'APIv4 action parameter $retries is marked @required but has a default, so the kernel never sees '
                    . 'it missing and the requirement is never enforced.',
                    36,
                ],
                [
                    'APIv4 action parameter $locale is typed ?string with no default — a caller that omits it gets '
                    . '"must not be accessed before initialization" instead of an API validation error, because '
                    . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks @required.'
                    . ' @required does not prevent this.'
                    . " Declare it untyped with an @var docblock and @required (core's own form for a mandatory "
                    . 'parameter), or give it a default.',
                    39,
                ],
                [
                    'APIv4 action parameter $signature is typed ?string with no default — a caller that omits it gets '
                    . '"must not be accessed before initialization" instead of an API validation error, because '
                    . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks @required.'
                    . " Declare it untyped with an @var docblock and @required (core's own form for a mandatory "
                    . 'parameter), or give it a default.',
                    42,
                ],
                [
                    'APIv4 action parameter $fromTrait is typed string with no default — a caller that omits it gets '
                    . '"must not be accessed before initialization" instead of an API validation error, because '
                    . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks @required.'
                    . " Declare it untyped with an @var docblock and @required (core's own form for a mandatory "
                    . 'parameter), or give it a default.',
                    74,
                ],
                [
                    'APIv4 action parameter $any is typed mixed with no default — a caller that omits it gets '
                    . '"must not be accessed before initialization" instead of an API validation error, because '
                    . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks @required.'
                    . " Declare it untyped with an @var docblock and @required (core's own form for a mandatory "
                    . 'parameter), or give it a default.',
                    76,
                ],
                [
                    'APIv4 action parameter $maybe is typed ?string with no default — a caller that omits it gets '
                    . '"must not be accessed before initialization" instead of an API validation error, because '
                    . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks @required.'
                    . " Declare it untyped with an @var docblock and @required (core's own form for a mandatory "
                    . 'parameter), or give it a default.',
                    78,
                ],
                [
                    'APIv4 action parameter $plain is typed string with no default — a caller that omits it gets '
                    . '"must not be accessed before initialization" instead of an API validation error, because '
                    . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks @required.'
                    . " Declare it untyped with an @var docblock and @required (core's own form for a mandatory "
                    . 'parameter), or give it a default.',
                    111,
                ],
                [
                    'APIv4 action parameter $halfLazy is typed array with no default — a caller that omits it gets '
                    . '"must not be accessed before initialization" instead of an API validation error, because '
                    . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks @required.'
                    . " Declare it untyped with an @var docblock and @required (core's own form for a mandatory "
                    . 'parameter), or give it a default.',
                    134,
                ],
                [
                    'APIv4 action parameter $readInExit is typed string with no default — a caller that omits it gets '
                    . '"must not be accessed before initialization" instead of an API validation error, because '
                    . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks @required.'
                    . " Declare it untyped with an @var docblock and @required (core's own form for a mandatory "
                    . 'parameter), or give it a default.',
                    176,
                ],
                [
                    'APIv4 action parameter $emptyWrongBranch is typed ?string with no default — a caller that omits it gets '
                    . '"must not be accessed before initialization" instead of an API validation error, because '
                    . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks @required.'
                    . " Declare it untyped with an @var docblock and @required (core's own form for a mandatory "
                    . 'parameter), or give it a default.',
                    178,
                ],
                [
                    'APIv4 action parameter $elseRead is typed ?string with no default — a caller that omits it gets '
                    . '"must not be accessed before initialization" instead of an API validation error, because '
                    . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks @required.'
                    . " Declare it untyped with an @var docblock and @required (core's own form for a mandatory "
                    . 'parameter), or give it a default.',
                    237,
                ],
                [
                    'APIv4 action parameter $trimmed is typed ?string with no default — a caller that omits it gets '
                    . '"must not be accessed before initialization" instead of an API validation error, because '
                    . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks @required.'
                    . " Declare it untyped with an @var docblock and @required (core's own form for a mandatory "
                    . 'parameter), or give it a default.',
                    239,
                ],
                [
                    'APIv4 action parameter $orWrong is typed ?string with no default — a caller that omits it gets '
                    . '"must not be accessed before initialization" instead of an API validation error, because '
                    . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks @required.'
                    . " Declare it untyped with an @var docblock and @required (core's own form for a mandatory "
                    . 'parameter), or give it a default.',
                    298,
                ],
                [
                    'APIv4 action parameter $matchDefault is typed ?string with no default — a caller that omits it gets '
                    . '"must not be accessed before initialization" instead of an API validation error, because '
                    . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks @required.'
                    . " Declare it untyped with an @var docblock and @required (core's own form for a mandatory "
                    . 'parameter), or give it a default.',
                    365,
                ],
                [
                    'APIv4 action parameter $noElse is typed string with no default — a caller that omits it gets '
                    . '"must not be accessed before initialization" instead of an API validation error, because '
                    . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks @required.'
                    . " Declare it untyped with an @var docblock and @required (core's own form for a mandatory "
                    . 'parameter), or give it a default.',
                    367,
                ],
                [
                    'APIv4 action parameter $andWrong is typed ?string with no default — a caller that omits it gets '
                    . '"must not be accessed before initialization" instead of an API validation error, because '
                    . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks @required.'
                    . " Declare it untyped with an @var docblock and @required (core's own form for a mandatory "
                    . 'parameter), or give it a default.',
                    369,
                ],
                [
                    'APIv4 action parameter $matchUnsetRead is typed ?string with no default — a caller that omits it gets '
                    . '"must not be accessed before initialization" instead of an API validation error, because '
                    . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks @required.'
                    . " Declare it untyped with an @var docblock and @required (core's own form for a mandatory "
                    . 'parameter), or give it a default.',
                    414,
                ],
                [
                    'APIv4 action parameter $writeThrough is typed ?stdClass with no default — a caller that omits it gets '
                    . '"must not be accessed before initialization" instead of an API validation error, because '
                    . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks @required.'
                    . " Declare it untyped with an @var docblock and @required (core's own form for a mandatory "
                    . 'parameter), or give it a default.',
                    451,
                ],
                [
                    'APIv4 action parameter $issetTrueWrong is typed ?string with no default — a caller that omits it gets '
                    . '"must not be accessed before initialization" instead of an API validation error, because '
                    . 'ValidateFieldsSubscriber reads every parameter through its getter before it checks @required.'
                    . " Declare it untyped with an @var docblock and @required (core's own form for a mandatory "
                    . 'parameter), or give it a default.',
                    560,
                ],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new Api4ActionPropertyRule();
    }
}
