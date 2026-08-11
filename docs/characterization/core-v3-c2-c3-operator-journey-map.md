# Core V3 C2-C3 operator-journey map

Date: 2026-08-11

**Characterized source object:**
`edbff7e2712edde80165e32f02e58a3c370ddfec`

The completed provider-profile source result is recorded in
`core-v3-provider-profile-c2-1-evidence.md`.
The completed deployment-admin source result is recorded in
`core-v3-deployment-admin-c2-2-evidence.md`.
The completed single-package mutation source result is recorded in
`core-v3-package-admin-c2-3-evidence.md`.
The completed bulk-package mutation source result is recorded in
`core-v3-package-admin-c2-4-evidence.md`.
The completed package-page source result is recorded in
`core-v3-package-page-c3-1-evidence.md`.

## Landed programme arithmetic after C3-1

The source results now remove 410 physical production PHP lines from the Phase
0 programme: C1 removes 20, C2-1 removes 136, C2-2 removes 145, C2-3 removes
65, the correctness-evidenced C2-4 source result adds 11, and C3-1 removes 55.
A visible 390 lines remain to the 800-line physical-deletion floor, and 430
remain to retain the original final target of 46,254 shipped PHP and 45,602
backend PHP.

C3-1 missed its original local allocation by 245 lines, but the owner accepted
its measured projection-only boundary rather than distorting correctness to
manufacture LOC. The 390/430-line remainders are visible programme targets, not
an automatic packet stop or NO-GO. C3-2 now performs the read-only base
re-inventory and presents any remaining measured tradeoff to the owner before
an abandonment, scope or programme-exit decision. The programme must not be
reported complete by borrowing presentation credit or silently lowering its
exit target.

**Decision:** C2 and C3 may proceed only as the vertical packets below. This
inventory changes no production PHP, public API, hook, action name, capability,
nonce, persistent state, dependency, runtime type, release or installed
WordPress state. It does not authorize a `Dispatcher` or `Dashboard` layer
rewrite.

## Current boundary and counters

WordPress calls `Dispatcher::dispatchPostRequests()` from `admin_init`.
`Dashboard` remains the registered menu callback for the root page and the
plugin/theme create and management pages. Checked-out sibling production code
does not construct, subclass or implement either class, but unknown external
consumers remain possible; their existing public methods therefore remain
compatibility entrypoints during C2-C3.

The C1 candidate is the C2-C3 source baseline:

| Measure                     |                                                       Value |
| --------------------------- | ----------------------------------------------------------: |
| Shipped PHP                 |                                                47,074 lines |
| Reviewed passive PHP        |                                                   652 lines |
| Backend PHP                 |                                                46,422 lines |
| Named shipped runtime types |                                                         253 |
| `Dashboard.php`             | 2,277 lines / 16 public, 1 protected and 60 private methods |
| `Dispatcher.php`            |  1,473 lines / 2 public, 6 protected and 20 private methods |
| Two-class concentration     |                                         3,750 backend lines |

The frozen Phase 0 admin reference is 6,165 backend lines. Its 15% contraction
gate requires a final admin-programme result of at most 5,240 backend lines.
C1 physically removed 20 lines, so the complete Core physical-deletion floor
still requires at least 780 further backend lines to reach the accepted
800-line programme minimum. The packets below allocate that remaining floor;
presentation reclassification, test deletion and documentation deletion earn
no credit.

## Stable choreography

The request envelope is the posted `ran_booster` array. The explicit router
reads only its scalar action before selecting a closed branch. Unknown actions
return without capability checks, nonce checks, sensitive reads or mutation.
Routes that state an explicit POST check below perform it before capability or
nonce handling; the remaining branches rely on WordPress-populated `$_POST`
and then check capability before nonce.

Three internal action-family owners are the maximum for C2. Their exact landing
type arithmetic is fixed now rather than financed by temporary types:

1. proposed `ProviderProfileAdminController` replaces the undocumented internal
   `CoreProviderProfileInteraction` type and the complete provider-profile
   request branches: 253 - 1 + 1 = 253 types;
2. proposed `DeploymentAdminController` replaces
   `BackgroundDeploymentFailureNoticeController`, while a paired
   `DeploymentAdminPresenter` replaces `BackgroundDeploymentFailureNotice` and
   the Dashboard activity projection: 253 - 2 + 2 = 253 types; and
