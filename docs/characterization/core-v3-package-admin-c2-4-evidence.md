# Core V3 package-admin C2-4 evidence

Date: 2026-08-11

This records the source-only bulk-package mutation result against exact Core
baseline `cf37bbfd8120689723d3040dca5242447894dbb2`. It does not claim an
installed WordPress activation, candidate archive, release publication, or
deployed-runtime result.

## Ownership result

Before this slice, `Dispatcher` owned bulk POST admission, operation-derived
capability, action nonce, typed command construction, application-service
execution, safe failure mapping and redirect selection. `Dashboard` separately
owned the signed result tuple and every bulk notice projection.

The internal `PackageAdminController` now owns that complete request and signed
feedback family. It receives `BulkPackageActionService` through composition and
executes the existing typed command; the service, repositories,
`DeploymentCoordinator`, `WordPressUpdaterLock`, and WordPress plugin state
remain the application and authoritative-state owners. No new production type,
result, interface, hook, option, table field, dependency or public facade is
added.

`Dispatcher` retains only the explicit closed `bulk-plugin`/`bulk-theme` route,
request-method normalization, shared native/HTMX redirect transport and
termination. `Dashboard::bulkPackageRedirect()` remains public and compatible
as a bounded delegate. The package-list render path delegates signed notice
verification and projection to the same controller type and owner without
trusting redirect data as durable state. As in C2-3, Dispatcher and Dashboard
may hold separately composed stateless controller instances; request-local
messages remain Dashboard-owned.

The existing optional `BulkPackageActionService` parameter and its positional
order remain on `Dispatcher` solely as constructor-compatibility residue. The
controller does not receive the service through that parameter and Dispatcher
does not use it. The request-local container may therefore construct one
redundant stateless service on the admin path. Removing or reordering that
parameter is assigned to the final C3-2 base Dashboard/Dispatcher GO/NO-GO,
where direct and unknown constructor compatibility can be re-evaluated as one
explicit decision.

## Authority, bounds and outcomes

The controller rejects non-POST requests and any action outside the exact two
bulk routes before capability or nonce work. Plugin activation and deactivation
derive `activate_plugins` and `deactivate_plugins`; other plugin operations use
`update_plugins`; theme operations use `update_themes`. Each route then verifies
its unchanged action nonce before identifier parsing or application execution.
Denied capabilities and invalid nonces leave service, repository and WordPress
state untouched.

The live baseline bounds are preserved exactly:

- plugin activation and deactivation accept 200 identities and reject 201;
- queue and policy operations accept 20 identities and reject 21.

The stale 50-identity planning claim was characterization drift, not authority
to reduce or expand a live cap. Duplicate and malformed identities continue to
fail typed command construction. Queue/policy work remains bounded to 20 even
though the signed result format can safely represent the 200-identity
activation family.

Real outer Dispatcher journeys execute plugin and theme policy changes through
the injected controller and actual `BulkPackageActionService`. They prove exact
capability, nonce, repository application result, package policy consequence,
normalized signed destination and native/HTMX target parity. Focused service
outcomes additionally preserve live WordPress activation/deactivation
postconditions, repository snapshot/batch results, queue attempt targets,
provider/credential readiness, lock outcomes, Booster self-protection and stale
selection handling.

The complete result tuple remains signed across package type and every bounded
field. Tuple tampering, a forged nonce and cross-type nonce replay render no
notice. The public compatibility boundary also rejects a plugin activation or
deactivation result before it can be signed for the theme list. Success copy is
therefore available only on the matching package list and never substitutes for
repository, WordPress or attempt state.

## Frozen counters and explicit deviation

The reviewed passive allowlist remains unchanged at 652 lines.

| Measure | C2-3 baseline | C2-4 candidate | Delta |
| --- | ---: | ---: | ---: |
| Shipped PHP | 46,728 | 46,739 | +11 |
| Passive PHP | 652 | 652 | 0 |
| Backend PHP | 46,076 | 46,087 | +11 |
| Named runtime types | 253 | 253 | 0 |

Physical production diff arithmetic is 272 added and 261 deleted lines: net
+11. The directly affected production cluster is 2,719 lines at baseline and
2,730 lines in the candidate: Dashboard 1,548, Dispatcher 570 and controller
612. The controller grows from 364 to 612 lines, an added 248; counting the
retained Dashboard and Dispatcher bulk delegates broadly yields 639 lines.

This result misses the planned 80-line local deletion floor by 91 lines and the
179-line controller-addition cap by 69 lines. It is 185 lines above the map's
original cumulative shipped/backend ceiling because C2-3 had already landed 94
lines above its planned cumulative ceiling. The measured growth is retained
under the owner direction that correctness and cohesive ownership are primary;
no authority, exact-copy, signed-isolation, readback, public compatibility or
outer outcome evidence was deleted to manufacture LOC.

Smaller alternatives were rejected where they would make ownership or behavior
less honest: retaining signing and notices in Dashboard would leave the response
family split; passing the application service from Dispatcher on each call
would preserve the old adapter dependency; deleting Dispatcher’s compatibility
parameter would introduce an avoidable constructor risk; and collapsing exact
authority, failure or notice cases merely to meet a line cap would weaken the
evidence packet.

The exact programme arithmetic is now C1 -20, C2-1 -136, C2-2 -145, C2-3 -65
and C2-4 +11: 355 physical production lines removed. C3-1 must remove at least
445 more lines to reach the 800-line programme floor, or 485 more to retain the
original 46,254 shipped / 45,602 backend final target. Those are visible
programme targets, not automatic abandonment triggers. If clean,
correctness-preserving C3-1 work cannot reclaim them, the coordinator measures
the tradeoff and consults the owner before any abandonment or programme-scope
decision.

## Source gates

- Focused real bulk Dispatcher authority/transport suite: 48 tests and 235
  assertions pass.
- Focused bulk application-service suite: 20 tests and 77 assertions pass.
- Focused Dashboard signed-feedback and page suite: 63 tests and 301 assertions
  pass.
- Full `composer check`: characterization 35; PHPUnit 2,068 tests and 12,690
  assertions; updater bootstrap smoke; release-uploader checks; and PHPCS pass.
- `pnpm check`: formatting, ESLint, Stylelint, and 137 asset tests pass.
- Changed PHP files pass syntax checks; `git diff --check` passes.

Candidate archive and installed WordPress activation checks require an
immutable source commit and separate runtime authorization; this uncommitted
source evidence does not substitute for those later exact-commit gates.
