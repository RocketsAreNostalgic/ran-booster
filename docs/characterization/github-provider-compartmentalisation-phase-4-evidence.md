# GitHub provider compartmentalisation Phase 4 evidence

Date: 2026-08-14

## Scope and decision

- Phase 3 evidence commit: `0195554f46d3d2b80b7c7c3854e956c9ab02c493`.
- Phase 4 implementation commit: `9b95cd63ab46e051c227bc31654b97c3225c0cc9`.
- Programme baseline: `6570293ce97b4a39efa51b68f40a18638f6952ff`.
- Provider API remains exactly `9`.
- The mandatory internal isolation programme is complete.
- Extraction decision: **NO-GO**.

Phase 4 created no extraction repository, Composer package, WordPress plugin
entrypoint, release, tag, publication, merge, push, deployment, migration or
Dex synchronization. It did not change provider behavior, credentials,
webhook/archive/mutation custody, schemas, options, tables, hooks or persistent
state. The immutable published Core `v1.0.0-beta.16` and its certified consumers
remain unchanged historical release evidence.

## Final module ownership

`RAN/Booster/GitHub/` contains seven production types and 2,112 physical lines:

- `CredentialPolicy.php`: 130 lines;
- `Diagnostics.php`: 146 lines;
- `GitHubProvider.php`: 393 lines;
- `RepositoryBrowser.php`: 737 lines;
- `RepositoryWebhookClient.php`: 308 lines;
- `WebhookNormalizer.php`: 256 lines; and
- `WebhookPolicy.php`: 142 lines.

The 16 focused module test/support files contain 3,043 physical lines. The
module lane owns GitHub protocol, token policy, repository browsing, archive
authorization, diagnostics, webhook policy/normalization/management, aggregate
metadata and module-only shims.

The Phase 4 review found that `ModuleDependencyBoundaryTest` still contained two
Core-host inventory assertions. That would have made a copied module suite
depend on the complete Core source tree during the extraction rehearsal. Commit
`9b95cd63ab46e051c227bc31654b97c3225c0cc9` moved those assertions without
weakening them into the Core-owned `GitHubModuleHostBoundaryTest`. The portable
module test tree now contains only module behavior and its dependency allowlist;
the Core suite continues to enforce the one host composition seam and the
closed list of host-integration owners.

### Complete module dependency allowlist

The module imports exactly these 50 documented Provider API contracts, values
and helpers. `ModuleDependencyBoundaryTest` fails on any addition or on any
reference to Core admin controllers, deployment/storage repositories,
container, logging, secrets or WordPress implementations.

