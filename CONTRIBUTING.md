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
and `RAN/Deployment/WordPressWorkerWakeup.php`.
Their owned methods use snake_case; PHP-provided enum methods such as `tryFrom()`
retain their native names. Enum cases, backed values and persisted representations
are unchanged. Callers on other types retain their current contracts until separately audited. This eleven-file scope does not
complete Core naming, condition or exception acceptance.

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
