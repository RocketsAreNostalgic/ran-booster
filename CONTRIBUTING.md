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

Development analysis remains uncached: the locked PHPStan does not save result
caches for file-list inputs. Compatible classes are batched, and other fixture
worlds remain separate processes as described below.

## Production PHP analysis coverage

`composer analyze` is blocking PHPStan level 7. Its production profile defaults to the repository root, with root-relative
exclusions for development, dependencies and disposable output. New production
roots enter automatically, including views and the immutable generated Admin Shell. At the #167 coverage checkpoint this is 345 shipped Core
PHP files. Dependency `scanDirectories` supplies symbols; it is not direct
analysis of dependency bodies. Tests and maintenance scripts retain syntax,
standards and their behavioural gates, rather than being counted as production
analysis coverage. Level 8 remains advisory and separately scoped; this gate does not imply
maximum analysis depth or complete retained-exception acceptance.

Three exact `property.notFound` allowances retain WordPress's mutable foreign
update-transient contract: the response-channel assignment in
`CorePackageExecutor::transient_filter()` and the two corresponding plugin/theme
assignments in `tests/WordPress/core-updater-proof.php`. The existing object must
retain its identity and supported magic accessors; declaring every object as
`stdClass`, replacing it or adding an unrelated interface would change that
contract. These allowances do not exempt any file or disable other diagnostics.
Level 6 advisory runs can report them as unmatched because that lower level does
not emit these findings; such a run is no longer the maintained enforcement
profile. Keep unmatched-ignore reporting enabled.

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

## Native injection contracts

Release administration accepts `PackageDisplayProjection` implementations,
including standalone projections, and release workflow package lookup accepts
`RepositoryPackageIdentity`. Production `Package` inherits that identity role.
Objects with matching method names alone are no longer admitted at these
boundaries. Native return types do not replace the existing source, revision,
nonempty identity, capability or nonce checks.

`DeploymentAttemptRepository` and `ManagedReleaseStore` accept real `wpdb`
connections or explicitly implementing injected connections. Reads require
`SqlReadConnection`; deployment mutations require `DeploymentWriteConnection`,
and managed-release mutations require `ManagedReleaseWriteConnection`.
Read-only connections need not implement mutation methods. Admission occurs
at the existing operation boundary after lifecycle readiness, not in the
constructor. Error channels remain optional. Deployment reads clear a declared
error slot before querying and read it afterwards through ordinary PHP property
access; inaccessible declared slots still require working magic accessors. When
no slot is declared, deployment reads use a callable `__get`/`__set` pair if
present, rejecting incomplete pairs before querying. Managed-release reads
inspect a declared error slot after querying without requiring or clearing it.
All row/result validation remains required.
Magic dispatch alone no longer satisfies these native method contracts.

`CredentialUsageReader` requires `CredentialUsageConnection` for injected
connections, adding count reads to the SQL read role. This admission follows
storage readiness and credential identity validation. Malformed counts, rows
and query errors still fail closed; the connection's error slot remains optional.

This is a deliberate narrowing of accepted injected objects, not a claim that
all structural database objects are `wpdb`. The separate `Database` capability
probes and `RepositorySourceGuard` array-token fixtures retain their contracts.
Do not add methods to those partial fixtures or coerce their query tokens merely
to satisfy an unrelated connection role.

`Database` admits real `wpdb` or a native `SchemaConnection` for schema work.
The abstract connection declares the six schema methods and writable public
`last_error` state. Admission follows capability and installed-version checks:
a supported partial capability fixture can still pass current-version readiness
without implementing schema operations. Capability queries retain structural
and magic dispatch, optional error suppression and restoration. This schema
boundary deliberately rejects unmarked injected schema objects.

`RepositorySourceGuard` retains structural connections and opaque query tokens;
missing or non-callable query methods now produce its unavailable result after
lifecycle readiness. The WordPress executor similarly rejects an unavailable
filesystem move callback while retaining valid declared and magic callbacks.

Package getters that permit an absent repository return null before hydration
without emitting PHP warnings. The required `get_repository()` getter still
throws `TypeError`, now with an explicit hydration message. Generic `PackageModel`
inputs retain their nullable normalization. Only models constructed from validated
`ManagedRepository` values carry the stronger producer identity annotations;
opaque repository IDs remain unchanged, including any permitted whitespace.

