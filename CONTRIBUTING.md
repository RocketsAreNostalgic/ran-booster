# Contributing

Use a Conventional Commit pull-request title (`feat:`, `fix:`, `docs:`, `test:`, `chore:`) so the squash commit subject consumed by Release Please truthfully represents the change.

Before proposing a change, install the locked Composer and pnpm dependencies, then run:

```sh
composer check
pnpm check
```

`composer check` is the ordinary non-mutating PHP aggregate. It retains localisation/generated-state checks, deterministic tests, Admin Shell verification, the independent `lint:syntax` parser sweep, `standards` (PHPCS/WPCS/PHPCompatibility), and the existing blocking `analyze` contract. `composer standards:fix` is the matching mutating PHPCBF command and is not part of the required check.

Runtime, archive, release-candidate, and WordPress lifecycle changes need the focused proof required by [AGENTS.md](AGENTS.md). Do not commit generated release ZIPs, secret sidecars, WordPress runtime state, or credentials.

Release Please owns version/changelog/release-PR/tag/draft lifecycle. The pinned shared Profile B workflow promotes only the exact ZIP and checksum emitted by successful main Quality; do not add repository-local candidate markers, publisher state, mutable release recovery, or a second version engine.

Follow [SUPPORT.md](SUPPORT.md) for ordinary support, non-sensitive defects, and feature requests. Follow [SECURITY.md](SECURITY.md) for vulnerabilities; do not submit security details in an issue or pull request.

RAN Booster is distributed through verified GitHub release artifacts rather than WordPress.org. Do not add WordPress.org/SVN publication, a hosted licence service, telemetry, or a second update authority without a separate decision.

## Production PHP analysis coverage

`composer analyze` is blocking PHPStan level 1. Its direct roots cover the four
root entrypoints, `RAN/`, `views/` (including the immutable generated Admin Shell)
and PHP under `assets/`. At the #167 coverage checkpoint this is 345 shipped Core
PHP files. Dependency `scanDirectories` supplies symbols; it is not direct
analysis of dependency bodies. Tests and maintenance scripts retain syntax,
standards and their behavioural gates, rather than being counted as production
analysis coverage.

`ProductionAnalysisCoverageTest` derives shipped PHP from `release-files.txt`
and compares it with PHPStan's effective direct file selection, including
exclusions. New shipped PHP outside that selection fails the ordinary test
suite. Negative controls demonstrate missing view/asset roots and an excluded
shipped file. Do not add an exclusion or baseline to conceal an omitted surface.

View PHPDoc describes locals supplied by `Dashboard` and its presenters or by a
parent include. Keep it consistent with the caller; optional input must keep its
actual fallback/guard. Generated Admin Shell bytes are verified through
`composer admin-shell:check` and must not be hand-edited. Full path coverage does
not complete #167's naming, condition, exception or connected-contract work,
and does not raise the analysis level or certify new dependency/host versions.

## Audited PHP naming scope

