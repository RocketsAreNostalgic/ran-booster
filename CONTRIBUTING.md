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

The secure-storage, uninstall and deployment-identity cohort under #167 migrates
33 production declarations: seven protected LocalDataRemover helpers, eight
protected SiteKeyStore storage seams, sixteen protected WpConfigSecretsPathWriter
filesystem seams, PrivateLocationCandidateResolver::validate_configured and
DeploymentAdminController::current_user_id. All connected callers and 28 owned
test overrides follow those names. The seven affected production files were
already included in both naming scopes; each scope remains 161 files.

Only these method names change. Parameter names, types, defaults, visibility,
SensitiveParameter attributes, retained public methods and serialized values are
preserved. Key election and exact deletion, cache/SQL ordering, configuration
bytes and metadata, locking/replacement/rollback, path and permission rejection,
uninstall cleanup ordering and deployment capability/nonce checks retain their
contracts. Unknown external subclasses of the protected seams are not certified;
this pre-release migration provides no compatibility aliases. API12 #177 is now
part of the integrated baseline; its provider contracts and release work remain
outside this cohort.

The connected public/protected cohort under #167 migrates nine package-removal
gateway methods with their interface, implementation and service callers; two
installation-store compare-and-swap methods with their coordinator callers;
19 troubleshooting protected methods with their owned fixture overrides; the
multisite notice callback with its WordPress registration; and the portability
exception factory with its callers. All 43 production declarations and connected
test doubles follow the owned snake_case names. No compatibility aliases are
introduced. Other public APIs, named/promoted parameters and serialized fields
retain their existing contracts. InstallationStore's retained camelCase named
parameters have exact-line exceptions rather than broad suppression.

Both naming scopes additionally include PackageRemovalGateway, InstallationStore
and LocalSecretStoreUnavailable, bringing each scope to 161 files. Previously
scoped implementations retain their audited enforcement. SQL/CAS/cache order,
package deactivation/deletion and rollback, troubleshooting configuration reads,
notice capability and once-only output, and exception messages/chaining are
unchanged. API12 #177 and connected external provider/updater contracts remain
separately owned; this cohort does not certify the broader public inventory.


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
`RAN/Troubleshooting/CoreSelfUpdateStatus.php`,
`RAN/Secrets/PosixFilesystemProbe.php`,
`RAN/Secrets/EncryptedSecretsEnvelopeCodec.php`,
`RAN/Storage/CredentialUsageReader.php` and
`RAN/RepositoryProvider/InvalidCredentialInput.php`,
`RAN/Portability/BlueprintReviewer.php`,
`RAN/Portability/BlueprintRepositoryVerifier.php`,
`RAN/Portability/ManagedPackageBlueprintExporter.php` and
`RAN/AddOn/Portability/NativePortabilityFacade.php`,
`RAN/Admin/Interaction/CoreAdminInteractionFacade.php`,
`RAN/Admin/Interaction/SignedAdminInteractionFlow.php`,
`RAN/Admin/Interaction/AdminInteractionTarget.php`,
`RAN/Admin/Interaction/AdminInteractionRequest.php`,
`RAN/PackageOperationService.php`,
`RAN/PackageRemoval/PackageRemovalService.php`,
`RAN/PackageRemoval/WordPressPackageRemovalGateway.php`,
`RAN/WordPress/CorePackageExecutor.php`,
`RAN/Logging/BoosterLogger.php`,
`RAN/Logging/TemporaryDebugCapture.php`,
`RAN/RepositoryProvider/ProviderDiagnosticRequest.php`,
`RAN/RepositoryProvider/ProviderDiagnosticResult.php`,
`RAN/Portability/BlueprintArchive.php`,
`RAN/Portability/BlueprintCredential.php`,
`RAN/Portability/BlueprintPackage.php`,
`RAN/Portability/PackageBlueprint.php`,
`RAN/RepositoryProvider/RepositoryReference.php`,
`RAN/RepositoryProvider/RepositoryDescriptor.php`,
`RAN/RepositoryProvider/RepositoryBrowseRequest.php` and
`RAN/Portability/WpPusherCoexistencePolicy.php`.
The five-slice secrets, credential, webhook, release-evidence and database cohort
also includes:

