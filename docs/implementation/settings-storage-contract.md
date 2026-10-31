# Settings and storage source contract

Baseline: live fetched Core main `c0f127a29ab5e136986169b4b5a99f6f5d9bc546`, 31 October 2026. Source-only implementation authorized for #461/#460/#569. No retained-site operation, merge, deployment, release or Workbench publication.

## Ownership and invariants

Settings owns Dashboard/Dispatcher/Booster/BoosterServiceProvider shared routing and bootstrap integration. Overview execution chat owns presenter/views/tests and sends integration hunks; Troubleshooting execution chat owns views/retention/collectors/output/tests and sends integration hunks. Both confirmed this boundary before production edits. No generated shell edits.

One standalone `ran-booster-settings` submenu after Extensions, outside horizontal navigation. General and Storage remain separate forms/actions. General Save cannot invoke storage or remote discovery. Storage keeps existing action names, purpose nonces, manage_options + activate_plugins, typed reset, fresh backend rechecks and local private failure results. Canonical destination `admin.php?page=ran-booster-settings#ran-booster-storage`. Stale Overview storage POST rejects without mutation or replay; old heading anchor remains a GET handoff. Existing Overview operations remain until the complete cutover.

Settings alone obtains authenticated status once per request and bounded authorized recovery once. Protected POST result overrides captured GET status. Overview gets only status/reason_code/message/settings_url from existing permitted status, no recovery or private payload. Installation/report obtain configuration facts or explicit unknown, never invoke authenticated status/recovery just to fill a summary. Inspection failure is pathless and cannot suppress General.

One existing bootstrap classifier owns Settings GET and narrowly routed report POST deferral. Storage/diagnostic mutation POSTs preserve initialization. Credential expiry purge and lazy expired capture cleanup remain; no whole-request immutability claim. GET Settings defers schema upgrade, package-link enumeration and verify_and_secure. Existing status authentication can take the file lock; it does not provision, adopt or reset.

Preferences: defined code > sparse saved > default > runtime constraints; preserve explicit false and invalid code. Save preserves dormant locked values; confirmed GitHub management alone clears an existing saved hide request. Atomic option CAS plus package-management transaction must serialize Save/clear; stale form cannot resurrect hiding after management/removal. No API registry, provider tabs, new storage backend, multisite support or public extension seam.

Updater: exact pinned beta.15/protocol-7 supports disabled target, late candidate/final-install descriptor admission and cached response filtering. Registration reads fresh policy; the descriptor callback does not run before feed discovery. Check is separate POST (manage_options + update_plugins), atomic claim/cooldown, native wp_update_plugins scope, bounded current evidence, no installation. Native Update owns installation. A refresh/global last_checked is not successful Core-check evidence; exact check-completed event is. Five-minute cooldown; two-minute claim expiry (existing HTTP timeout 10s, bounded check collection; expiry allows recovery but old claim cannot finalize newer claim).

## Pre-edit projected production cost

Exact engineering projections, not allowances or measured implementation. Reconcile actual production/test/docs deltas per unit; record projection revisions before extending scope.

| Unit/file | Projected added/deleted lines |
| --- | ---: |
| Foundation RAN/Booster.php | +3/-0 |
| Foundation RAN/Dashboard.php | +8/-0 |
| Foundation views/settings.php | +24/-0 |
| Storage RAN/Dashboard.php | +85/-24 |
| Storage RAN/Dispatcher.php | +29/-3 |
| Storage RAN/Booster.php | +8/-4 |
| Storage views/settings-storage.php (moved markup/helpers) | +243/-0 |
| Storage views/onboarding.php | +9/-240 |
| Storage presenter/remediation/docs-view/uninstall wording | +8/-8 |
| General RAN/Settings/GeneralPreferenceStore.php | +470/-0 |
| General ActivityStore/PackageArtifactLimit/CoreSelfUpdatePolicy | +24/-4 |
| General fixed visibility owner/management transaction seam | +256/-0 (GitHubVisibility +145/-0; AbstractPackageRepository +36/-0; GeneralPreferenceStore serialized preference lock/clear +75/-0; revised +16/-0 before post-commit persistent-cache invalidation) |
| General fixed check claim/evidence owner | +282/-0 |
| General existing updater adapter/status/bootstrap | +65/-15 |
| General Core registration-failure fallback (ran-booster.php; focused tests) | +55/-0 production; +95/-0 tests |
| General Core registration-failure installed admission smoke | +100/-0 tests |
| General Settings view/Save/actions/unsaved enhancement | +240/-8 |
| General exact uninstall option allowlist | +2/-0 |

Concrete types: foundation/storage zero; General one fixed internal preference owner, at most one fixed visibility decision owner and one check claim/evidence owner. No DTO/interface/registry/facade. Persistent delta: one nonsecret nonautoload General option with five keys; one current check/claim option, no history/table/cron. No storage state delta. Public API delta zero; internal fixed Settings entry/projection only.

