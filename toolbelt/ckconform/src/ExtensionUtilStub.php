<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/**
 * Stand-in for CRM_*_ExtensionUtil so `return [...]` config files (mgd.php,
 * settings, .aff.php) can be evaluated outside a CiviCRM boot.
 */
final class ExtensionUtilStub
{
    /** @var array{0: string, 1: string} SHORT_NAME and LONG_NAME of the extension being checked */
    private static array $names = ['', ''];

    /**
     * Autoload stub for any CRM_*_ExtensionUtil: ts() returns the literal, other
     * statics the first argument or '', constants come from info.xml. Also
     * defines a global ts() and a CRM_Core_Component::isEnabled() answering true.
     */
    public static function register(?Context $context = null): void
    {
        if ($context !== null) {
            self::$names = [$context->fileName() ?? '', $context->extensionKey() ?? ''];
        }
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        if (!function_exists('ts')) {
            eval('function ts($text, $params = []) { return $text; }');
        }

        spl_autoload_register(static function (string $class): void {
            if ($class === 'CRM_Core_Component') {
                eval('class CRM_Core_Component { public static function isEnabled($component) { return true; } }');

                return;
            }
            if (preg_match('/^CRM_\w+_ExtensionUtil$/', $class) !== 1) {
                return;
            }
            eval(sprintf(
                'class %s {
                    const SHORT_NAME = %s;
                    const LONG_NAME = %s;
                    const CLASS_PREFIX = %s;
                    public static function ts($text, $params = []) { return $text; }
                    public static function __callStatic($name, $args) { return $args[0] ?? \'\'; }
                }',
                $class,
                var_export(self::$names[0], true),
                var_export(self::$names[1], true),
                var_export(substr($class, 0, -strlen('_ExtensionUtil')), true),
            ));
        });
    }
}