- `RAN/Secrets/SiteKeyStore.php`
- `RAN/Secrets/PrivateLocationCandidateResolver.php`
- `RAN/RepositoryProvider/CredentialValidationResult.php`
- `RAN/RepositoryProvider/CredentialExpiryReport.php`
- `RAN/RepositoryProvider/WebhookRequest.php`
- `RAN/RepositoryProvider/PushEvent.php`
- `RAN/RepositoryProvider/RepositoryWebhookFitnessResult.php`
- `RAN/RepositoryProvider/RepositoryWebhookOperationResult.php`
- `RAN/RepositoryProvider/RepositoryReleaseInspection.php`
- `RAN/RepositoryProvider/RepositoryReleaseCandidate.php`
- `RAN/RepositoryProvider/RepositoryReleaseNativeTargetStatus.php`
- `RAN/Storage/Database.php`

The backend five-slice cohort also includes:

- `RAN/Portability/PortabilityApplicationService.php`
- `RAN/Troubleshooting/TroubleshootingService.php`
- `RAN/Troubleshooting/LocalTroubleshootingService.php`
- `RAN/Storage/AbstractPackageRepository.php`
- `RAN/Storage/PluginRepository.php`
- `RAN/Storage/ThemeRepository.php`
- `RAN/Secrets/WpConfigSecretsPathWriter.php`
- `RAN/Secrets/SecretsStorageProvisioner.php`

The release and deployment five-slice cohort also includes:

- `RAN/RepositoryProvider/Admin/ProviderAdminMetadata.php`
- `RAN/AddOn/WebhookAssistance/WebhookProfileMetadata.php`
- `RAN/AddOn/ReleaseTracking/ReleaseTrackingPreflight.php`
- `RAN/Internal/ReleaseManagement/ProspectiveReleaseCandidateReader.php`
- `RAN/AddOn/WebhookAssistance/AssistedWebhookFacade.php`
- `RAN/AddOn/WebhookAssistance/WebhookAssistanceReadinessEvaluator.php`
- `RAN/WordPress/ManagedReleaseStore.php`
- `RAN/WordPress/ManagedReleaseTargetRegistrar.php`
- `RAN/Deployment/DeploymentCoordinator.php`

The lifecycle and release service five-slice cohort also includes:

- `RAN/Uninstall/LocalDataRemover.php`
- `RAN/Runtime/UnsupportedMultisiteBootstrap.php`
- `RAN/Deployment/DeploymentAttemptRepository.php`
- `RAN/AddOn/ReleaseTracking/NativeReleaseTrackingFacade.php`
- `RAN/AddOn/ReleaseTracking/NativeProspectiveReleaseFacade.php`

The request-processing five-slice cohort also includes:

- `RAN/Dispatcher.php`
- `RAN/Admin/ProviderProfileAdminController.php`
- `RAN/Admin/PackageAdminController.php`
- `RAN/Admin/PortabilityController.php`
- `RAN/Admin/WebhookManagement/Operation/WebhookOperationCoordinator.php`

The deployment recovery and webhook persistence five-slice cohort also includes:

- `RAN/Admin/DeploymentAdminController.php`
- `RAN/Admin/DeploymentAdminPresenter.php`
- `RAN/Storage/RepositorySourceGuard.php`
- `RAN/Admin/WebhookCleanupContext.php`
- `RAN/Admin/WebhookManagement/Installation/InstallationRecord.php`
- `RAN/Admin/WebhookManagement/Installation/WordPressInstallationStore.php`

The administrative projection and portability internals five-slice cohort also includes:

