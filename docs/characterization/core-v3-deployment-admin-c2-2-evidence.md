# Core V3 deployment-admin C2-2 evidence

Date: 2026-08-11

This records the source-only deployment recovery and activity result against
Core baseline `eccd6831b62fcdca7d0002f2ca906f2e4ae8ad1b`. It does not claim an
installed WordPress activation, release publication, or deployed-runtime
result.

## Ownership result

- `Dispatcher` retains the closed action router and request-envelope and
  request-method acquisition. It passes one normalized POST boolean to
  `DeploymentAdminController` and retains all unrelated routes.
- `DeploymentAdminController` owns the three deployment recovery POST actions
  and the failure-notice AJAX dismissal. `DeploymentCoordinator`,
  `DeploymentAttemptRepository`, and `BackgroundDeploymentFailureMonitor`
  remain the mutation and authoritative-readback owners.
- `DeploymentAdminPresenter` owns the failure notice, manual deployment result
  projection, activity list/detail/cursor state, package activity summaries,
  package-settings links, and rejected-admission projection.
- `Dashboard` retains menu and view composition plus its established message
  and failure logging entrypoints. `Booster` retains the existing admin,
  network-admin, asset, and AJAX hooks.
- The two internal background-failure notice types are deleted in the same
  slice. Their AJAX action, nonce action, user-meta fingerprint key, and native
  notice contract move unchanged to the replacement owners.

No attempt or rejected-admission SQL, state machine, schema, table identity,
option, user-meta identity, public hook, action, nonce, dependency, package
version, or release metadata moves or changes.

## Authority and outcome result

The explicit router rejects non-POST deployment actions before controller
authority work. Every accepted deployment action checks `manage_options`, then
its exact `ran-booster-<action>` nonce. Runner request and stopped-worker
reconciliation retain the stored `update_plugins` plus `update_themes` gate.
Needs-attention resolution loads the exact attempt and exact correlation before
deriving `update_plugins` or `update_themes` from the stored package type; the
submitted package type cannot select capability.

Successful resolution uses the repository's exact attempt/correlation update
and records the current actor. Reconciliation continues through
`DeploymentCoordinator::reconcileConfirmedStopped()` and runner requests
continue through `DeploymentCoordinator::requestRunner()`. Fixed Dashboard
success and redacted failure messages remain unchanged.

AJAX dismissal checks `manage_options` before nonce, current user, monitor, or
user-meta access. It writes the unchanged fingerprint key and requires exact
user-meta readback before reporting success. The native notice remains
request-deduplicated, administrator-only, escaped, per-user dismissible, and
linked to the exact activity/correlation and optional credential target.

Activity detail keeps malformed or partial attempt/reference identities in
detail mode rather than broadening to history. List cursors remain canonical
positive integers with the 50-row lookahead contract. Exact attempt and
correlation reads, later verified outcomes, package settings links, and
rejected-admission events continue through the existing repositories; storage
failures remain fail-closed.

## Frozen counters

The reviewed passive allowlist remains unchanged at 652 lines.

| Measure | C2-1 baseline | C2-2 candidate | Delta |
| --- | ---: | ---: | ---: |
| Shipped PHP | 46,938 | 46,793 | -145 |
| Passive PHP | 652 | 652 | 0 |
| Backend PHP | 46,286 | 46,141 | -145 |
| Named runtime types | 253 | 253 | 0 |

Type arithmetic is 253 minus the two deleted notice types plus the controller
and presenter. The replacements are 447 physical lines: 129 controller and
318 presenter. Counting every deployment-specific delegate line broadly adds
47 lines: Booster 6, Dashboard 26, Dispatcher 13, and uninstaller 2. The broad
replacement/delegate result is 494 lines against the 497-line cap.

## Source gates

- Focused deployment dispatch, failure notice, activity, package-operation,
  asset, and uninstall suite: 170 tests and 964 assertions pass. This includes
  an outer real-`Dispatcher` stopped-worker reconciliation journey and a real
  package-operation/rejected-admission repository/Dashboard projection journey.
- Full `composer check`: characterization 35; PHPUnit 2,044 tests and 12,554
  assertions; updater bootstrap smoke; release-uploader checks; and PHPCS pass.
- `pnpm check`: formatting, ESLint, Stylelint, and 137 asset tests pass.
- Changed PHP files pass syntax checks; `git diff --check` passes.

The candidate archive and installed WordPress activation checks require the
immutable source commit. The private execution closeout records those checks
after the source commit exists; this evidence does not substitute a working
tree or uncommitted archive for exact-commit proof.