3. proposed `PackageAdminController` replaces `PackageEditProviderGuard`, and a
   later `PackagePagePresenter` replaces `PackageViewConfig`: each landing is
   independently 253 - 1 + 1 = 253 types.

They are not registries, public facades, command handlers or transport-neutral
operations. `Dispatcher` keeps the explicit switch, superglobal acquisition,
redirects and termination while delegating to these fixed controllers; each
controller owns its family's capability/nonce ordering and bounded browser
response. Existing application owners keep mutation. `Dashboard` keeps menu
compatibility, tab resolution and final view composition. If a named legacy
type proves public or cannot be deleted in the same source commit, its packet is
NO-GO; a temporary owner is not an acceptable substitute.

## Provider-profile journeys

| Action journey                                                                 | Capability and nonce order                                                                                      | Application owner, mutation and bounded result                                                                                                                                               | Authoritative readback and rendering                                                                                                                                                                                                                            | Prohibited effects                                                                                                                                | Concentration and disposition                                                                                                  |
| ------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| Save or replace repository access profile (`save-access-profile`)              | `manage_options`, then `ran-booster-save-secrets`; provider, ID, fields, expiry and secret are parsed afterward | `WordPressUpdaterLock` encloses `SecretsFile::saveCredential()` and expiry-observation updates; result is fixed success or allowlisted validation/generic failure                            | Exact `SecretsFile::credentialProfiles()` record is compared before success; C1 rebuilds the provider region from sidecar, usage and expiry state; native response retains the Dashboard notice, enhanced response uses the signed provider-profile interaction | No secret read or write before authority; no submitted secret in errors/logs/redirects; no success before saved-record comparison; no lock bypass | `manageCredentialProfiles()` combines four actions and most provider policy in 316 lines. **Propose provider-profile packet.** |
| Delete repository access profile (`delete-access-profile`)                     | Same capability and nonce                                                                                       | Under `WordPressUpdaterLock`, verify mutable file-owned profile, fail when managed-package usage is nonzero, delete it, clear the public-lookup default and expiry observation if applicable | Absence from `credentialProfiles()` is required before success; provider region is rebuilt                                                                                                                                                                      | No deletion while used, immutable or non-file-owned; no option/expiry cleanup before verified sidecar deletion                                    | Same proposed packet                                                                                                           |
| Save or replace Push-to-Deploy profile (`save-webhook-profile`)                | Same capability and nonce                                                                                       | Provider metadata and provider-owned webhook normalizer validate scope/target; `ManagedPackageWebhookAuthorityResolver` derives authority; `SecretsFile::saveWebhook()` mutates              | Exact `webhookProfiles()` record is compared for label, scope, target, authority, origin and configured state; provider region is rebuilt                                                                                                                       | No plaintext secret projection; no caller-selected authority ID; no success on partial record                                                     | Same proposed packet                                                                                                           |
| Delete Push-to-Deploy profile (`delete-webhook-profile`)                       | Same capability and nonce                                                                                       | Verify mutable file-owned profile, call `SecretsFile::deleteWebhook()` and return fixed success/failure                                                                                      | Absence from `webhookProfiles()` is required before success                                                                                                                                                                                                     | No immutable/non-file deletion and no secret disclosure                                                                                           | Same proposed packet                                                                                                           |
| Validate saved access profile (`validate-access-profile`)                      | `manage_options`, then `ran-booster-save-secrets`                                                               | Provider-owned `CredentialValidator` returns a bounded result; a valid result may update `CredentialExpiryObservationStore` and the sidecar provider-expiry date                             | Provider result plus fresh observation/sidecar state; native notice or action-local HTMX error/success fragment                                                                                                                                                 | No validation before authority; no token output; invalid validation does not claim success                                                        | Shares provider parsing, safe-error and enhanced-response choreography. **Include in provider-profile packet.**                |
| Change default public repository lookup profile (`save-public-lookup-profile`) | `manage_options`, then dedicated `ran-booster-save-public-lookup-profile` nonce                                 | Verify provider browse capability and exact configured profile, then write `PublicRepositoryLookupProfileStore`                                                                              | Fresh option-backed selection rendered by `renderPublicLookupProfileRegion()`                                                                                                                                                                                   | No arbitrary profile ID, unavailable provider default or success event on failure                                                                 | Same action-family owner but distinct nonce. **Include in provider-profile packet without merging nonce scopes.**              |
| Render provider pages and managed repositories                                 | Read-only `Dashboard::getIndex()` route; no mutation nonce                                                      | C1 owners acquire and project provider/profile/repository state                                                                                                                              | Complete C1 semantic provider page and stable repository hooks                                                                                                                                                                                                  | No mutation, secret material or new facade                                                                                                        | **Retain C1 ownership.** C2 may consume its rendering entrypoints but may not reopen the C1 read model.                        |
| Create, adopt or reset secure storage                                          | Explicit POST, `manage_options`, `activate_plugins`, then the action-specific create/adopt/reset nonce          | `SecretsStorageProvisioner` performs physical setup/recovery/reset and returns `SecretsStorageProvisioningResult`                                                                            | Next-request `status()`/recovery state after success; protected same-request onboarding result on failure                                                                                                                                                       | No path/token in redirect or global notice; no physical custody change under an admin-cohesion packet                                             | **Defer to C4.** These branches are security characterization, not provider-profile deletion credit.                           |