- `RAN/Admin/SecretsStorageSetupPresenter.php`
- `RAN/Admin/DocumentationHookRenderer.php`
- `RAN/Admin/PackagePagePresenter.php`
- `RAN/Admin/ProviderRepositoryRowsNormalizer.php`
- `RAN/AddOn/Portability/PortabilityFacade.php`
- `RAN/AddOn/Portability/PortabilityReviewResult.php`

The parallel private-naming tranche also includes:

- `RAN/Admin/ReleaseManagement/ManagedReleaseBrowserOperations.php`
- `RAN/Admin/ReleaseManagement/ProspectiveReleaseOperations.php`
- `RAN/Admin/ReleaseManagement/ReleaseManagementControls.php`
- `RAN/Admin/ReleaseManagement/ReleaseManagementDisplay.php`
- `RAN/Admin/ReleaseManagement/ReleaseTrackingOperations.php`
- `RAN/Admin/ProviderSettingsPresenter.php`
- `RAN/Dashboard.php`
- `RAN/Admin/WebhookManagement/Display/WebhookDisplayModel.php`
- `RAN/Admin/WebhookManagement/RepositoryWebhookManagementControls.php`
- `RAN/Admin/WebhookManagement/WebhookManagementController.php`
- `RAN/Deployment/AdmittedBranchHostAdapter.php`
- `RAN/Deployment/PreparedArtifact.php`
- `RAN/Deployment/ReleaseArtifactCustodian.php`
- `RAN/RepositoryProvider/AuthenticatedPreparedArchive.php`
- `RAN/Secrets/SecretsFile.php`
- `RAN/RepositoryProvider/ProviderSecretPolicyCatalog.php`
- `RAN/RepositoryProvider/RepositoryReleaseWorkflowResult.php`
- `RAN/RepositoryProvider/RepositoryReleaseWorkflowTarget.php`

Their owned methods use snake_case; PHP-provided enum methods such as `tryFrom()`
retain their native names. Enum cases, backed values and persisted representations
are unchanged. Callers on other types retain their current contracts until separately audited. This 158-file scope does not
complete Core naming, condition or exception acceptance. The repository UI quartet
renames owned renderer and picker helpers and local variables; the public
`render()`/`handle()` entry points, projected keys, AJAX action/nonce, error statuses,
URL and form fields, accessibility attributes and rendered markup remain stable.
The condition and parameter compliance tranche independently enables inherited
`WordPress.PHP.YodaConditions` on 32 audited source paths and promotes
`Generic.CodeAnalysis.UnusedFunctionParameter` diagnostics to blocking errors on
nine audited paths. Inherited class-wide and before-last-parameter exemptions
are re-enabled within that scope so an interface cannot hide an unused private
helper parameter. The exact cohorts are the include patterns in `.phpcs.xml`;
separately owned API12 files remain deferred. Equality rewrites preserve operand
effects and short-circuit order. Expression grouping may resolve a WPCS token
heuristic without changing the parsed expression; comparisons that must read
mutable state before a WordPress filter retain precise site-local explanations.
Unused private parameters require complete caller, argument-type and evaluation
proof before removal. Required callbacks, retained named-argument contracts and
`compact()` recognition gaps use local annotations instead of blanket suppression.
Exception annotations identify safe message boundaries on the relevant line;
domain failure messages are not HTML-escaped to satisfy an output sniff.
SecretsFile retains its atomic native-filesystem exception, while its obsolete
silenced-error and `var_export()` exclusions are removed. Scoped enforcement does
not complete the broader public naming or programme acceptance matrix.

The picker retains narrow exceptions for the connected repository metadata and
browse-result property contracts pending their own audited cohorts.
The WordPress updater quartet retains public WordPress hook methods, the filter's
constructor argument, lock methods and their named-argument contracts. Private
helpers and owned identifiers use
snake_case while updater status and diagnostic keys, WordPress.org request
filtering, shared-lock SQL/cache behavior and external receiver contracts stay
unchanged.