Rejected smaller alternatives: a link cannot replace forms/results; moving only markup loses failures/assets/verification; duplicating forms creates conflicting authority; skipping self-target registration cannot fence cached offers/tokens; unconditional preference updates lose concurrent writes; post-commit GitHub clearing leaves a resurrection race. Reuse shell/presenter/Dispatcher/option-CAS boundaries.

### #460 visibility-management serialization invariant

The fixed `RAN\\Admin\\GitHubVisibility` owner may inspect only local Core facts
and never performs provider construction, remote discovery, or secret access. Its
visibility decision remains fail-visible for malformed, recovery, or unknown
facts. A confirmed `gh` management record must clear an existing saved
`hide_github: true` request before the package-management transaction commits;
an absent preference option or an explicit false is a no-op and must not seed
the option.

The clear is not sufficient by itself. General Save must first acquire a
`SELECT … FOR UPDATE` lock across the exact `provider = 'gh'` range of the
existing `provider_identity (provider, provider_repository_id)` package-table
index, then take the General-preference option lock, reread its snapshot, check
admission, and conditionally save. Every `gh` store/adopt/release-adopt writer
already takes a serializable transaction and locks the exact `gh` identity
through `RepositorySourceGuard` before it inserts or updates; it clears the
preference after verified management-row persistence and before `COMMIT`.
Thus either a Save completes first and the management transaction clears its
true request, or management completes first and the stale Save snapshot is
rejected. The missing-option gap is included in the range lock, so an
initially absent/false preference cannot race into a later automatic re-hide.
No package removal, failed/preview/uncertain write, passive GET, or provider
selection transition invokes the clear.

## Verification boundary

Required composer check, pnpm check, parser and affected storage/updater WordPress proofs. Source, artifact and installed/browser evidence remain separate. Each opened PR gets independent exact-base/head review. Passing tests grant no merge or installed acceptance.

### #460 race-proof projection

Before any disposable database fixture is selected or mutated, the projected
delta is test-only: `tests/WordPress/general-settings-race.php` and its
non-mutating shell runner, approximately +260 test lines and +0 production
lines. The proof must receive an explicitly supplied disposable WordPress path,
database identity and marker; it must refuse every other target. It will prove
the stale-General-Save versus confirmed-`gh` management race for initially
absent, false and true preferences against MySQL row/range locks. It does not
authorize creating, resetting or mutating the Overview fixture.

Foundation measured delta: production +30/-0 (Booster +2, Dashboard +8, fixed view +20); tests +3/-0. Focused Extensions route suite: 22 tests/144 assertions; touched-file standards pass. Existing Overview forms remain authoritative in this unit. Documentation is this contract only; no generated assets/types/state added.

General integration projection revised before adding page-local enhancements: +35 JS, +35 scoped CSS, +10 loader/view lines. The script only reports unsaved changes; native forms remain authoritative. Settings GET and exact preference-only Save POST defer schema/sidecar hardening in the existing classifier; capture/security expiry cleanup is retained. The report owner approved exact report-action deferral even on an invalid route, with Dispatcher retaining strict route/authority checks.

## Measured implementation cost against the frozen baseline

| Production file | Added | Deleted |
| --- | ---: | ---: |
| `RAN/Activity/ActivityStore.php` | 6 | 3 |
| `RAN/Admin/CredentialExpiryNotice.php` | 1 | 1 |
| `RAN/Admin/DeploymentOutcomeMessage.php` | 3 | 3 |
| `RAN/Admin/GitHubVisibility.php` | 183 | 0 |
| `RAN/Admin/ProviderSettingsPresenter.php` | 1 | 1 |
| `RAN/Admin/SecretsStorageSetupPresenter.php` | 1 | 1 |
| `RAN/Booster.php` | 19 | 2 |
| `RAN/BoosterServiceProvider.php` | 3 | 0 |
| `RAN/Dashboard.php` | 230 | 29 |
| `RAN/Dispatcher.php` | 66 | 5 |
| `RAN/PackageArtifactLimit.php` | 1 | 1 |
| `RAN/Secrets/SecretsStorageProvisioner.php` | 2 | 2 |
| `RAN/Settings/GeneralPreferenceStore.php` | 473 | 0 |
| `RAN/Storage/AbstractPackageRepository.php` | 28 | 0 |
| `RAN/Troubleshooting/CoreSelfUpdateStatus.php` | 47 | 6 |
| `RAN/Uninstall/LocalDataRemover.php` | 3 | 0 |
| `RAN/WordPress/CoreSelfUpdateCheck.php` | 282 | 0 |
| `RAN/WordPress/CoreSelfUpdatePolicy.php` | 5 | 5 |
| `RAN/WordPress/ManagedReleaseUpdaterRegistrar.php` | 1 | 1 |
| `assets/ran-booster-settings.js` | 26 | 0 |
| `assets/ran-booster/65-package-settings.css` | 28 | 0 |
| `ran-booster.php` | 83 | 26 |
| `uninstall.php` | 1 | 1 |
| `views/documentation.php` | 2 | 2 |
| `views/onboarding.php` | 5 | 218 |
| `views/settings-storage.php` | 229 | 0 |
| `views/settings.php` | 181 | 0 |

