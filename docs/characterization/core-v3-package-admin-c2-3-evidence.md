# Core V3 package-admin C2-3 evidence

Date: 2026-08-11

This records the source-only single-package mutation result against Core
baseline `d13e6f2db9b7d358a074dfb67e70c829a2eb1ebb`. It does not claim an
installed WordPress activation, candidate archive, release publication, or
deployed-runtime result.

## Ownership result

- `Dispatcher` retains the closed ten-action router, request envelope, request
  method and shared native/HTMX redirect transport.
- The internal `PackageAdminController` owns POST admission, the exact
  capability and nonce sequence, `PackageMutationGuard`, stored-provider edit
  admission, repository-request resolution, application result mapping and
  signed package PRG feedback.
- `PackageOperationService`, `PackageRemovalService`,
  `DeploymentCoordinator`, `PluginRepository`, and `ThemeRepository` retain
  mutation and authoritative reread ownership.
- `Dashboard::postPackageOperation()` remains public and compatible. It is a
  bounded delegate so existing callers keep the same application entrypoint,
  request-local failure messages, and fixed signed outcome contract.
- The internal `PackageEditProviderGuard` is deleted in the same slice. No
  replacement public result, interface, hook, state or dependency is added.

Bootstrap resolves and binds one `Dashboard` object before runtime
initialization, so Dispatcher failures and the following page render use the
same request-local message owner. The controller is stateless; separately
autowired controller instances do not create a second message or request-global
boundary.

## Authority and outcome result

All ten actions reject non-POST requests before capability or nonce work. The
capability matrix remains exact: install plugin/theme; update plugin/theme for
edit, update and unlink; and the ordered later delete/activation capabilities
for destructive removal. Each action retains its own nonce. Plugin and theme
reinstall-after-save each require the separate matching update nonce before a
package identifier, stored provider, repository or mutation is reached.

`PackageMutationGuard` continues to reject multisite mutation and Core
self-update before repository resolution. Edits verify the stored package and
stored provider before the submitted provider is resolved. Missing controller
repositories or provider registry fail closed. Stale edit snapshots, explicit
delete confirmation, safe installed paths, active themes, dependants, shared
plugin directories and updater-lock ownership remain enforced by the existing
application services.

Successful install, edit and deployment outcomes use the service's fresh
repository reread, including when it is a distinct object from the pre-write
package. Reinstall uses the authoritative edited package as the update command.
Unlink and delete retain their verified service outcomes. Failures remain on
the same request with redacted logging context. A terminal deployment failure
that carries a correlation field remains in the bounded deployment/manual
failure family even when the presenter rejects a malformed identity; it cannot
fall through to package-removal copy. The regression enters through public
`Dashboard::postPackageOperation()` with a real controller/service/coordinator
flow and asserts one fixed manual failure from the malformed terminal result.

Success feedback remains signed across package type, operation and identifier;
a nonce cannot be rebound across any of those fields. Native and HTMX requests
receive the same signed destination, while Dispatcher retains the established
transport choice. Package-list filters and create/settings return targets
remain normalized and unchanged.

## Frozen counters and explicit deviation

The reviewed passive allowlist remains unchanged at 652 lines.

| Measure | C2-2 baseline | C2-3 candidate | Delta |
| --- | ---: | ---: | ---: |
| Shipped PHP | 46,793 | 46,728 | -65 |
| Passive PHP | 652 | 652 | 0 |
| Backend PHP | 46,141 | 46,076 | -65 |
| Named runtime types | 253 | 253 | 0 |

Type arithmetic is 253 minus `PackageEditProviderGuard` plus
`PackageAdminController`. The physically affected production cluster is 2,784
lines at baseline and 2,719 lines in the candidate: Dashboard 1,720,
Dispatcher 635, and controller 364. The controller is 364 physical lines.
Counting every direct controller delegate line broadly adds 16 Dashboard lines
and 10 Dispatcher lines, for 390 lines.

This is physically net-negative and cohesive, but it does not meet the map's
original 180-line deletion floor or 264-line broad replacement cap. It is 115
lines short of the local deletion floor, 94 lines above the cumulative
shipped/backend ceiling, and 126 lines above the broad cap. The implementation
proceeds under the owner clarification that
scoped line growth required for correctness evidence is acceptable while
whole-track simplification remains the goal. No authority, safety, readback or
outcome evidence was deleted to manufacture a local counter pass.

The exact programme result is now C1 -20, C2-1 -136, C2-2 -145, and C2-3 -65:
366 physical production lines removed. C2-4 and C3-1 must remove at least 434
more lines to reach the 800-line programme floor, or 474 more to retain the
original 46,254 shipped / 45,602 backend final target. Their original 80-line
and 300-line allocations total only 380. The 434-line remainder stays visible,
but it is not an automatic stop or NO-GO for a later packet. Correctness and
cohesion stay primary, and sound work must not be distorted to chase LOC. If
correctness-preserving C2-4 and C3-1 work cannot close the resulting 54-line
programme-floor gap (or 94-line original-target gap), the coordinator consults
the owner with the measured tradeoff before any abandonment, scope, or
programme-exit decision; scoped correctness growth cannot become a quieter
programme exit.

## Source gates

- Focused package dispatch and authority suite: 42 tests and 161 assertions
  pass.
- Focused package operation and removal suite: 83 tests and 408 assertions
  pass.
- Focused Dashboard routing suite: 60 tests and 298 assertions pass.
- Full `composer check`: characterization 35; PHPUnit 2,054 tests and 12,591
  assertions; updater bootstrap smoke; release-uploader checks; and PHPCS pass.
- `pnpm check`: formatting, ESLint, Stylelint, and 137 asset tests pass.
- Changed PHP files pass syntax checks; `git diff --check` passes.

Candidate archive and installed WordPress activation checks require an
immutable source commit and separate runtime authorization. This uncommitted
source evidence does not substitute for those later exact-commit gates.