### Provider-profile duplication

`Dispatcher` repeats provider parsing, profile-ID validation, safe-error mapping,
Dashboard message calls and native/enhanced branching across the profile,
validation and public-lookup methods. `CoreAdminInteractionFacade` already owns
the signed enhanced provider-profile response. The proposed
`ProviderProfileAdminController` replaces the undocumented, checked-out-
consumer-free `CoreProviderProfileInteraction` interface as the one internal
provider-profile request/response owner; `CoreAdminInteractionFacade` retains
its published Admin Interaction API facade and becomes its concrete enhanced
transport collaborator. `SecretsFile`, provider capabilities, the updater lock,
expiry store and public-lookup store retain their state transitions. The
controller may not absorb those application invariants or create a credential
DTO family. Discovery of an external consumer of the legacy internal interface
stops this packet rather than increasing the type count.

## Deployment and diagnostics journeys

| Action journey                                                                                                 | Capability and nonce order                                                                                                                                                        | Application owner, mutation and bounded result                                                                                                                                                                                                  | Authoritative readback and rendering                                                                                                                                                                                                                                                                                              | Prohibited effects                                                                                                                                                                        | Concentration and disposition                                                                                                                                                                                                            |
| -------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Run provider diagnostics (`run-troubleshooting`)                                                               | `manage_options`, `install_plugins`, `update_plugins`, `install_themes`, `update_themes`, then `ran-booster-run-troubleshooting`                                                  | `TroubleshootingService::diagnose()` receives bounded provider, credential ID and normalized repository locator; it returns same-request diagnostic rows                                                                                        | Same-request `Dashboard` payload and complete diagnostics region; HTMX success event only when every result passes and the result is not partial                                                                                                                                                                                  | No persistent mutation, no invalid locator, no success toast for warning/partial/failure                                                                                                  | Dispatcher parses and Dashboard stores/maps one request-local result. **Defer:** no coherent zero-type replacement owner is evidenced.                                                                                                   |
| Start/stop/delete temporary debug capture (`manage-debug-capture`)                                             | Explicit POST, `manage_options`, then `ran-booster-manage-debug-capture`                                                                                                          | `TemporaryDebugCapture::{start,stop,delete}` owns the bounded file lifecycle; result is fixed success or generic failure                                                                                                                        | Fresh `TemporaryDebugCapture::snapshot()` projected by `debugCapturePayload()`; start/stop may return the bounded HTMX region, delete uses PRG                                                                                                                                                                                    | No deployment interruption, arbitrary log path, exception text or success event on failure                                                                                                | **Retain/defer:** `TemporaryDebugCapture` is cohesive and no second request owner justifies a type; recheck after approved packets land.                                                                                                 |
| Request one-shot deployment runner (`request-deployment-runner`)                                               | Explicit POST, `manage_options`, action nonce, coordinator availability, then both `update_plugins` and `update_themes`                                                           | `DeploymentCoordinator::requestRunner()` schedules the existing one-shot wakeup; fixed success/failure notice                                                                                                                                   | Scheduled event is only a wakeup; durable truth remains in deployment attempts and fresh activity                                                                                                                                                                                                                                 | No direct deployment claim and no runner request on GET, failed nonce or missing capability                                                                                               | Shares identity/result choreography with recovery actions. **Propose deployment-recovery packet.**                                                                                                                                       |
| Reconcile confirmed stopped worker (`reconcile-deployment-worker`)                                             | Explicit POST, `manage_options`, action nonce, coordinator availability, then both update capabilities; exact attempt/correlation and `confirm_stopped` follow                    | `DeploymentCoordinator::reconcileConfirmedStopped()` owns the protected transition                                                                                                                                                              | Exact attempt state and activity detail after mutation                                                                                                                                                                                                                                                                            | No broad lookup, no inferred stopped state and no package mutation from the adapter                                                                                                       | Same deployment-recovery packet                                                                                                                                                                                                          |
| Resolve needs-attention attempt (`resolve-needs-attention`)                                                    | Explicit POST, `manage_options`, action nonce, repository availability, canonical exact attempt/correlation read, then stored package's update capability                         | `DeploymentAttemptRepository::resolveNeedsAttention()` records actor/time after explicit `confirm_reviewed`                                                                                                                                     | `findExact()` plus correlation equality before mutation; fresh exact detail/activity afterward                                                                                                                                                                                                                                    | No capability choice from submitted package type; no retry or deployment mutation; no broad-list fallback on malformed identity                                                           | Same deployment-recovery packet                                                                                                                                                                                                          |
| Render deployment activity list/detail and rejected admission                                                  | Read-only troubleshooting `panel=activity`; exact detail requires positive attempt and 32-hex correlation; cursor is fail-closed                                                  | `DeploymentAttemptRepository` supplies history/detail/package summaries; `RejectedAdmissionAuditRepository` supplies bounded audit rows                                                                                                         | Exact attempt/correlation, later verified attempt, fresh repository history and unambiguous managed-package settings URLs                                                                                                                                                                                                         | No malformed identity fallback to broad history, no ambiguous package link, no mutation                                                                                                   | `Dashboard::activity()` and helpers occupy 216 lines and duplicate request canonicalization found in Dispatcher. **Include in deployment-recovery packet.**                                                                              |
| Dismiss current background deployment-failure notice (`wp_ajax_ran_booster_dismiss_background_failure_notice`) | The registered asset POSTs `action` and `nonce`; the controller checks `manage_options`, then `ran-booster-background-failure-notice`. The handler has no separate method branch. | `BackgroundDeploymentFailureMonitor::fingerprint()` derives the current bounded failure fingerprint; the controller writes `_ran_booster_background_failure_notice_fingerprint` for the current user and returns fixed JSON success/error data. | A positive user ID and current fingerprint are required; exact `get_user_meta()` plus `hash_equals()` must confirm the write before `{dismissed:true}`. `admin_notices` and `network_admin_notices` retain notice rendering, and `admin_enqueue_scripts` loads/localizes the dismissal asset only while `shouldRender()` is true. | No user-meta read/write before capability and nonce; no dismissal for absent failure, absent user, failed write or stale/different fingerprint; no script when the notice is not visible. | `DeploymentAdminController` retains the AJAX constants/response while replacing the old controller; `DeploymentAdminPresenter` retains notice rendering, visibility and fingerprint identity. **Include in deployment-recovery packet.** |
| Record blocked retry message from a package operation                                                          | Package capability/nonce precedes the operation; existing active attempt arrives as a typed storage failure                                                                       | `PackageOperationService`/coordinator owns mutation refusal; Dashboard currently maps the result and may append one rejected-admission audit record                                                                                             | Exact active attempt and correlation link to activity; audit failure is logged but does not alter refusal                                                                                                                                                                                                                         | No claim that needs-attention work is running; no retry before acknowledgement                                                                                                            | Result/message ownership crosses the package and deployment surfaces. **Establish once in deployment-recovery packet, then reuse from package packet.**                                                                                  |