Under #167, `RANOwnedMethods` and variable naming checks cover only
`RAN/Deployment/DeploymentPolicy.php`, `RAN/Deployment/DeploymentState.php`,
`RAN/PackageSource.php`, `RAN/Deployment/DeploymentOutcome.php`,
`RAN/Deployment/DeploymentRequest.php`,
`RAN/Deployment/DeploymentCheckFailure.php`,
`RAN/Deployment/DeploymentStorageFailure.php`, `RAN/PackageSubdirectory.php`,
`RAN/Deployment/DeploymentAttempt.php`, `RAN/Deployment/DeploymentWorker.php`,
`RAN/Deployment/WordPressWorkerWakeup.php`,
`RAN/Storage/PackageMutationResult.php`, `RAN/Storage/PackageStorageFailure.php`,
`RAN/Storage/DatabaseLifecycleFailure.php`,
`RAN/Storage/DatabaseCompatibilityFailure.php`,
`RAN/PackageOperation.php`, `RAN/PackageRemoval/PackageRemovalResult.php`,
`RAN/WordPress/CorePackageExecutionResult.php`,
`RAN/WordPress/CorePackageExecutionFailure.php`,
`RAN/Deployment/PackageMutationGuard.php`, `RAN/WordPress/CoreSelfUpdatePolicy.php`,
`RAN/Admin/BulkPackageAction.php`, `RAN/Admin/BulkPackageActionFailure.php`,
`RAN/Admin/BulkPackageResult.php`, `RAN/Admin/BulkPackageActionService.php`,
`RAN/Secrets/SecretsStorageProvisioningResult.php`,
`RAN/Secrets/WpConfigPathWriteResult.php`,
`RAN/Secrets/SecretsStorageUnavailable.php`,
`RAN/Secrets/SecretsRuntimeAvailability.php`,
`RAN/Webhook/SignedWebhookVerifier.php`, `RAN/Webhook/WebhookController.php`,
`RAN/Webhook/WebhookProcessor.php`, `RAN/Webhook/WebhookResponse.php`,
`RAN/Admin/CredentialExpiryObservationStore.php`,
`RAN/Admin/CredentialExpiryReminder.php`, `RAN/Admin/CredentialExpiryNotice.php`,
`RAN/Admin/CredentialExpiryNoticeController.php`,
`RAN/Admin/BackgroundDeploymentFailureMonitor.php`,
`RAN/Admin/BackgroundDeploymentFailureEmail.php`,
`RAN/Admin/DeploymentOutcomeMessage.php`, `RAN/Admin/ManagedPluginFailureRows.php`,
`RAN/Admin/PublicRepositoryLookupProfileStore.php`,
`RAN/Admin/RepositoryBranchCheckEvidenceStore.php`,
`RAN/Admin/CredentialSelfDestructPurger.php`,
`RAN/Admin/PackageRepositoryRequestResolver.php`, `RAN/Admin/BoosterNoticeScope.php`,
`RAN/Admin/DevelopmentEnvironmentDetector.php`,
`RAN/Admin/CoreSelfUpdateDevelopmentNotice.php`,
`RAN/Admin/DevelopmentSafetyNoticeController.php`,
`RAN/Admin/Component/AdminActionNormalizer.php`,
`RAN/Admin/Component/AdminActionRenderer.php`,
`RAN/Admin/Component/AdminPackageSourceChoiceNormalizer.php` and
`RAN/Admin/Component/AdminStatusSummaryRenderer.php`,
`RAN/Admin/Component/ProviderManagementTableRenderer.php`,
`RAN/Admin/Component/RepositoryTableRenderer.php`,
`RAN/Admin/Component/RepositoryDetailRenderer.php` and
`RAN/Admin/RepositoryPickerController.php`,
`RAN/WordPress/CoreSelfUpdateNativeTarget.php`,
`RAN/WordPress/WordPressOrgUpdateRequestFilter.php`,
`RAN/WordPress/WordPressUpdaterLock.php` and
`RAN/Troubleshooting/CoreSelfUpdateStatus.php`.
Their owned methods use snake_case; PHP-provided enum methods such as `tryFrom()`
retain their native names. Enum cases, backed values and persisted representations
are unchanged. Callers on other types retain their current contracts until separately audited. This sixty-one-file scope does not
complete Core naming, condition or exception acceptance. The repository UI quartet
renames owned renderer and picker helpers and local variables; the public
`render()`/`handle()` entry points, projected keys, AJAX action/nonce, error statuses,
URL and form fields, accessibility attributes and rendered markup remain stable.
The picker retains narrow exceptions for the connected repository metadata and
browse-result property contracts pending their own audited cohorts.
The WordPress updater quartet retains public WordPress hook methods, the filter's
constructor argument, lock methods and their named-argument contracts. Private
helpers and owned identifiers use
snake_case while updater status and diagnostic keys, WordPress.org request
filtering, shared-lock SQL/cache behavior and external receiver contracts stay
unchanged.

DeploymentRequest uses owned snake_case properties and constructor parameters,
including `is_private`; its persisted JSON retains the `private` key and exact
canonical field order. Its JSON conversion methods use snake_case. Existing
named-argument tests exercise the renamed constructor parameters. Reserved-keyword
parameter enforcement is enabled for this file; other signature cohorts remain
subject to their own audit.

DeploymentCheckFailure uses `provider_status` and the owned `outcome_code`
constructor/property name. RuntimeException methods, messages, numeric codes and
failed-state validation remain unchanged; Branch exceptions are different types.

DeploymentStorageFailure uses snake_case owned factories/accessors and
`active_attempt` names. Inherited exception methods, numeric codes, messages and
the sanitized active-attempt array keys remain unchanged.