The credential-support quartet renames private helpers and owned identifiers.
Public parameter names, the promoted `tableName` property and inherited Throwable
contracts retain narrow documented exceptions where required. Encrypted-envelope
bytes and validation, Sodium calls and sensitive-parameter attributes, POSIX probe
ordering and cleanup, credential-usage SQL and projections, and safe failure text
remain unchanged. Reserved-parameter enforcement is unchanged.

The portability quartet renames private helpers, private properties and owned
variables. Public methods and named parameters, including by-reference outputs
and inherited facade contracts, retain narrow documented exceptions. Foreign DTO
properties, authorization and credential ordering, blueprint bytes and fingerprints,
provider access and error classifications remain unchanged. Reserved-parameter
enforcement is unchanged.

The administration interaction quartet renames private helpers, private properties
and owned variables. Public methods and named parameters, callback strings and
foreign signed-request DTO fields retain their existing contracts through narrow
documented exceptions. Nonce inputs, canonical URLs, route validation, response
headers, fragment validation, rendered markup and error text remain unchanged.
Reserved-parameter enforcement is unchanged.

The package-operation quartet renames private helpers, private properties and
owned variables. Public methods, named parameters and promoted constructor
properties retain their existing caller and gateway/executor override contracts
through narrow documented exceptions. Operation results, lock/guard/removal
ordering, WordPress hook installation and restoration, failure mapping and every
PreparedArtifact custody contract remain unchanged. The executor test's private
reflection reference follows its renamed helper. Reserved-parameter enforcement
is unchanged.

The diagnostic safety quartet renames private helpers, private request properties
and owned variables. Public methods and named parameters retain narrow documented
exceptions, and public diagnostic-result fields remain unchanged. Diagnostic
budgets, deadline ordering and sticky exhaustion reasons, result projections,
log sanitization and exception redaction, capture bytes and limits, lock ordering,
filesystem permissions, replacement and cleanup behavior remain unchanged.
Reserved-parameter enforcement is unchanged.

The blueprint format and archive quartet renames private helpers and owned locals.
Public methods, named parameters, promoted fields and native ZipArchive properties
retain narrow documented exceptions. SensitiveParameter attributes, canonical
schema and key ordering, credential associations and fingerprints, resource limits,
management equality, archive encryption and entry validation, error-handler
restoration and failed-write cleanup remain unchanged. Reserved-parameter
enforcement is unchanged.

The repository input trio renames private helpers, private validator parameters
and owned browse-request properties. Public methods, named parameters and DTO
fields retain narrow documented exceptions. Opaque locator bytes, repository
identity casing, slug normalization, array projections, browse scope validation,
request deadlines, timeout and response-size limits, counters and failure codes
remain unchanged. The browse test's private reflection reference follows the
renamed property. Reserved-parameter enforcement is unchanged.

The WP Pusher coexistence policy renames private inventory helpers and owned locals.
Its public methods and activation callback retain narrow documented exceptions;
exact plugin identity, option lookup order, malformed-inventory rejection and
localized activation failures remain unchanged. Reserved-parameter enforcement
is unchanged.

The five-slice cohort renames private helpers, private state and owned locals.
Public methods, named and promoted constructor parameters, serialized fields and
protected override seams retain narrow documented exceptions. The private
resolver callback follows its helper rename. Key encoding, autoload repair,
option deletion/cache ordering, path fingerprints and permissions remain intact.
Credential and release timestamp rules, bounded failure messages, raw/normalized
webhook headers, verification clones and result projections remain unchanged.
Database SQL/DDL, migration ordering, capability caching, failure classification
and wpdb error restoration are preserved. Reserved-parameter scope is unchanged.