### Deployment and diagnostics duplication

Dispatcher contains separate POST/capability/nonce/result flows for debug
capture, diagnostics and three deployment actions. Dashboard separately owns
request-local diagnostic state, debug snapshots, deployment identity parsing,
history, rejected-admission projection and deployment outcome notices. Only the
deployment seam has executable type financing:
`DeploymentAdminController` replaces the existing background-failure notice
controller and expands that same deployment-admin authority to the three Core
POSTs; `DeploymentAdminPresenter` replaces the background-failure notice and
absorbs activity/rejected-admission projection while retaining the notice and
its dismissal fingerprint. `DeploymentOutcomeMessage` remains the shared fixed
copy owner. `DeploymentCoordinator`, `DeploymentAttemptRepository`,
`RejectedAdmissionAuditRepository` and `BackgroundDeploymentFailureMonitor`
remain cohesive application/readback owners. Diagnostics and debug capture stay
in the stable hotspots until a later rebaseline proves a zero-type coherent
owner; they receive no deletion allocation here.

## Package journeys

### Exact single-package action matrix

The common order is capability set, action nonce, package mutation guard,
stored-provider guard for edit only, repository resolution for install/edit
only, then `PackageOperationService::execute()`. An edit with
`reinstall_after_save=1` additionally verifies the matching update nonce before
any guard or mutation.

