Warning: truncated output (original token count: 14543)
Total output lines: 912

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

## PHPStan result cache

Production analysis uses PHPStan's native result cache in
`vendor/.phpstan-cache/production`, outside maintained-source discovery and the
runtime archive. The analyser still runs on every check. CI restores and saves
only this directory, with separate namespaces for each Git ref, PHP environment
and dependency lock; cache failures fall back to ordinary analysis. PR cache
state is not restored by main or the publisher. Unique run/attempt keys allow
new results to be saved without overwriting older entries.

Production analysis retains one worker to bound memory. For a cold diagnostic
run, use `vendor/bin/phpstan analyse --configuration=phpstan.neon --no-progress
--debug --memory-limit=2G`; `--debug` disables result-cache use and saving. Use
`-vv` without `--debug` to inspect normal cache reuse and invalidation.
Dependency updates must use Composer and update the committed lock. The locked
PHPStan does not reliably invalidate in-place edits of installed vendor source
with an unchanged lock; use the cold diagnostic command for such experiments.
CI installs the committed immutable dependencies before restoring analysis state.

The isolated development/integration sweep still runs uncached. The locked
PHPStan does not save result caches when only individual files are supplied;
separate per-file directories alone do not address that limitation. Keep those
fixture worlds isolated until a measured, independently reviewed alternative
is available under organisation issue #154.

## Production PHP analysis coverage

`composer analyze` is blocking PHPStan level 5. Its production profile defaults to the repository root, with root-relative
exclusions for development, dependencies and disposable output. New production
roots enter automatically, including views and the immutable generated Admin Shell. At the #167 coverage checkpoint this is 345 shipped Core
PHP files. Dependency `scanDirectories` supplies symbols; it is not direct
analysis of dependency bodies. Tests and maintenance scripts retain syntax,
standards and their behavioural gates, rather than being counted as production
analysis coverage. Levels 6–8 remain separately scoped; this gate does not imply
maximum analysis depth or complete retained-exception acceptance.

Analysis declarations follow the supported WordPress 7.0 floor. The direct
`php-stubs/wordpress-stubs` constraint is `~7.0.0`: accept 7.0 patch declarations
without silently modelling a later WordPress minor. Updating the support floor
requires an explicit declaration review. The locked PHPStan 2.2.16 and
phpstan-wordpress 2.0.4 support the Composer PHP 8.2 platform; their minimum
constraints also preserve the coverage test's current analyzer API and the
extension's WordPress 7.0 declaration support. These are development-only tools.
Their adoption does not raise the enforced analysis level or change its roots,
exclusions, or `treatPhpDocTypesAsCertain` policy.

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

## Maintained development PHP and reviewed exception boundaries

`composer analyze` invokes `scripts/analyze-development.php`, which discovers all
PHP recursively under `scripts/` and `tests/`. It invokes the locked level-5
analyzer once per file. `phpstan-development.neon` provides unit-test symbols;
`phpstan-integration.neon` provides the separate installed WordPress/fixture
symbol world. Both remain pathless: adding broad paths would reintroduce unrelated
fixture declarations into supposedly isolated invocations. Their names select
symbol environments, not lists of files permitted to enter analysis.

The isolated development sweep can exceed Composer's five-minute process limit.
Only after production analysis, the `analyze` script invokes Composer's built-in
timeout override before running that sweep; every analyzer exit remains blocking.
The repository-quality CI job retains a bounded thirty-minute limit for dependency
setup, the complete PHP contract and frontend checks. The first full native run
took 19 minutes 55 seconds, so twenty minutes leaves insufficient scheduling and
future-file headroom. All other CI job limits are unchanged.

The old 312-file pending inventory is removed. There are 363 analyzed development
files and 345 production files at this candidate (708 directly analyzed of 710
maintained PHP). Future root/split files enter automatically. The two exceptions
below are proposed for independent review within the owner's explicit policy for
files that genuinely cannot be analyzed; green CI alone does not approve them.

| Exact exemption | Evidence and disposition |
| --- | --- |
| `tests/fixtures/provider-api11-registration/workflow-provider.php` | Intentionally implements removed `RepositoryReleaseWorkflowManagementV2`; loading it against the current host fatals. PHPStan reports non-ignorable `interface.notFound`. Preserve the historical rejection fixture rather than inventing a compatible interface. |
| `tests/fixtures/provider-api12-registration/repository-provider.php` | Intentionally lacks four current provider methods; loading it fatals and PHPStan reports four non-ignorable `method.abstract` diagnostics. Preserve that incompatibility contract. |

`ProviderApiLifecycleTest` proves both historical versions are rejected in both
load orders, without loading their classes or populating the provider registry.
The coverage guard pins the exact SHA-256 identity of each file, rejects a new,
changed or stale exemption, and checks all other maintained files against actual
analyzer selection. Retiring/changing those historical contracts requires fresh
review and removal/requalification of these exemptions. Neither exemption grants
future siblings or split files an analysis waiver.

