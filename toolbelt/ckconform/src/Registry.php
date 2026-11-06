<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/**
 * The ordered list of checks.
 *
 * Order is not cosmetic: the golden output is compared line by line, so a
 * reordering reads as a regression. Entries whose class does not exist are skipped.
 */
final class Registry
{
    /** @var list<class-string<Check>> */
    private const CHECKS = [
        Check\PhpcsConfigCheck::class,
        Check\PhpstanConfigCheck::class,
        Check\PhpunitConfigCheck::class,
        Check\TestBootstrapGuardCheck::class,
        Check\DeprecationGateCheck::class,
        Check\CoverageSectionCheck::class,
        Check\CiCoverageCheck::class,
        Check\CoversNothingCheck::class,
        Check\HeadlessBuilderAppliedCheck::class,
        Check\TestSuiteRequiredCheck::class,
        Check\ComposerJsonCheck::class,
        Check\AutoloadPathCheck::class,
        Check\PhpVersionCoherenceCheck::class,
        Check\Psr0ClassPathCheck::class,
        Check\CiWorkflowCheck::class,
        Check\ReleaseWorkflowCheck::class,
        Check\ReleaseTagCoherenceCheck::class,
        Check\ReleaseTagsCheck::class,
        Check\VersionFormatCheck::class,
        Check\UnreleasedShippedChangesCheck::class,
        Check\ChangelogCheck::class,
        Check\HandbookCheck::class,
        Check\MonorepoVersionLockstepCheck::class,
        Check\ConfigWithoutRunnerCheck::class,
        Check\RequiredExtensionsCheck::class,
        Check\MonorepoRequiresMountedCheck::class,
        Check\MixinDeclarationCheck::class,
        Check\MixinVersionCheck::class,
        Check\EntitySchemaFormatCheck::class,
        Check\EntityDaoStubCheck::class,
        Check\LicenseSkeletonCheck::class,
        Check\LicenseCoherenceCheck::class,
        Check\CopyrightCheck::class,
        Check\LicensingUrlCheck::class,
        Check\NpmLicenseCheck::class,
        Check\FloatingTagCheck::class,
        Check\DeprecatedImagePathCheck::class,
        Check\MovedRepositoryCheck::class,
        Check\ComposeFloatingTagCheck::class,
        Check\ComposeProjectNameCheck::class,
        Check\PlaywrightDiagnosticsCheck::class,
        Check\WorkflowPermissionsCheck::class,
        Check\FrontEndApi3Check::class,
        Check\Api3SurfaceCheck::class,
        Check\RawSqlCheck::class,
        Check\Api4EntityCheck::class,
        Check\Api4SelfEntityCheck::class,
        Check\Api4LiteralEntityCheck::class,
        Check\RiverleaCustomPropertyCheck::class,
        Check\LockfileCheck::class,
        Check\NpmInstallCheck::class,
        Check\CommittedArtifactCheck::class,
        Check\VendoredBundleCheck::class,
        Check\DeployHygieneCheck::class,
        Check\GitignoreCheck::class,
        Check\GitignoreCoverageCheck::class,
        Check\SettingsMetadataCheck::class,
        Check\ManagedEntityMetadataCheck::class,
        Check\ManagedJobCheck::class,
        Check\ManagedReferenceGraphCheck::class,
        Check\HookDispatchNameCheck::class,
        Check\HookStyleCheck::class,
        Check\DeclaredCallbackCheck::class,
        Check\ContainerServiceReferenceCheck::class,
        Check\TemplateReferenceCheck::class,
        Check\UpgraderIntegrityCheck::class,
        Check\PortableConfigReferenceCheck::class,
        Check\AfformContractCheck::class,
        Check\PermissionClosureCheck::class,
        Check\MessageTemplateTokenCheck::class,
        Check\CrmScopeCheck::class,
        Check\SmartyCompatCheck::class,
        Check\TranslationCatalogCheck::class,
        Check\PolicyKeyCheck::class,
        Check\SuppressionHygieneCheck::class,
    ];

    /** @return list<Check> */
    public static function all(): array
    {
        $checks = [];
        foreach (self::CHECKS as $class) {
            if (class_exists($class)) {
                $checks[] = new $class();
            }
        }

        return $checks;
    }

    /** @return list<class-string<Check>> */
    public static function declared(): array
    {
        return self::CHECKS;
    }
}