| Actions                           | Required capabilities                                  | Repository resolution            | Authoritative result/readback                                                                                                               |
| --------------------------------- | ------------------------------------------------------ | -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| `install-plugin`, `install-theme` | `install_plugins` or `install_themes`                  | Yes                              | Typed operation result, exact managed package record, coordinator/WordPress installed postconditions; signed PRG only after success         |
| `edit-plugin`, `edit-theme`       | `update_plugins` or `update_themes`                    | Yes, after stored-provider guard | Optimistic source-revision check, exact saved package; optional reinstall uses the saved package and coordinator postconditions             |
| `update-plugin`, `update-theme`   | Matching update capability                             | No                               | Exact deployment attempt/outcome and installed package postconditions; list filters preserved only through normalized signed redirect state |
| `unlink-plugin`, `unlink-theme`   | Matching update capability                             | No                               | Exact absence of the management record while installed files remain                                                                         |
| `unlink-delete-plugin`            | `update_plugins`, `delete_plugins`, `activate_plugins` | No                               | WordPress deactivation/deletion safety and file absence before management unlink                                                            |
| `unlink-delete-theme`             | `update_themes`, `delete_themes`                       | No                               | Active/parent/child safety and file absence before management unlink                                                                        |

`PackageMutationGuard`, `PackageEditProviderGuard` and
`PackageRepositoryRequestResolver` run before `Dashboard::postPackageOperation()`.
`PackageOperationService` and its existing repositories/coordinator own the
mutation. Dashboard currently parses `PackageOperation`, executes the service,
maps conflict/deployment/removal/storage outcomes, constructs signed PRG URLs,
verifies their notices on the next GET and records rejected needs-attention
admission. This is the clearest cross-hotspot ownership violation.

The landing owner is exact: `PackageAdminController` replaces
`PackageEditProviderGuard`, absorbing that guard's single request-bound policy
beside the complete action/capability/nonce/result choreography while continuing
to call `PackageOperationService`, `BulkPackageActionService` and
`PackageRepositoryRequestResolver`. The later read-only `PackagePagePresenter`
replaces `PackageViewConfig`; it retains the closed plugin/theme vocabulary and
adds only the create/edit/index model currently assembled in Dashboard. It may
not absorb mutation, generic tabs or provider acquisition. Both replacements
are internal and each lands at 253 - 1 + 1 types.