The backend five-slice cohort renames private helpers, private repository state
and owned locals. Public methods, named/promoted constructor parameters and
protected override seams retain narrow documented exceptions. Existing private
reflection references follow their helper renames. Portability review fingerprints,
credential decisions, disabled-package verification and retries remain unchanged.
Troubleshooting row order, provider budgets, safe reports and marker cleanup,
repository SQL/transaction/write/read-back order, configuration file bytes and
lock/replace/rollback behavior, and secrets recovery authority/revisions/order are
preserved. Reserved-parameter scope is unchanged.

The release and deployment cohort renames 61 private helpers, non-promoted
private state and owned locals. Public/protected methods, named parameters,
promoted constructor properties and external DTO fields retain narrow line-local
exceptions. Metadata validation and projections, candidate ordering/failure mapping,
webhook capability/nonce/target-lock and profile cleanup order, native authority
snapshots and hook restoration, and deployment admission/recovery/cleanup remain
unchanged. WordPress callback strings and every PreparedArtifact call retain their
existing contracts. Test behavior and reserved-parameter enforcement are unchanged.

The lifecycle and release service cohort renames 83 private helpers, non-promoted
private state and owned locals. Public/protected methods, constructor and other
public named parameters, promoted properties and external DTO fields retain narrow
exceptions. Cleanup ownership, inode/permission checks and deletion order,
Multisite hook registration and notice behavior, deployment SQL/transactions,
retention and recovery, native release locking/cache/restoration, and prospective
release acquisition/custody/cleanup/adoption order remain unchanged. Protected
uninstall override seams and every PreparedArtifact call retain their contracts.
Test sources and reserved-parameter enforcement are unchanged.

The request-processing cohort renames 62 private helpers, six non-promoted
private properties and owned locals. Public/protected callback and caller names,
constructor parameters, promoted properties and external DTO fields retain narrow
exceptions. Two portability include-scope variables retain their existing view
contract. Fifteen private reflection references follow the renamed helpers; all
other test behavior remains unchanged. Authorization/nonce/capability order,
credential failure redaction, signed/header/redirect bytes, portability cleanup,
webhook locking, concurrent-write recovery and durable state remain unchanged.
Both method and variable naming rules cover this cohort; reserved-parameter scope
is unchanged.

The deployment recovery and webhook persistence cohort renames 17 private
helpers, one non-promoted private property and owned locals. Public/protected
methods, constructor and promoted parameters, and external DTO fields retain
narrow documented exceptions. Recovery identity and lock checks, activity query
and projection order, source-authority validation, cleanup capability ordering,
installation serialization, five compare-and-swap attempts, raw-state preservation
and cache deletion order remain unchanged. Two private input names remain distinct
from their serialization locals. Both naming rules cover this cohort; tests and
reserved-parameter enforcement are unchanged.

The administrative projection and portability internals cohort renames 28
private helpers and owned locals. Public/protected methods, named constructor and
promoted parameters, and external DTO members retain narrow documented exceptions.
Secret redaction and recovery projections, documentation/package callback order
and output-buffer restoration, repository-row validation and immutable projections,
and portability canonical JSON, nonce/fingerprint inputs and failure mapping remain
unchanged. No non-promoted private properties require renaming. Both naming rules
cover this cohort; test sources and reserved-parameter enforcement are unchanged.

The parallel private-naming tranche covers release operations, administration,
artifact custody, secrets storage and policy/DTO internals. Its 264 private helpers
and eligible private state and owned locals use snake_case. Public/protected
methods, named and promoted parameters, DTO fields, WordPress callbacks and view
include-scope aliases retain narrow documented exceptions. Release projections,
request and nonce boundaries, dashboard render order, hook registration/restoration,
artifact authorization and custody, secure-file locking/replacement/rollback,
recovery authority and serialized policy/DTO bytes remain unchanged. Necessary
private reflection references follow their helpers. Both naming rules cover all
18 types; API12-owned source paths and reserved-parameter scope are unchanged.

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