The small analysis-only `WP_CLI_Command` base declaration reflects upstream
WP-CLI v2.12.0's actual constructor contract, with source-blob provenance in the
file. It is analyzed itself and never loaded by the product or fixture runtime;
installed WP-CLI still provides the real class. Dynamic API constants in the
integration profile represent the separately installed host, whose identity is
checked by those harnesses, rather than the source checkout's current literals.

PHPStan occurrence annotations retain intrinsically invalid calls and historical
host contracts, magic-property probes, externally mutated fixture state and
locked-tool implementation APIs. The exact identifiers and reasons remain beside
the probes; no blanket ignore, baseline or broad ignored-error configuration is
allowed. Removing annotations must expose the intended diagnostic, while an
adjacent unrelated violation remains rejected. Exception dispositions are grouped
by these contracts, not by diagnostic counts, and require independent review.

WPCS native I/O exceptions preserve real inode/permission/atomic-write semantics,
private disposable test paths and exact encoded bytes. Framework names and
co-located test collaborators have declaration-local allowances. Broad native-I/O,
class, function, nonce and CLI output disables and whole-file XML exemptions are
removed. Only caller-bound view locals retain the exact variable-prefix allowance;
new functions/classes/constants/hooks in those views remain checked. Production
executable PHP is unchanged; four map PHPDocs now accurately include numeric IDs
coerced to integer keys by PHP. A developer-only ZIP safety loop now uses native
`numFiles`, with regular and malicious-entry regression controls.

## Current PHP naming contract

All owned PHP methods, properties, parameters and variables use ASCII snake_case,
including public contracts, interface implementations, inherited owned overrides,
view bindings, scripts and test helpers. `.phpcs.xml` applies `RANOwnedMethods`
and WordPress variable naming to the complete owned PHP tree rather than a list
of completed cohorts. Native PHP magic methods keep their required names. Any
other framework-owned declaration or property must have an exact-line exception
that identifies its actual owner; pre-release compatibility is not a deferral.

The connected beta.31 candidate targets Provider API **14**, Add-on API **17**,
Admin Interaction API **3** and Prospective Release API **8**. Portability API
**3**, canonical nonce/review-fingerprint generation **2**, and Release Workflow
API **3** remain unchanged. Admission guards and consumer implementations must
match the exact boundary they consume. Published preparation branches and earlier
test reports do not qualify a reconstructed composition. Run fresh `composer
check`, `pnpm check` and the applicable installed/archive proofs against its exact
head and matching consumer dependencies before claiming qualification.

Owned callers, callback descriptors, named arguments, fixture overrides, mocks
and reflection references follow the renamed symbols. There are no old-name
aliases. Persisted and wire keys, WordPress hooks, request/response schemas,
headers, nonce payloads, permissions, state transitions and failure ordering keep
their existing behavior. Foreign receivers retain their actual owner’s names.

Magic package reads preserve getter-first lookup for the existing 15 getter
keys, including their case variants. Renamed protected backing fields have new
exact fallback spellings: `deployment_policy`, `source_revision`,
`deployment_ref`, `installation_slug`, `plugin_uri`, `theme_uri`, `author_uri`,
`text_domain`, `domain_path` and `author_name`. Old fallback-only spellings are
removed…8543 tokens truncated…deployment admission/recovery/cleanup remain
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

### Historical Provider API13 methods-only tranche

Core #167 coordinates 47 interface declarations across 20 interfaces with 50
GitHub Provider implementations. Only resolved contract methods and their
callers, overrides, mocks and reflection references migrate to snake_case.
Public parameter/promoted names, DTO accessors, persisted fields and unrelated
same-spelled APIs remain unchanged. Exact API13 admission prevents API11/12
implementations from loading; workflow V3 and Add-on API16 remain unchanged.
Source overlays are preparation only until a matching immutable Provider
release is adopted and the complete installed composition is qualified.

### Current Portability API 3 connected naming

The five Portability facade/DTO classes and their receiver-resolved consumers use
snake_case methods, owned parameters and promoted properties. Portability API 3
is a breaking PHP contract; the connected Admin Interaction boundary is API 3. Canonical nonce
and review-fingerprint payload generation remains 2, preserving existing bytes,
stored/wire keys, validation and adoption behavior. No old-name aliases are added.
PortabilityCandidate and PortabilityApplyResult join both audited naming scopes.
Provider and Blueprint DTO consumers use their current snake_case contracts.
The extension catalogue must describe the published Migrator accurately; recovery
branches and candidate-source checks are not released-package certification.


The local standards guard also rejects rule `include-pattern` selectors,
`phpcs-only`/`phpcbf-only` conditional elements, and unreviewed PHPCS config values
(including success-on-error settings). Actual checker mutations prove the hidden
JSON diagnostic or zero failure status before the independent guard rejects them.
Locked PHPCS ignores relative mode on rule-specific patterns: the generated Admin
Shell variable exception therefore retains its suffix pattern, while the existing
tracked-file guard rejects every matching path except the exact parity-checked
`views/generated/ran-admin-shell.php`. Nested same-path and case-variant matches
require explicit review; a suffix neighbor still receives the diagnostic. This
limitation is protected by include-or-fail inventory rather than represented as
an exact-path XML capability that the locked checker does not implement.
