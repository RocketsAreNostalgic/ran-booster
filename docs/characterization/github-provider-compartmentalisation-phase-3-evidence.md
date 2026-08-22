# GitHub provider compartmentalisation Phase 3 evidence

Date: 2026-08-13

## Scope and immutable inputs

- Baseline Core commit: `6570293ce97b4a39efa51b68f40a18638f6952ff`.
- Phase 3 implementation commit: `842250d42d6ac67756f1f478ce6d068ba370a859`.
- Provider API remains exactly `9`.
- This phase did not create an extraction repository, publish a release, merge,
  deploy, or change credentials, webhook/archive/mutation custody, persistent
  state, or provider behavior.

The audit found one incomplete ownership detail from the preceding phase:
generic Core credential surfaces still used GitHub-shaped token and PAT
vocabulary. The correction is deliberately terminology-only. GitHub PAT names,
prefixes, permission guidance, and Assisted Hooks vocabulary remain owned by
the bundled GitHub module and intentional product documentation.

## Ownership and attribution

- `tests/Booster/GitHub/` owns the GitHub protocol, credential policy,
  browser/client, diagnostics, webhook policy and normalization, aggregate
  metadata, and module-only shims.
- The generic admin tests no longer read `GitHubProvider.php` or use a GitHub PAT
  mismatch as a generic dispatcher fixture. GitHub metadata assertions now live
  in `GitHubProviderMetadataTest`.
- `ModuleDependencyBoundaryTest` continues to enforce the production module
  dependency allowlist, retains `BoosterServiceProvider.php` as the single Core
  composition reference, and now enumerates the Core tests that intentionally
  own host-integration use of GitHub concretes.
- Core retains registry and store issuance, atomic policy publication, generic
  admin, persistence, portability, webhook routing, and deployment integration.
- `ProviderTrustConformanceTest` now contains only generic delivery-evidence
  adapter behavior. The designated GitHub construction and registration
  boundary is `VendorConformanceTest`.

The separate `phpunit.github-module.xml` lane uses
`tests/Booster/GitHub/bootstrap.php`. That bootstrap maps only the GitHub module,
Provider API namespace, module tests, the Provider API capability enum, and the
one transitive package-slug value helper used by `RepositoryDescriptor`. It
throws if another Core or test namespace is requested. In a separate process,
the vendor-conformance test proves that neither `BoosterServiceProvider` nor
`CoreContainer` is loaded and registers `gh` only with
`ProviderRegistry::registerWithCredentialStore()`, the documented Provider API
9 path.

## Installed release proof

The Quality workflow now runs a named readback immediately after installing and
reconciling the shared runtime ZIP. The readback proves:

- the active runtime exposes Provider API 9 and a sealed provider registry;
- `gh` metadata, credential kinds, webhook scopes, locator guidance, and admin
  navigation match the bundled contract;
- every optional GitHub capability resolves through the registry;
- credential and webhook policies publish the expected provider code, constant,
  retained headers, and signature header;
- the provider and both policies load from the installed
  `RAN/Booster/GitHub/` tree;
- the installed ZIP has no Composer autoloader and the development Composer
  autoloader is not included.

Local archive reproduction used WordPress 7.0.4, PHP 8.2.29, and a disposable
database and installation. The verified ZIP installed and activated as
`1.0.0-beta.15`; its embedded release marker points to the exact Phase 3 commit.
The explicit GitHub readback passed, as did the existing external provider smoke
with the fixture activated both before and after Core. The temporary database
and WordPress directory were removed after the proof; the live Local site and
database were not changed.

Archive: `build/ran-booster-1.0.0-beta.15.zip`

SHA-256: `70629bf16bd6a89081c1fecfaa7a99e4ab581eece71290aa9bd42fb9e3f2f091`

The build and independent verifier both confirmed 334 linted PHP files and the
locked updater `ran/wp-github-release-updater` `v2.0.0-beta.5` at
`933eebd7cd00a9529477030e617bbdd893aab131`.

## Verification results

- GitHub module lane: 195 tests, 1,678 assertions.
- Full PHPUnit: 2,144 tests, 13,789 assertions.
- Characterization checks: 35.
- Asset tests: 137.
- `composer check`: passed, including PHP lint, PHPCS, and updater bootstrap
  smoke.
- `pnpm check`: passed, including Prettier, ESLint, Stylelint, and asset tests.
- Release build and independent release verification: passed.
- Installed WordPress activation, exact release readback, and explicit `gh`
  metadata/capability/policy/navigation readback: passed.
- External fixture before-Core and after-Core activation orders: both passed.
- Authenticated local admin journeys: Overview, GitHub provider, credential
  management modal, Troubleshooting, and Documentation were inspected. Generic
  copy was provider-neutral; GitHub PAT labels remained provider-owned. The
  modal was opened without submitting or mutating state.
- Dependency, direct-construction, GitHub protocol, credential-pattern, and
  secret-canary scans found no new cross-boundary dependency or exposed secret.
- Independent hostile review reported no blocking or advisory findings. It
  identified only acceptable harness residuals: PHPUnit is launched from the
  development Composer environment but the prepended module autoloader fails
  closed on unrelated Core/test classes; the installed readback driver and
  container fixture are source-owned while the reflected runtime objects are
  proved to originate in the installed ZIP.

## Exact delta

Against the baseline commit:

- production: `+13/-13`, all provider-neutral user-facing terminology in one
  controller, one JavaScript view fragment, and four PHP views; zero net lines
  and no control-flow or data-flow change;
- tests: `+426/-108`;
- workflow: `+3/-0`;
- tooling: `+15/-0` for the bounded PHPUnit configuration and Composer lane;
- durable documentation before this evidence record: `+0/-0`;
- production classes/types, dependencies, public APIs, compatibility shims,
  loaders, containers/facades, schemas, options, tables, hooks, and persistent
  state: no change.

Phase 3 proves the host/module boundary only. Phase 4 isolation closeout and the
extraction decision remain separately authorized work; extraction remains
NO-GO.