PackageSubdirectory uses snake_case slug helpers and the `provider_slug`
parameter. Repository-relative path validation, destination case handling and
exception messages remain unchanged. The distinct Branch Updater archive helper
retains its own contract; this scope applies only to Core `RAN\PackageSubdirectory`.

DeploymentAttempt uses snake_case owned hydration, accessors, projections and
private validation helpers. Its durable row keys, request JSON and safe/log
projection order remain unchanged. DeploymentWorker uses `run_once()` and
WordPressWorkerWakeup uses snake_case locals; worker result keys, cron hook and
scheduling behavior retain their existing contracts.

The storage mutation/failure cohort also enforces owned method and variable names
in `RAN/Storage/PackageMutationResult.php`, `PackageStorageFailure.php`,
`DatabaseLifecycleFailure.php` and `DatabaseCompatibilityFailure.php`. Fixed
diagnostics, recovery flags, database requirements and returned outcomes are
unchanged. `PackageMutationResult::get_message()` is owned; inherited Throwable
methods such as `PackageStorageFailure::getMessage()` keep their native names.

PackageOperation uses owned snake_case methods, properties and parameters,
including `is_private`. HTTP input keys and the eleven-field expected-package
map are unchanged. Reserved-keyword parameter enforcement also covers this file.
PackageRemovalResult keeps its status/outcome values and the service projection's
`status` and `outcome_code` keys. CorePackageExecutionResult uses snake_case
accessors; failure enum values and WordPress restoration classification remain
unchanged. Branch Updater's distinct execution result retains its own contract.

Mutation guard and native self-update policy PHP names use snake_case. Guard
ordering, runtime restrictions, limits, release-marker schema and diagnostic keys
remain unchanged. Other policy receivers keep their separately audited contracts.

The bulk-action cohort (`BulkPackageAction`, `BulkPackageActionFailure`,
`BulkPackageResult` and `BulkPackageActionService`) enforces owned snake_case
methods and variables. Signed notice keys and ordering, operation/error/skip
codes, selection limits, guard ordering and updater-lock behavior are unchanged.
Inherited Throwable methods and separately owned receiver contracts retain their
names. This naming migration does not change presentation or UI behavior.

The webhook-ingress cohort (`SignedWebhookVerifier`, `WebhookController`,
`WebhookProcessor` and `WebhookResponse`) enforces owned snake_case methods and
variables. Raw-body signature verification, authentication and dispatch ordering,
route and callback strings, response keys, status codes and headers are unchanged.
Repository-provider contracts, WordPress methods and other service receivers
retain their existing names.

The credential-expiry cohort enforces owned snake_case methods and variables in
its observation store, reminder, notice and notice controller. Option schema and
keys, provider-over-manual precedence, date cutoffs, fingerprints, AJAX and nonce
actions, capability ordering, rendered HTML and translated strings are unchanged.
`CredentialExpiryReport::isKnown()` and its `expiresAt` property retain their
separately scoped provider contract; the single property read has a local naming
exception. This migration does not change UI behavior.

The background-failure cohort enforces owned snake_case methods and variables in
its monitor, email, outcome-message catalogue and managed-plugin failure rows.
Newest-attempt selection, fingerprint inputs, closed outcome messages, email
filters and payloads, capabilities, hook strings and rendered HTML are unchanged.
`DeploymentFailureNotifier::notify()` and provider, WordPress and other service
receiver contracts retain their existing names.

The lookup and branch-evidence cohort enforces owned snake_case names in the
public lookup profile store, branch-check evidence store, credential expiry purger
and package repository request resolver. Persisted option keys, fingerprints,
advisory locking, purge ordering, trusted lookup selection and provider-verified
request projections are unchanged. Provider contract properties retain their
existing names with narrowly scoped access exceptions.

The development-notice cohort enforces owned snake_case methods and variables in
notice scope, environment detection, the Core source-checkout notice and the
development-safety dismissal controller. Screen selection, detection order,
capability and nonce checks, action and metadata keys, HTML and translated strings
are unchanged. WordPress hook event names stay fixed; method callback descriptors
follow the renamed owned methods.

The administration action, source-choice and status-summary component cohort
enforces owned snake_case helper, variable and parameter names. Public normalize
and render method names, structured array keys, URL validation and limits, HTML,
escaping, ARIA attributes and callback invocation behavior are unchanged.