Booster validates each resolved service-method callback before registering it
with WordPress. Valid duck-typed and magic callbacks retain their original array
identity and resolution count. Invalid callbacks now throw `LogicException`
during registration. `CoreContainer::make()` itself still permits arbitrary
factory results; it does not promise that a class-name key returns that class.
Missing reflected service names retain `ReflectionException`, with an explicit
internal message; interface and trait instantiation retain their native errors.

Malformed webhook materials use PHPUnit's controlled return facility, retaining
numeric-key rejection tests. The historical beta27 worker constructs the class
loaded from that artifact through reflection; focused tests preserve its nine
ordered arguments without claiming the current Composer constructor contract.
The historical installed proof still requires its own acceptance.

WordPress transient response writes preserve arbitrary object identity, magic
access and native readonly errors. Focused tests cover those behaviors; nominal
`stdClass` narrowing or replacement would change the supported filter contract.

## Maintained development PHP and reviewed exception boundaries

`composer analyze` invokes `scripts/analyze-development.php`, which discovers all
PHP recursively under `scripts/` and `tests/`. It batches compatible test classes
in one locked level-7 analyzer invocation and analyzes all remaining files
individually. `phpstan-development.neon` provides unit-test symbols;
`phpstan-integration.neon` provides the separate installed WordPress/fixture
symbol world. Both remain pathless: adding broad paths would reintroduce unrelated
fixture declarations into supposedly isolated invocations. Their names select
symbol environments, not lists of files permitted to enter analysis.

The runner uses the already locked PHP parser to identify test-owned named
classes, interfaces, traits and enums under `RAN\\Tests`. Only development-profile
files with these declarations are eligible for batching. Foreign declarations,
functions, global constants, defining or dynamic function calls and `eval` retain
individual analysis. Case-insensitive duplicate class declarations anywhere in
the discovered source also retain individual analysis. This partition is rebuilt
from source every time; it has no maintained file inventory or cached verdict.
New and moved files enter the current appropriate execution group automatically.
`--list` reports complete discovery; `--list-batch` reports the actual batch.

Batching removes repeated PHPStan startup work. File-list invocations do not use
PHPStan's result cache, so this is not a development-cache claim. Each invocation
keeps its own symbol world and a 1 GiB analyzer limit. Local execution is serial
by default. Set `PHPSTAN_DEVELOPMENT_PROCESSES` to `1`, `2` or `4` to select a
bounded number of concurrent invocations; CI uses four. PHPStan may also create
one child worker per invocation, so allow memory for the complete process tree.
The runner starts bounded groups, waits for every child and prints each child's
buffered diagnostics in selection order. Temporary output streams are removed
when closed. Invalid concurrency settings fail the command.

Required coverage checks and real-checker probes protect both the batch and
isolated remainder, including fixture signatures that would hide a body error
if combined. They exercise serial and concurrent execution. A failed batch still
allows isolated checks to run, and any failed invocation fails the complete
analysis.

The isolated development sweep can exceed Composer's five-minute process limit.
Only after production analysis, the `analyze` script invokes Composer's built-in
timeout override before running that sweep; every analyzer exit remains blocking.
The repository-quality CI job retains a bounded thirty-minute limit for dependency
setup, the complete PHP contract and frontend checks. Installed WordPress/database
checks start alongside repository quality after the exact runtime archive is
verified. Each installed lane still verifies the source and artifact identity;
the final Quality job requires both repository and installed checks to succeed.
Release candidates retain their separate install-readback path.

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
removed. Unknown keys and unrelated subclass getters retain their behavior;
underscore-prefixed getter aliases are not introduced. WordPress header keys
such as `PluginURI`, `ThemeURI`, `AuthorURI`, `TextDomain` and `DomainPath` stay
unchanged. Paired magic-read tests must distinguish getter keys from backing-field
fallbacks.

## Current standards coverage and exceptions

The condition and parameter rules now apply by default throughout the PHP tree:
Yoda conditions, unused parameters (including inherited/interface implementations)
and reserved parameter names no longer use migration-cohort include lists.
Foreign/native signatures, fixed callback slots and implicit template/`compact()`
uses have declaration-local explanations. Owned private parameters and their callers
must not retain dead arguments merely because a class implements an interface.

