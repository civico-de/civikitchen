<?php

declare(strict_types=1);

namespace CiviKitchen\PHPStan;

/**
 * CiviCRM core's CRM_/Civi\ namespace surface — GENERATED, do not edit.
 *
 * Regenerate with:
 *   php tools/gen-core-namespace-catalog.php <core-dir>
 *
 * Generated from CiviCRM 6.20.0. The boundary rule in ArchitectureTest
 * allows these prefixes (plus the extension's own); every other CRM_/Civi\
 * symbol is another extension's internals. Core-shipped extensions (ext/)
 * are included — SearchKit, Afform etc. count as core.
 */
final class CoreNamespaceCatalog
{
    /**
     * The core release this was generated from.
     *
     * CI reads this to fetch the matching source tree, so the drift gate
     * compares against the exact release rather than a moving branch.
     */
    public const CORE_VERSION = '6.20.0';

    /**
     * X in CRM_X_* / CRM_X owned by core.
     *
     * @var list<string>
     */
    public const CRM_COMPONENTS = [
        'ACL', 'Activity', 'Admin', 'Afform', 'AfformAdmin',
        'Api4', 'Authx', 'Badge', 'Batch', 'Campaign',
        'Case', 'CiviImport', 'CivicrmAdminUi', 'CivicrmSearchUi', 'Ckeditor4',
        'Contact', 'Contribute', 'Core', 'Custom', 'Dashlet',
        'Dedupe', 'Event', 'Export', 'Extension', 'Financial',
        'Friend', 'Grant', 'Group', 'Iframe', 'Import',
        'Invoicing', 'Legacycustomsearches', 'Logging', 'Mailing', 'Member',
        'MessageAdmin', 'Note', 'OAuth', 'Oembed', 'PCP',
        'Pledge', 'Postbox', 'Price', 'Profile', 'Queue',
        'Report', 'SMS', 'Search', 'Standaloneusers', 'Tag',
        'UF', 'Upgrade', 'Utils', 'riverlea',
    ];

    /**
     * X in Civi\X\* / Civi\X owned by core.
     *
     * @var list<string>
     */
    public const CIVI_NAMESPACES = [
        'API', 'ActionSchedule', 'AdminUI', 'Afform', 'AfformAdmin',
        'AfformLoginToken', 'AfformReCaptcha2', 'Angular', 'Api4', 'Authx',
        'BAO', 'CCase', 'ChartKit', 'Checkout', 'CiUtil',
        'Codeception', 'ComposerTasks', 'Connect', 'Contribute', 'Core',
        'Crypto', 'Custom', 'Esm', 'FlexMailer', 'I18n',
        'Iframe', 'Import', 'Install', 'Kcfinder', 'LegacyFinder',
        'Mailing', 'Managed', 'Membership', 'OAuth', 'Oembed',
        'Order', 'Payment', 'Pipe', 'Postbox', 'Queue',
        'Report', 'Riverlea', 'Schema', 'Search', 'Smarty',
        'Standalone', 'Test', 'Token', 'UserDashboard', 'UserJob',
        'Util', 'Visual', 'WorkflowMessage',
    ];

    /**
     * info.xml keys of the extensions core ships in ext/.
     *
     * A <requires> on one of these needs no lookup by the boundary rule —
     * their namespaces are already in the two lists above.
     *
     * @var list<string>
     */
    public const CORE_EXTENSION_KEYS = [
        'afform_login_token', 'authx', 'batch_entry',
        'chart_kit', 'civi_campaign', 'civi_case',
        'civi_contribute', 'civi_event', 'civi_mail',
        'civi_member', 'civi_pledge', 'civi_report',
        'civicrm_admin_ui', 'civicrm_search_ui', 'civigrant',
        'civiimport', 'ckeditor4', 'contributioncancelactions',
        'elavon', 'eventcart', 'ewaysingle',
        'financialacls', 'greenwich', 'iframe',
        'legacybatchentry', 'legacycustomsearches', 'legacydedupefinder',
        'legacyprofiles', 'message_admin', 'oauth-client',
        'oembed', 'org.civicrm.afform', 'org.civicrm.afform-mock',
        'org.civicrm.afform_admin', 'org.civicrm.flexmailer', 'org.civicrm.search_kit',
        'payflowpro', 'postbox', 'recaptcha',
        'riverlea', 'scheduled_communications', 'search_kit_reports',
        'sequentialcreditnotes', 'standaloneusers', 'tellafriend',
        'user_dashboard',
    ];
}
