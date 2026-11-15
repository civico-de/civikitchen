<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/**
 * The CiviCRM hook catalog — GENERATED, do not edit.
 *
 * Regenerate with:
 *   php tools/gen-hook-catalog.php <core-dir>
 *
 * Generated from CiviCRM 6.20.0. Deprecated entries carry the core line they
 * were derived from, so a surprising one can be audited without rerunning the
 * generator. Hooks that no longer exist, and hooks deprecated only in the dev
 * docs, are NOT here — see HookDispatchNameCheck for those and for why.
 */
final class HookCatalog
{
    /**
     * The core release this was generated from.
     *
     * CI reads this to fetch the matching core release, so the drift gate
     * always compares against the exact release rather than a moving branch.
     * Bumping core is therefore deliberate: regenerate, and this moves with it.
     */
    public const CORE_VERSION = '6.20.0';

    /**
     * Hook suffixes CiviCRM core currently dispatches.
     *
     * @var list<string>
     */
    public const LIVE = [
        'aclGroup', 'aclWhereClause', 'activeTheme', 'alterAPIPermissions', 'alterAdminPanel',
        'alterAngular', 'alterBadge', 'alterBarcode', 'alterBundle', 'alterCalculatedMembershipStatus',
        'alterContent', 'alterCustomFieldDisplayValue', 'alterDeferredRevenueItems', 'alterDisplayName', 'alterEntityRefParams',
        'alterExternUrl', 'alterLocationMergeData', 'alterLogTables', 'alterMailContent', 'alterMailParams',
        'alterMailStore', 'alterMailer', 'alterMailingLabelParams', 'alterMailingRecipients', 'alterMenu',
        'alterNonDbTaggableEntities', 'alterPaymentProcessorParams', 'alterRedirect', 'alterReportVar', 'alterResourceSettings',
        'alterSettingsFolders', 'alterSettingsMetaData', 'alterTemplateFile', 'alterUFFields', 'angularModules',
        'apiWrappers', 'batchItems', 'batchQuery', 'buildAmount', 'buildAsset',
        'buildForm', 'buildGroupContactCache', 'buildProfile', 'buildStateProvinceForCountry', 'buildUFGroupsForModule',
        'caseChange', 'caseEmailSubjectPatterns', 'caseSummary', 'caseTypes', 'check',
        'config', 'contactListQuery', 'container', 'copy', 'coreResourceList',
        'cron', 'crypto', 'cryptoRotateKey', 'custom', 'customPre',
        'dashboard', 'dashboard_defaults', 'disable', 'dupeQuery', 'emailProcessor',
        'emailProcessorContact', 'enable', 'entityRefFilters', 'entityTypes', 'esmImportMap',
        'eventDefs', 'eventDiscount', 'export', 'fieldOptions', 'fileSearches',
        'findDuplicates', 'findExistingDuplicates', 'geocoderFormat', 'getAssetUrl', 'idsException',
        'import', 'importAlterMappedRow', 'inboundSMS', 'initiators', 'install',
        'invalidateChecksum', 'links', 'mailSetupActions', 'mailingGroups', 'mailingTemplateTypes',
        'managed', 'membershipTypeValues', 'merge', 'navigationMenu', 'oauthGrant',
        'oauthProviders', 'oauthReturn', 'oauthReturnError', 'oauthToken', 'oembed',
        'optionValues', 'pageRun', 'permission', 'permissionList', 'permission_check',
        'post', 'postCommit', 'postEmailSend', 'postIPNProcess', 'postInstall',
        'postJob', 'postMailing', 'postProcess', 'postSave', 'post_case_merge',
        'pre', 'preJob', 'preProcess', 'pre_case_merge', 'processProfile',
        'queryObjects', 'queueActive', 'queueRun', 'queueStatus', 'queueTaskError',
        'recent', 'referenceCounts', 'relativeDate', 'relativeDateSql', 'requireCiviModules',
        'scanClasses', 'searchColumns', 'searchKitTasks', 'searchProfile', 'searchTasks',
        'selectWhereClause', 'summary', 'summaryActions', 'tabset', 'themes',
        'tokenValues', 'tokens', 'translateFields', 'triggerInfo', 'ufGroupTypes',
        'unhandledException', 'uninstall', 'unsubscribeGroups', 'upgrade', 'userContentPolicy',
        'validateForm', 'validateProfile', 'viewProfile', 'xmlMenu',
    ];

    /**
     * Hooks core still fires but has marked for removal: suffix => reason.
     *
     * @var array<string, string>
     */
    public const DEPRECATED = [
        // CRM/Utils/Hook.php:1770
        'alterAPIPermissions' => 'deprecated - this hook only applies to APIv3',
        // CRM/Utils/Hook.php:1614
        'dupeQuery' => 'deprecated since 5.72',
        // CRM/Utils/Hook.php:1722
        'import' => 'deprecated',
        // CRM/Utils/Hook.php:1512
        'optionValues' => 'deprecated in favor of hook_civicrm_fieldOptions',
        // CRM/Utils/Hook.php:3346
        'relativeDate' => 'deprecated since 6.20 will be removed around 6.32. Use hook_civicrm_relativeDateSql instead.',
        // CRM/Utils/Hook.php:1004
        'tokenValues' => 'deprecated since 5.71 will be removed sometime after all core uses are fully removed.',
        // CRM/Utils/Hook.php:932
        'tokens' => 'deprecated — core logs a deprecation warning when it fires',
    ];
}