Under the accepted pre-public-beta naming policy in #167, owned public parameter
names do not justify reserved-keyword exemptions. `Theme::from_wp_theme_object()`
uses `$wp_theme`; `Plugin::from_wp_array()` uses `$plugin_data`; the
`ManagedRepository`, `RepositoryDescriptor` and `RepositoryReference` constructors
use `$is_private`. Named callers must use these names; no compatibility aliases
are added. Positional argument order, defaults, the DTOs' public readonly `$private`
properties and their `private` wire fields remain unchanged. The current eight
consumer candidates have no affected named calls or parameter-reflection contracts.
Locked-checker mutations of all five real declarations must report the original
reserved-name diagnostic. Runtime tests exercise the new named arguments alongside
existing positional calls; installed WordPress proof exercises the theme factory.

The inherited profile is WordPress-Extra plus PHPCompatibilityWP and the RAN
syntax baseline, not every WordPress-Docs rule. Full PHPStan path coverage is
enforced at level 7; it does not imply maximum analysis depth. The [standards scope inventory](docs/php-standards-coverage.md)
records the remaining specific exclusions and their rationale. The former blanket
exemptions in 29 test/harness files are removed; a token-aware guard rejects new
whole-file or all-rule suppressions. Specific native/runtime fixture exceptions
remain explicit and do not constitute a blanket security clearance.

`StandardsCoverageTest` feeds positive and negative fixtures through the actual
locked checker and this repository's ruleset. It proves new paths receive checks,
inherited classes cannot conceal unused private parameters, reserved names are
reported and a local exception does not suppress the next declaration. Keep
exceptions specific, justified and reviewable; do not add blanket exclusions to
make the canonical command pass.

Core locks `ran/coding-standards` v1.0.3 at
`28f6e7c0a758c93503a0267696245a5540e002a5`. This development-only adoption
retains the rulesets and owned-method sniff from v1.0.1; the newer release
qualifies the shared package's own maintained PHP. Core's checks, exceptions and
PHPStan enforcement remain independently controlled here.

The v1.0.1 release introduced a message-specific exclusion for
`WordPress.Security.EscapeOutput.ExceptionNotEscaped`: exception messages are
diagnostic values, and escaping belongs at actual output boundaries. Core removes
redundant test-only annotations and the characterization-path XML rule. Shipped
PHP retains its original annotations because the separate required installed
Plugin Check scanner uses its own WPCS profile and still reports this message.
Any alignment of that scanner needs a separate explicit decision. Mixed test
annotations retain their other selectors and explanations. Output escaping,
nonce and sanitization checks remain enabled; this adoption does not certify
every existing exception.

### Historical naming cohort record

The following cohort notes record incremental migrations before the complete
beta.31 naming composition. Their scope counts, deferred symbols, retained
parameter/property names and package versions describe those earlier checkpoints,
not the current naming contract above. Preserve historical behavior evidence;
do not restore those temporary compatibility exceptions or use historical test
results to qualify the current tree.

The connected Branch and GitHub Provider helper tranche updates eight Core
consumer/test files under #167. Branch-owned calls and implementations use the
accepted snake_case API; Provider helper cleanup and logging overrides follow
the Provider-owned names and parameters. Three reflection references in
`BuiltInGitHubRegistrationTest` also follow the private property rename already
landed in Provider #42, so adopting current Provider source preserves the
existing artifact-limit assertions. This intentionally breaks the old beta
PHP API. The installed native-lifecycle fixture likewise reflects the renamed
private `native_targets` field. Foreign Core-owned interfaces, persisted/wire fields and runtime
behavior remain unchanged. The three production consumers already belong to
the method and variable enforcement scopes, which remain 176 and 166 files.

The committed Composer lock pairs these consumers with immutable Branch
`v1.0.0-beta.8` and GitHub Provider `v1.0.0-beta.9`. Earlier source overlays
were preparation only; archive and installed qualification must use the real
released lock. Follow
[the connected-boundary adoption sequence](docs/connected-naming-adoption.md)
before landing this composition or claiming installed/archive qualification.

The artifact-ceiling cohort uses `PackageArtifactLimit::require_valid()` in its
resolver, durable deployment request and tests. Method enforcement includes
`PackageArtifactLimit`, expanding the scope from 172 to 173 files; variable
enforcement remains at 166 files. The `resolve()` null-only `legacyNull`
parameter, constants, integer bounds and failure message remain unchanged.