```text
RAN\RepositoryProvider\Admin\CredentialFieldMetadata
RAN\RepositoryProvider\Admin\CredentialKindMetadata
RAN\RepositoryProvider\Admin\ProviderAdminMetadata
RAN\RepositoryProvider\Admin\ProviderNavigationPlacement
RAN\RepositoryProvider\Admin\ProviderSetupMetadata
RAN\RepositoryProvider\Admin\ProviderWebhookAssistanceMetadata
RAN\RepositoryProvider\Admin\WebhookScopeMetadata
RAN\RepositoryProvider\ArchiveRequest
RAN\RepositoryProvider\AuthenticatedPreparedArchive
RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader
RAN\RepositoryProvider\CredentialExpiryReport
RAN\RepositoryProvider\CredentialValidationResult
RAN\RepositoryProvider\CredentialValidator
RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser
RAN\RepositoryProvider\GitReferenceSyntax
RAN\RepositoryProvider\InvalidCredentialInput
RAN\RepositoryProvider\InvalidWebhookInput
RAN\RepositoryProvider\PreparedArchive
RAN\RepositoryProvider\ProviderCode
RAN\RepositoryProvider\ProviderCredentialPolicy
RAN\RepositoryProvider\ProviderCredentialPolicySupplier
RAN\RepositoryProvider\ProviderCredentialStore
RAN\RepositoryProvider\ProviderDiagnosticBudgetExceeded
RAN\RepositoryProvider\ProviderDiagnosticRequest
RAN\RepositoryProvider\ProviderDiagnosticResult
RAN\RepositoryProvider\ProviderDiagnostics
RAN\RepositoryProvider\ProviderMetadata
RAN\RepositoryProvider\ProviderWebhookPolicy
RAN\RepositoryProvider\ProviderWebhookProfileReader
RAN\RepositoryProvider\PublicRepositoryBrowseMetadata
RAN\RepositoryProvider\PushEvent
RAN\RepositoryProvider\RepositoryBrowseMode
RAN\RepositoryProvider\RepositoryBrowseRequest
RAN\RepositoryProvider\RepositoryBrowseResult
RAN\RepositoryProvider\RepositoryDescriptor
RAN\RepositoryProvider\RepositoryLookupRequest
RAN\RepositoryProvider\RepositoryProvider
RAN\RepositoryProvider\RepositoryReference
RAN\RepositoryProvider\RepositoryWebhookFitness
RAN\RepositoryProvider\RepositoryWebhookFitnessResult
RAN\RepositoryProvider\RepositoryWebhookManagement
RAN\RepositoryProvider\RepositoryWebhookOperationResult
RAN\RepositoryProvider\RepositoryWebhookSettingsLink
RAN\RepositoryProvider\SignedWebhookVerification
RAN\RepositoryProvider\StaleDeployment
RAN\RepositoryProvider\SubmittedCredentialValidator
RAN\RepositoryProvider\WebhookEnvelope
RAN\RepositoryProvider\WebhookNormalizer
RAN\RepositoryProvider\WebhookRejected
RAN\RepositoryProvider\WebhookRequest
```

### Complete Core production references back into the module

There is one owning production file and one composition call:

- `RAN/BoosterServiceProvider.php:42` imports `GitHubProvider`; and
- `RAN/BoosterServiceProvider.php:245` calls `GitHubProvider::create()` inside
  `ProviderRegistry::registerWithCredentialStore( 'gh', ... )`.

There is no production `new GitHubProvider`, second composition owner, alias,
compatibility implementation or generic module loader.

### Composition seam

`GitHubProvider::create()` is the aggregate's only public static constructor. It
accepts only `ProviderCredentialStore` and
`AuthenticatedWebhookDeliveryEvidenceReader`, returns `RepositoryProvider`, and
constructs the five module-owned collaborators locally. Its private constructor
creates metadata and policy values only. Registration performs no HTTP request,
filesystem/database access, credential enumeration, credential-material read or
delivery-evidence read.

The normal Core boundary, external vendor-conformance lane, fail-on-read pressure
case and installed ZIP all proved this property. The aggregate implements the
base provider contract plus the seven current optional capability contracts; no
new public seam or runtime type was introduced.

## Generic Core audit

The final static search found no accidental GitHub repository-provider endpoint,
header, token guidance, archive construction, webhook payload interpretation or
remote mutation policy outside `RAN/Booster/GitHub/`.

The remaining GitHub-shaped production matches have intentional owners:

- the two `BoosterServiceProvider` matches are the single composition seam;
- `InvalidCredentialInput` and `ProviderDiagnosticResult` contain generic
  fail-closed safe-text scanners covering several provider token/header shapes;
- `NativeProspectiveReleaseFacade`, `NativeReleaseTrackingFacade`,
  `ReleaseTrackingPreflight`, `GitHubReleaseUpdateNotice`,
  `CorePackageExecutor`, `GitHubReleaseUpdaterBootstrap`,
  `ManagedReleasePreflight`, `ManagedReleaseTargetRegistrar`,
  `ProspectiveReleaseArtifact` and `PreparedArtifact` belong to the separately
  scoped shared GitHub Release updater, Core self-update or managed-release
  product path; and
- the GitHub Sponsors link in `Booster.php` is intentional product copy.

The credential-pattern scan found only named test canaries and deliberately
invalid examples. No token, authorization value, private key or site-owned
secret was present. The module has no option, transient, hook registration,
table, `$wpdb`, database repository or mutable credential writer.