| Action journey                                             | Capability and nonce order                                                                                      | Application owner, mutation and bounded result                                                                                                                  | Authoritative readback and rendering                                                                                                  | Prohibited effects                                                                                                                   | Concentration and disposition                                                                                                                                   |
| ---------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Install/edit/update/unlink/delete one package              | Exact matrix above                                                                                              | `PackageOperationService`; updater lock, package repositories, removal service and coordinator retain their current invariants                                  | Exact package/deployment/WordPress postconditions; action-local failure or signed PRG success; package edit/index renders fresh state | No caller-forged provider replacement, Core self-update, multisite install, stale edit, unsafe delete, plaintext or unsigned success | Dispatcher owns guards/resolve; Dashboard owns execution/result/transport. **Propose single-package packet.**                                                   |
| Bulk queue/policy/activation (`bulk-plugin`, `bulk-theme`) | Explicit POST; derive bounded operation only to choose authority; exact operation capability, then action nonce | `BulkPackageAction::fromInput()` and `BulkPackageActionService::execute()` return `BulkPackageResult`                                                           | Fresh package/WordPress/attempt state plus signed bounded bulk result notice on the matching list                                     | No more than 200 activation/deactivation or 20 queue/policy identities, mutation before nonce, forged notice, cross-type capability or result-as-durable-truth | Dispatcher retains the closed route/transport; `PackageAdminController` owns request execution and signed feedback. **Landed in C2-4.**                          |
| Render package create/edit/index                           | Read-only registered menu callback; bounded package, search/provider/source/policy query                        | Package repositories and `ProviderSettingsPresenter` retain acquisition; `PackagePagePresenter` composes only already-acquired provider/form/readiness/retention data | Fresh package record/list, extension hooks and package activity; safe empty/disabled state on storage/database failure                | No GET mutation, raw add-on HTML, ambiguous package identity or business logic in views                                              | `Dashboard` retains request/acquisition/render compatibility; the stateless projection-only owner replaces `PackageViewConfig`. **Landed in C3-1.** |
| Render and consume signed package success/bulk notices     | GET marker must contain the full allowlisted tuple and verify its operation-specific nonce/hash                 | Package-family request owner maps only fixed semantic results; message collection remains request-local                                                         | Fresh page state is authoritative; notice is feedback, not mutation proof                                                             | No unsigned/partial/stale marker and no notice treated as state                                                                      | **Move with the owning package packets; do not create a generic result/message hierarchy.**                                                                     |

### Package extension and public-contract boundary

The package packets retain the exact package source, advanced-source,
management-row/action, settings-section and webhook-cleanup hooks and their
argument order. Release Deployments remains a fixture for Add-on API 14,
Prospective Release API 5 and release-tracking facades. No package packet may
add template, GitHub workflow, release-publication or draft-pull-request
authority to Core.

## Remaining Dashboard routes

| Route                                  | Current owner and result                                                                           | Disposition                                                                                                                                                         |
| -------------------------------------- | -------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Overview and onboarding/storage status | `Dashboard::getIndex()`, `OnboardingPresenter`, `SecretsStorageSetupPresenter`; rendered root page | **Retain.** Storage mutation remains deferred to C4; no C3 owner is justified solely to move this assembly.                                                         |
| Provider tabs                          | C1 presenters/normalizer/render helpers and passive view                                           | **Retain C1 boundary.**                                                                                                                                             |
| Portability                            | Existing Dashboard read projection and published `PortabilityFacade`/controller operations         | **Defer.** Not a C2 candidate family and no duplicated operation is evidenced here.                                                                                 |
| Documentation                          | `ProviderDocumentationPresenter` plus stable filters                                               | **Retain.** Already focused.                                                                                                                                        |
| Bounded add-on tabs                    | `AdminAddOnRegistry` and registered context                                                        | **Retain.** Do not turn it into a generic slot or whole-view replacement.                                                                                           |
| Tab navigation and base render         | `AdminTabRegistry`, `Dashboard::tabNavigation()` and `render()`                                    | **Retain until all approved vertical packets land.** Then run a zero-runtime-type, no-public-deletion GO/NO-GO cleanup; forwarding alone is not deletion authority. |

## Test and coupling inventory

Current stable-boundary evidence is sufficient for this characterization, so no
new PHP test was added merely to freeze private structure.

- `OperatorActionDispatcherTest` covers POST/capability/nonce order, debug file
  outcomes, HTMX diagnostic outcomes and deployment runner/needs-attention
  readback.
- `CredentialProfileInteractionDispatcherTest`,
  `CredentialValidationHtmxDispatcherTest` and
  `PublicLookupProfileHtmxDispatcherTest` cover signed native/enhanced profile
  outcomes, secret redaction, lock failure and exact state consequences.
- `SecretsStorageSetupDispatcherTest` already protects the separately deferred
  storage boundary.
- `PackageMutationGuardDispatcherTest` and
  `PackageEditProviderGuardDispatcherTest` cover the complete action/capability/
  nonce matrix and pre-mutation stops.
- `PackageOperationServiceTest`, `PackageRemovalServiceTest`,
  `BulkPackageActionServiceTest` and `DashboardIndexRoutingTest` cover typed
  mutation results, authoritative repository/WordPress/attempt readback,
  signed notices, package pages and activity list/detail outcomes.
- `BackgroundDeploymentFailureTest` and `BoosterAssetsTest` cover the current
  failure fingerprint, capability/nonce dismissal, exact user-meta readback,
  admin/network notice hooks and visible-only dismissal asset.