The internal webhook-history cohort uses `WebhookHistory::for_package()`,
`WebhookHistory::from_record()` and `WebhookHistoryView::to_array()` throughout
its owned callers and tests. Method enforcement includes both history classes,
expanding the scope from 170 to 172 files; variable enforcement is unchanged by
this cohort. Constructor/promoted parameter names and serialized history keys
remain intact. The separate authority resolver's `forPackage()` and foreign
readiness `toArray()` contracts retain their existing names. Historical records
remain observations, not live readiness or restored update authority.


The internal-variable cohort adds variable enforcement for Booster,
ProviderRegistry, ReleaseWorkflowRequestController, ReleaseWorkflowDisplay and
ReleaseWorkflowPresenter: variable scope expands from 161 to 166 files, while
method scope remains 170. Internal locals, private parameters and eight private
properties use snake_case; two ProviderRegistry property reflection references
follow the private rename. Two owned private named-argument labels follow their
renamed private parameters. Public/protected parameter contracts, promoted
parameters, public properties, DTO fields, method names and production
data/literals retain their names. Exact line/source exceptions document the
retained contracts.
RepositoryReleaseWorkflowStatus remains outside variable scope because its
mixed-case variables are public/promoted DTO contracts requiring a connected
migration. Registration, security/nonce/capability checks, projection/rendering
and failure/control ordering are preserved.

The connected public package cohort migrates the `Package` interface and
`AbstractPackage`, `Plugin` and `Theme` methods, including their factories,
owned callers, callable checks, fixture overrides and mock method references.
Method enforcement adds the interface (173 to 174 files); variable enforcement
remains at 166 files. Public parameter names, properties, persisted keys and
foreign receiver methods retain their contracts. Direct method callers must use
the renamed snake_case methods; no legacy method aliases are provided.

Magic property reads retain their existing getter-first behavior through a
bounded map of the 15 existing getter keys. Case variants, protected-field
fallback, unknown keys and unrelated subclass getters retain their behavior.
The fallback rejects newly introduced underscore-prefixed getter aliases, so
method renaming does not create new magic property names. Existing package
subclasses must migrate their owned method overrides with the interface.

The connected public repository cohort migrates 21 declarations across
`AbstractPackageRepository`, `PluginRepository` and `ThemeRepository`, together
with owned callers, test overrides and mock method references. These classes
were already enforced; method and variable scopes remain at 174 and 166 files.
Public parameter names, including `userId`, signatures, persisted keys, source
filtering, revision fences, adoption/removal ordering and failure behavior retain
their contracts. Direct callers and subclasses must use the renamed snake_case
methods; no legacy aliases are provided. Foreign same-spelled methods remain
unchanged.

The secret-storage recovery cohort migrates nine public methods in `SecretsFile`
and `SecretsStorageProvisioner`, including connected callers, fixture overrides
and recovery tests. Both classes were already enforced; naming scopes remain
174 method files and 166 variable files. Public parameter names, including
`expectedPath`, retain named-argument compatibility. Recovery tokens and
confirmations, path and credential-fitness checks, lock and state rechecks, exact
key/ciphertext deletion, rollback and fresh-request verification remain unchanged.
Callers and subclasses use the snake_case methods without legacy aliases.

The documentation and metadata helper cohort migrates seven public methods in
`DocumentationHookRenderer` and internal `MetadataRules`, together with owned
callers, views and tests. MetadataRules joins method enforcement, increasing the
method scope from 174 to 175 files; variable scope remains 166 files. Public
parameters, including `maximumLength`, retain named-argument compatibility.
Validation expressions and error messages, hook names, metadata keys and rendered
HTML remain unchanged. Connected callers use snake_case methods without aliases.

The webhook InstallationRecord cohort migrates 17 methods and their connected
calls in WordPressInstallationStore, WebhookOperationCoordinator, WebhookHistory,
WebhookDisplayModel and RepositoryWebhookManagementControls. Schema 4, serialized
keys, storage identity, profile revisions, the unknown-hook sentinel, immutable
copies, validation/errors and rendered output are unchanged; no aliases are added.
InstallationRecord already belongs to both naming scopes. Its promoted fields
`providerCode`, `repositoryId`, `hookId`, `managementCredentialId`, `webhookProfileId`,
`webhookProfileScope`, `webhookProfileRevision`, `webhookProfileDisposition`,
`createdAt` and `checkedAt`, their constructor parameters, public named parameters
on `with_check`, `with_management_credential` and `with_profile` (including
`profileId`), and key parameters remain connected-migration obligations under
#167. Retained variable/property deferrals are temporary migration debt.