## Contract-pressure matrices

Both pressures were genuine host-side substitutions applied as committed
changes only in disposable detached worktrees at the Phase 3 evidence commit.
Neither pressure changed GitHub production code or survived into the Core
branch.

### Pressure 1: provider-neutral metadata projection

Temporary commit `6c80e3070af190f91d5dcd4f0b4dc22e4db616b0` made the API 9 external fixture publish its existing
provider-neutral `repositoryLocatorHint` contract and required generic Core's
troubleshooting projection to expose `group/subgroup/repository`. Delta:
`tests +8/-1`, production `+0/-0`.

Results:

- focused fixture owner: 4 tests / 49 assertions;
- GitHub module: 195 tests / 1,678 assertions;
- full PHPUnit: 2,144 tests / 13,790 assertions;
- characterization: 35 checks;
- focused host boundary: 40 tests / 278 assertions;
- asset tests: 137;
- `composer check`, PHPCS, `pnpm check` and PHP 8.2 lint of 579 files: passed;
- build and independent verifier: 334 installed PHP files, SHA-256
  `7555acce912f55c206a8b595f65f7ff49d71358fd047cf7fa6e972ad4e306a97`;
- activation, reactivation and installed `gh` metadata/capability/policy/navigation
  readback without a development Composer autoloader: passed; and
- external fixture before-Core and after-Core load orders: passed, including the
  new generic locator-hint projection.

### Pressure 2: provider credential-store substitution

Temporary commit `6a8472c393781644fbea3615c9bced46a4e866e6` replaced the Core registration test's concrete
credential-store implementation with a provider-bound fail-on-read store. Every
credential method throws, so successful registration proves that the host can
replace the store implementation and the GitHub composition seam neither knows
the Core `SecretsFile` implementation nor reads credential state. Delta:
`tests +10/-13`, production `+0/-0`.

Results:

- focused composition owner: 1 test / 19 assertions;
- GitHub module: 195 tests / 1,678 assertions;
- full PHPUnit: 2,144 tests / 13,789 assertions;
- characterization: 35 checks;
- focused host boundary: 40 tests / 277 assertions;
- asset tests: 137;
- `composer check`, PHPCS, `pnpm check` and PHP 8.2 lint of 579 files: passed;
- build and independent verifier: 334 installed PHP files, SHA-256
  `acdaf64018a3320c43de26265bf07cec7e972a736d4042c306e86928e06bc0c4`;
- activation, reactivation and installed `gh` readback without a development
  Composer autoloader: passed; and
- external fixture before-Core and after-Core load orders: passed.

An initial attempt to run both full PHPUnit processes concurrently produced
cross-worktree failures in the suite's shared fixed archive-preflight temporary
directory. The same unmodified gates passed when serialized. The collision is
test-process isolation evidence and was not treated as a pressure result.

## Temporary extraction rehearsal

The rehearsal used exact Core commit
`9b95cd63ab46e051c227bc31654b97c3225c0cc9`. A disposable, symlink-free,
package-shaped scratch tree contained:

- exact blob-verified copies of the seven files under `RAN/Booster/GitHub/`;
- exact blob-verified copies of all 16 files under `tests/Booster/GitHub/`; and
- the named `provider-api-9` fixture: the 63 exact files in the documented
  `RAN/RepositoryProvider/` contract namespace, exact
  `ProviderCapability.php`, and exact transitive `PackageSubdirectory.php`.

No other Core production or test source was copied. A public-contract-only
rehearsal bootstrap placed the scratch loaders and fail-closed guard ahead of
Core's Composer autoloader, asserted loader precedence and reflected class
origins, mapped the module, its tests and the named fixture, and failed closed on
every other `RAN` or `Tests` namespace. It contained no container,
storage, deployment, secrets, admin-controller, logging or WordPress runtime
stub.

Results:

- module autoloader before Provider API fixture: 193 tests / 1,156 assertions;
- Provider API fixture autoloader before module: 193 tests / 1,156 assertions;
- provider runtime edits: none;
- module test edits or reclassification during rehearsal: none;
- Core-private stubs or bootstrap: none; and
- scratch tree: removed after evidence capture.