- `PackageControlContractTest` inventories native GET, native POST/PRG, enhanced
  package interactions and add-on/WordPress-owned controls.

Implementation coupling remains cleanup evidence, not public API: the current
tests contain 15 `Dashboard` reflection constructions, one Dashboard subclass
and five Dispatcher subclasses; three direct `new Dashboard(...)` and three
direct `new Dispatcher(...)` constructions also remain. Touched packets replace
only private reflection/subclass assertions made redundant by outer action/page
outcomes. They may not add production interfaces for mocks or chase a test-line
target.

## Proposed child packets

All children are Core-only, source-only and one operator journey. Each begins
from the exact previous landed Core SHA, updates this evidence after landing,
runs the focused real action/page outcomes, `composer check`, `pnpm check`, PHP
lint, the WordPress activation smoke, exact counters and `git diff --check`, and
builds/verifies the candidate archive when the repository's normal gate does so.
Installation, publication and release remain separately authorized.

The deletion floors are cumulative programme measurements. Each range below is
pinned to the characterized source object and is a feasibility ledger, not
permission to delete blindly. Replacement-size caps include the small retained
Dispatcher/Dashboard delegate. Correctness and cohesive ownership remain
primary; a measured deviation is recorded and taken to the owner before any
abandonment or programme-scope decision rather than hidden or manufactured.

| Order / packet                              | Exact scope, landing owner and named deletion source                                                                                                                                                                                                                                                                                                                                                                                                                 | Hard landing budget and final type arithmetic                                                                                                                                    | Rollback                                                                                                        | Packet-specific stop                                                                                                                                                                                       |
| ------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1. Provider-profile request journey         | Six profile/validation/lookup actions after C1; storage excluded. Add `ProviderProfileAdminController`; delete `CoreProviderProfileInteraction` (28 lines) and replace Dispatcher 151-181, 816-1278, 1285-1301, 1349-1402 and 1445-1473. Retain shared HTMX detection and the deferred debug/diagnostics responders at 1279-1284 and 1302-1348. Controller plus delegate: at most 492 lines.                                                                         | At least 130 net backend lines deleted; cumulative shipped PHP at most 46,944; backend at most 46,292. Types: 253 - 1 + 1 = 253. Zero public/state/dependency delta.             | Revert one source commit; sidecar/option formats are unchanged.                                                 | Stop if the legacy interface has a production consumer, nonce scopes merge, secrets are read before authority, signed interaction behavior changes or the complete named branches do not leave Dispatcher. |
| 2. Deployment recovery and activity journey | Three deployment actions, failure-notice AJAX dismissal, retained admin/network notice and asset hooks, plus activity list/detail/rejected admission. Replace `BackgroundDeploymentFailureNoticeController` with `DeploymentAdminController` and `BackgroundDeploymentFailureNotice` with `DeploymentAdminPresenter`; replace Dispatcher 183-190 and 611-717 plus Dashboard 1535-1564, 1584-1661 and 1956-2213. Both replacements plus delegates: at most 497 lines. | At least 130 further backend lines deleted; cumulative shipped PHP at most 46,814; backend at most 46,162. Types: 253 - 2 + 2 = 253. Zero schema/public delta.                   | Revert one source commit; durable attempt/audit rows and user-meta fingerprint remain readable by the old path. | Stop on notice/dismissal regression, broad activity fallback, caller-selected package capability, schema change, weakened exact-correlation readback or duplicated attempt-repository SQL.                 |
| 3. Single-package mutation journey          | Exact ten-action matrix and signed PRG after packet 2. Add `PackageAdminController`; delete `PackageEditProviderGuard` (57 lines) and replace Dispatcher 198-285, 718-762 and 1403-1444 plus Dashboard 1110-1235, 1283-1349 and 1565-1583. Controller plus delegate: at most 264 lines.                                                                                                                                                                              | At least 180 further backend lines deleted; cumulative shipped PHP at most 46,634; backend at most 45,982. Types: 253 - 1 + 1 = 253. All package hooks/facades unchanged.        | Revert one source commit; package/attempt schemas and existing application services remain authoritative.       | Stop on action/capability/nonce drift, new public result type, Core self-update/multisite guard loss, stale-write/delete safety loss, unsigned success or facade/hook change.                              |
| 4. Bulk-package mutation journey            | Only `bulk-plugin`/`bulk-theme`, typed result and signed notice; extend the packet-3 `PackageAdminController`. Replace Dispatcher 192-197 and 543-610 plus Dashboard 1350-1534. Added controller code: at most 179 lines.                                                                                                                                                                                                                                            | At least 80 further backend lines deleted; cumulative shipped PHP at most 46,554; backend at most 45,902. Types remain 253 + 0 = 253.                                            | Revert one source commit; signed notices are feedback and need no data migration.                               | Stop if the exact operation capability moves after mutation, more than 200 activation/deactivation identities or 20 queue/policy identities pass, a forged/cross-type marker renders success, or bulk result is treated as durable truth.                                    |
| 5. Package create/edit/index page journey   | Read-only package selection, filters, source/extension/activity projection and rendering after mutation outcomes stabilize. Replace `PackageViewConfig` (88 lines) with `PackagePagePresenter`; replace Dashboard 405-1064, 1236-1282 and 1684-1785. The landed presenter is stateless and projection-only; Dashboard retains request normalization, provider/repository acquisition and final rendering.                                                                                                               | Landed at 46,684 shipped / 46,032 backend: -55 physical lines, 253 - 1 + 1 = 253 types. Presenter plus four public menu wrappers: 653 lines. The accepted correctness-evidenced deviation is recorded in the C3-1 evidence. | Revert one source commit; no persistent or installed state changes.                                             | The presenter is not generic, views remain passive, plugin/theme vocabulary and add-on boundaries are stable, and the outer Release Deployments hook fixture passes. **Landed in C3-1.**                    |
| 6. Base Dashboard GO/NO-GO                  | Read-only re-inventory after packets 1-5; diagnostics/debug/base routing remain unless this gate proves a new coherent zero-type proposal.                                                                                                                                                                                                                                                                                                                           | Zero production/type/public/state allowance.                                                                                                                                     | Documentation-only revert.                                                                                      | Retain the base coordinator unless a complete remaining route/hook responsibility and physical deletion are proven.                                                                                        |