Category totals (documentation measured before this table refresh):

- Production: +1910/-307.
- Tests: +1984/-38.
- Generated catalogues/fixtures: +581/-306.
- Documentation: +328/-21.

Three new internal concrete owners: GeneralPreferenceStore, GitHubVisibility and
CoreSelfUpdateCheck. Zero new extension APIs, provider contracts, schemas, tables,
cron jobs or storage-backend types. Two exact nonautoloaded option names are
added to verified uninstall: General's five-key map and the current update-check
claim/evidence record. The existing code overlay gains only the boolean hide
request constant. Existing WordPress hook subscriptions implement the necessary
exact-Core registration-failure fence; they create no new plugin event API.

Executed source evidence includes policy/CAS/locked/stale/cache/route/storage
and exact-updater checks. Synthetic disposable evidence includes activation,
database/storage smoke, six MySQL race schedules, failure-registration native
hooks, maintained native updater proof and desktop/mobile JavaScript/native
Settings Save. These do not establish a released artifact or retained-site
operator acceptance. A real newer-Core native install and retained-site storage
recovery remain separate acceptance work; no merge/deployment/release is implied.


## Exact-PR review remediation and remaining hold

Initial PR #583 tuple: c0f127a29ab5e136986169b4b5a99f6f5d9bc546 →
e08619d4dd37704f539512659052c0b90d1dc842. Independent review reproduced:

- Missing hide-constant projection lock: fixed with presence/type/source/lock
  tests, preserving dormant values and the lifecycle-clear exception.
- Precommit cache eviction: removed; four confirmed-management owners invalidate
  only after successful commit. A stale-cache repopulation regression and failed
  commit case cover the ordering; navigation reads a direct fresh hide snapshot.
- Late native discovery admission: new registration uses a fresh indexed option
  read, but the pinned runtime still begins list/inspection before descriptor
  admission. Explicit Check is prechecked; offers/installations stay fenced.
  The stronger automatic pre-discovery requirement requires an upstream pinned-runtime
  change or a separately reviewed dependency seam; this hold is not acceptance of
  an owner limitation. No workaround callbacks,
  transport interception, dependency repin or release is introduced.
- Stale runtime-only documentation: corrected to actual beta.15/Protocol-7 and
  registered-disabled behavior.

The new remediation adds no types, options, extension APIs or storage operation.
An indexed preference read is performed at Core target registration (when code
has not supplied the policy) rather than evicting the global options cache.

The exact uninstall allowlist also removes the current Core update-check
claim/evidence option; an undeletable owned option fails closed while unrelated
options remain preserved.

Released-updater readback on 31 October 2026 confirms beta.15 remains the latest published prerelease; no released replacement seam was available to repin.

## 31 October composition review: missing uninstall evidence option

The earlier summary incorrectly claimed both new options were allowlisted.
Source inspection found only General present. Before this correction, the
projected delta is exactly +1/-0 production line in the existing uninstall
allowlist, zero new types/APIs/state, and exactly +22 test lines including fixture imports.
The cleanup regression seeds both options, verifies unrelated state survives,
and requires an undeletable check option to abort rather than claim cleanup.
This source correction does not authorize a retained-site uninstall.

## Queued registration and late runtime loss

Before the next production edit, the measured proposal changes only the
existing Core fallback in `ran-booster.php`: +9/-8 production lines, no new
types, public seams, options, tables or dependencies. Registration occurs at
`plugins_loaded`; activation occurs later at `after_setup_theme`. A queued
registration can return true and then become inactive, so immediate failure is
not the complete admission boundary. Every existing fallback hook must read
current exact-target activity when invoked. Active target and unrelated
operations retain their incoming object identity. The existing typed status
adapter contains malformed or throwing runtime diagnostics.

The regression uses the real public registrar and scheduled activation callback,
an owned temporary regular plugin fixture, and the actual production fallback
block. It covers queued activation failure, active pass-through and later broker
ownership loss. It adds exactly 351 test/shim lines and no installed site
or unpublished updater fixture. The former conditional fallback is the red
control. This addresses the queued-failure fence independently of the still-held
pre-discovery guard-only dependency prerequisite.

The final queued-fence hunk measures +12/-11 after required assignment
alignment; its cumulative main-relative bootstrap delta is +83/-26 because
most changed lines were already introduced by this Settings feature.