The rehearsal therefore proves mechanical source/test ownership only. It does
not authorize a repository or package.

## Final implementation verification

The exact implementation commit `9b95cd63ab46e051c227bc31654b97c3225c0cc9`
passed:

- GitHub module: 193 tests / 1,156 assertions;
- full PHPUnit: 2,144 tests / 13,789 assertions;
- characterization: 35 checks;
- focused host boundary: 42 tests / 799 assertions;
- asset tests: 137;
- `composer check`, PHPCS, `pnpm check`, Prettier, ESLint and Stylelint;
- PHP 8.2 syntax checks for 580 source/test files;
- dependency, direct-construction, generic GitHub protocol/string,
  credential-pattern, secret-canary, public-seam, dependency-manifest and
  persistent-state scans;
- deterministic release build plus a separate clean verifier: 334 installed PHP
  files, locked updater `ran/wp-github-release-updater` `v2.0.0-beta.5` at
  `933eebd7cd00a9529477030e617bbdd893aab131`, ZIP SHA-256
  `0eb17c5e31da1038b4a726ddcf990ad153df2f1d91427402ca2cd1ea8270216f`;
- disposable WordPress 7.0.4 installation and activation;
- deactivation/reactivation and repeated installed `gh` readback without a
  development Composer autoloader; and
- external fixture before-Core and after-Core activation orders.

The disposable WordPress directory, database, extraction scratch tree and both
pressure worktrees were removed after their exact targets were revalidated.

## Exact deltas

Phase 4 implementation against the Phase 3 evidence commit, before this record:

- production: `+0/-0`;
- tests: `+69/-59`, moving two host assertions out of the portable module tree;
- documentation: `+0/-0`;
- workflow/tooling/package dependencies: `+0/-0`;
- production classes/types: no change; test types: `+1` Core-owned boundary test;
- public APIs, markers, hooks and compatibility shims: no change; and
- schemas, options, tables, files, credential records and persistent state: no
  change.

Across Phase 3 plus Phase 4 implementation, against the Phase 3 programme
baseline:

- production: `+13/-13`, terminology-only and zero net lines;
- tests: `+495/-167`;
- workflow: `+3/-0`;
- tooling: `+15/-0`;
- durable Phase 3 documentation: `+124/-0`;
- durable Phase 4 documentation: `+334/-0` (this evidence record); and
- production types, public seams, package dependencies and persistent state: no
  change.

## Independent hostile review

The independent non-implementing architecture/security review found no blocking
finding. It confirmed the current 50-type Provider API allowlist, one Core
composition owner, credential-read-free/local composition, corrected portable
rehearsal, intentional ownership of the remaining generic-Core GitHub-shaped
matches, and absence of a measured extraction benefit.

It recorded one non-blocking P2 hardening advisory: the dependency-boundary test
derives its positive allowlist from `use RAN\\...` declarations and names seven
forbidden Core namespaces, so a future fully qualified dependency in another
Core namespace could evade that lexical check. The current commit contains no
such reference, and the corrected extraction harness fails closed at runtime on
every `RAN` class outside the copied module and named Provider API fixture. A
future bounded token/AST inspection should harden the static test without
changing the present Phase 4 isolation result.

## Extraction decision

Separate source ownership has no measured maintenance or release benefit:

- there is no second host or independent maintainer;
- the module has no demonstrated release cadence distinct from Core;
- current changes are reviewed atomically with the only host and Provider API;
- internal isolation already supplies one owner, one composition seam, focused
  tests and attributable failures; and
- extraction would add at least one repository, Composer dependency, lock and
  provenance edge, release train, compatibility certification lane and
  upgrade/rollback/coexistence lifecycle without removing provider behavior from
  the shipped Core product.

The evidence therefore records **NO-GO for extraction**. The proven internal
module is the final product state. Phase 5 remains unauthorized and requires a
fresh explicit owner GO backed by a concrete measured benefit.