The landed C1 through C3-1 source results remove 410 physical backend lines from
the frozen programme and retain 253 types. C3-2 now carries a visible 390-line
programme-floor remainder and a 430-line original-final-target remainder. It is
a read-only re-inventory, not authority to manufacture deletion or add a type.
The coordinator must measure the correctness-preserving result and consult the
owner before any abandonment or programme-scope decision if the remainder
cannot be reclaimed cleanly. The four-type temporary allowance is not used by
this programme.

## Global reject and defer register

- **Reject:** splitting `Dispatcher` and `Dashboard` by method count, a handler
  registry, command bus, one-method interface family, generic presenter/result
  hierarchy, browser-form DTOs or a public operation facade.
- **Reject:** moving provider permission vocabulary, provider protocol,
  add-on-specific mutation, package release/bootstrap authority or WordPress
  installation ownership into Core.
- **Defer:** secure-storage create/adopt/reset and all encrypted-document
  physical custody to C4's separate threat model and failure contract.
- **Defer:** diagnostics and debug-capture movement until the base re-inventory;
  their existing application owners are cohesive, and no genuine replacement
  type currently finances a separate controller or presenter at 253 types.
- **Defer:** portability and public facade changes; their existing operations
  have no C2-C3 duplication case.
- **Retain:** `Dispatcher` explicit router, Dashboard menu/tab/base-render
  compatibility, existing application services, public hooks/facades, state
  identities and native POST/PRG plus enhanced parity.
- **Stop globally:** any public API/hook/action/schema ambiguity, capability or
  nonce weakening, pre-authority sensitive read, non-authoritative success,
  backend/type ceiling breach, presentation-credit accounting, generic
  framework need or result that only relocates code.

## P4 handoff fields

Each landed packet must record, without implementing P4, its UI/request adapter,
component-owned application owner, canonical normalized input, bounded semantic
result and authoritative readback. All current Core form journeys remain
browser-bound because their adapters own superglobals, WordPress capabilities,
nonce acquisition, redirect/HTMX response and termination. The published
webhook-assistance facade remains additionally browser-bound at its quarantined
nonce-revalidating leaf. No Ability, CLI, MCP adapter, registry, schema, public
facade, operation interface or runtime DTO is authorized by this map.