The blueprint serialization/archive cohort migrates ten methods across
BlueprintArchive, BlueprintCredential, BlueprintPackage and PackageBlueprint,
together with connected callers. Blueprint format 1, canonical JSON field order
and bytes, archive encryption/limits, credential handling and SensitiveParameter
contracts are unchanged. BlueprintPackage's promoted `displayName` and
`providerRepositoryId` properties and constructor parameters remain a separate
connected-migration obligation. These four classes were already enforced; both
cohorts preserve the scopes of 175 method files and 166 variable files.

The administration request/render cohort migrates 90 declarations (89 distinct
names) across 15 Core classes: Booster, Dashboard and Dispatcher (32), package,
provider and deployment presenters and the repository-row normalizer (24),
package, provider-profile, portability and deployment controllers (13), and the
webhook controls, controller, route helper and display model (21). Connected
callers, views, registered callback method strings and test overrides use the
snake_case methods without legacy aliases. WebhookManagementAdminUrl joins
method enforcement, increasing its scope to 176 files; variable scope remains
166 files. Public parameter and property names retain their existing contracts
and remain separate connected-migration obligations under #167.

WordPress hook names, priorities and argument counts, routes, menu slugs, nonce
and capability checks, request/response schemas, redirects, HTML/HTMX output,
secret handling, passive GET behavior and multisite quarantine are unchanged.
Foreign same-spelled methods, including Release Workflow's enrichRepositoryRows
and PHP reflection accessors, remain unchanged. Provider, AddOn and Branch
contracts, dependency adoption and historical evidence are outside this cohort.

The private API12 cohort migrates 74 private declarations and their owned calls:
two in Booster, eight in ProviderRegistry, one in RepositoryReleaseWorkflowStatus,
26 in ReleaseWorkflowRequestController, nine in ReleaseWorkflowDisplay and 28 in
ReleaseWorkflowPresenter. Five reflection method references in the connected
tests follow the renamed methods. Foreign methods with the same spelling,
property reflection, parameters, properties, local variables, all public/protected
signatures and production literals remain unchanged. Registration atomicity,
provider admission, capability/nonce checks, signed results, projection and
rendered output contracts are preserved; this is not a UI behavior change.

These six files expanded method enforcement from 164 to 170 files. Variable
enforcement remained at 161 files for that method-only cohort. Fifty retained mixed-case public/protected
declarations have individual deferred-contract annotations, pending their
connected migration. Magic methods retain their native names. This cohort does
not complete public contracts or variable/parameter naming in these files.

The protected-contract cohort migrates 54 production declarations: five
SecretsFile I/O and identity seams, 21 SecretsStorageProvisioner seams, 20
AbstractPackageRepository/PluginRepository/ThemeRepository declarations, three
AbstractPackage/Plugin/Theme runtime_slug declarations, and five Dispatcher and
ProviderProfileAdminController response helpers. Connected callers and owned
test overrides follow the new names. Parameter names, reference/default values,
SensitiveParameter attributes, covariant and never return types, literals,
SQL/transaction order, filesystem/crypto/rollback behavior and response output
remain unchanged.

The three runtime-slug files expanded method enforcement to 164 files. Variable
enforcement stayed at 161 files for that cohort. Their 26 retained mixed-case public declarations
have individual deferred-contract annotations: the Package interface and dynamic
getter dispatch still require a separate connected public migration. Magic
methods retain their native names. All other files in this tranche were already
scoped. This does not certify unknown external subclasses or complete the
remaining public/private naming inventory.

The secure-storage, uninstall and deployment-identity cohort under #167 migrates
33 production declarations: seven protected LocalDataRemover helpers, eight
protected SiteKeyStore storage seams, sixteen protected WpConfigSecretsPathWriter
filesystem seams, PrivateLocationCandidateResolver::validate_configured and
DeploymentAdminController::current_user_id. All connected callers and 28 owned
test overrides follow those names. Its seven affected production files were
already included in both naming scopes.

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
and LocalSecretStoreUnavailable. Those additions brought both scopes to 161 files;
the runtime-slug additions above expand only method enforcement. Previously
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
