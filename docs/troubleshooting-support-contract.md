# Troubleshooting and local support evidence

Implementation baseline: Core `c0f127a29ab5e136986169b4b5a99f6f5d9bc546`.
Core #571 and #572 are authorized by the owner's implementation request.
The rehearsal checkout and canonical Workbench are preserved.

## Invariant and cost recorded before production edits

Core owns five fixed views and one local, sanitized support representation.
Installation is the default. Explicit destinations and diagnostic POST results
survive. Settings owns storage operations after its coherent cutover.

Rejected smaller alternatives: copying the current display report leaks private
local declarations; retaining raw diagnostic rows trusts display safety as export
safety; transient read/modify/write loses concurrent providers; existing package
inventory may prepare schemas; Activity SELECT * has unbounded TEXT fields.

Projected production budget: 1,100 added lines and 160 removed lines across
Troubleshooting services/views, two narrow storage reads, asset enhancement and
coordinated routing. Three new concrete private types: InstallationFacts,
SupportReport and DiagnosticRunStore. No public API, registry, hook, table or cron.
One nonautoload site option for temporary diagnostic inputs; no generated-report
persistence. Tests and documentation are counted separately at delivery.
The coordinating Settings agent owns Dashboard, Dispatcher, Booster and
BoosterServiceProvider changes; patches are reviewed at that boundary.

## Routes and authority

Panels in order: installation, diagnostics, activity, debug-capture, support-report.
deployment-activity remains an Activity alias. Missing/invalid panel uses
Installation. A completed diagnostic request renders Diagnostics.
Generate is POST on the fixed Troubleshooting Support route, action
generate-support-report, nonce ran-booster-generate-support-report and
manage_options capability. Its payload permits only action. Restricted bootstrap
classification grants no action authority and applies before nonce validation;
normal operational POST initialization remains intact.

## Positive collection boundary

### Owner change: fresh basic diagnostics in a generated report (pre-edit contract)

The owner supersedes the earlier passive-only Generate decision for one narrow
operation: an admitted administrator may request one fresh **basic** diagnostic
sweep while creating the local report. The sweep runs local checks once, then
at most 32 already-admitted registered providers in metadata order. Every
provider receives a new `ProviderDiagnosticRequest(null, null, ...)`; it must
not enumerate a credential, accept a repository, test webhook readiness, write
Activity, retain a diagnostic run, start capture, repair storage or provision
anything. The normal selected-provider Diagnostics POST remains unchanged.

The fresh sweep has cooperative, monotonic admission checkpoints: a 20-second
overall window, an aggregate maximum of 20 claimed remote calls, and the
existing per-provider limits of five claims and ten seconds. Local checks are
not pre-emptible, so this is not a wall-clock guarantee. The existing five
second passive serialization budget begins after that sweep. Completed groups
remain visible; omitted, unavailable, malformed, exhausted and permission-
skipped groups are explicit. Results stay grouped (local plus each provider),
with the existing eight-row redaction bound applied per group.

Fresh checks require the same active-diagnostics capabilities as the selected
Diagnostics operation. A `manage_options`-only administrator still receives a
safe passive report, with fresh checks explicitly skipped for insufficient
permission. A local partial result prevents every provider call. The
report-only local variant omits durable-journal readiness because that existing
path may prepare storage; Activity's passive evidence continues to describe
the journal. This keeps generated reports free of schema migration and other
durable writes.

Final measured delivery: the report change touches the existing Troubleshooting
service, local diagnostics, report, facts and view files only. It adds no
tables, options, cron jobs, generated-report state, provider registrations or
public extension seams. The fresh path calls only already-admitted providers
with null selection inputs; it does not construct Activity or retained-run
writes. Environment and configuration values are projected as bounded status
tokens, and secret/path values are withheld.

### Settings check projection correction (pre-edit contract)

Settings owns policy resolution and durable operator-check observations. This
bounded correction consumes its optional scalar fields without copying its
implementation or adding collection: `preference_disabled` means a saved site
preference disables Core discovery even for a verified release. Installation
uses explicit site-disabled wording rather than source-checkout wording.

`last_successful_check` is the positive Unix UTC completion time of the last
matching successful operator check; later failure/skip does not erase it.
`last_check_outcome` permits only success, failed, unknown, rejected or skipped.
`last_check_code` exports only the reviewed fixed Core-check/updater reasons
listed below; arbitrary bounded strings remain Unknown. `check_cooldown_until`
is positive Unix UTC completion plus 300 seconds, possibly already past; it
does not promise another check. Missing/invalid fields are unavailable. All new
timestamps are capped at 253402300799. Existing target `last_check`/`next_check`
remain separate and never substitute for successful operator-check evidence.

Exported check codes: native_discovery_disabled, refresh_rejected,
orchestration_failed, check_not_observed, check_succeeded, check_rejected,
check_failed, claim_lost, cooldown_active, claim_unavailable, target_unavailable,
unexpected_failure, runtime_identity_invalid,
policy_disabled, policy_forced_off, filesystem_unavailable,
discovery_not_admitted, candidate_not_eligible, offer_token_unavailable,
update_available, release_list_failed, candidate_invalid, candidate_rejected,
candidate_inspection_failed, candidate_descriptor_mismatch,
candidate_validation_failed, no_candidates and no_newer_candidate. On-screen
codes additionally accept pathless lowercase/underscore codes up to 64 bytes;
the shared export policy remains the closed list above.

Projected correction cost: at most 75 production additions / 20 removals in
the existing view/report/service, one private pure projection method, no new
type, public seam, collector, remote request, persisted state or dependency.
Tests exercise Settings-shaped scalar input in the standalone source branch;
the Settings-owned composed candidate must also prove its real durable Check
readback through CoreSelfUpdateStatus and InstallationFacts to both projections.

All sections carry Included, Partial, Unavailable, Not run, Not captured, Not included or
Truncated coverage, collection times and omission context. One section failure
does not stop the others. The five-second budget is checked between bounded
collectors and cannot interrupt a blocking call. Output is at most 1 MiB UTF-8;
retain metadata and omit whole section data from the end when the encoded limit is reached. No recursive
serialization of arbitrary source objects is permitted.

| Source | Allowed fields and bounds | Cost / effect |
| --- | --- | --- |
| Runtime | WP/PHP/database numeric versions; OS family; SAPI; known server family; memory/time/post/upload limits; zip/sodium/curl/json/openssl/mbstring presence; filesystem method constant; cron/debug/environment flags | Fixed runtime reads; one database version read; no Site Health dump, phpinfo, remote request or directory traversal |
| Core/update | installed numeric version, provenance class, effective/requested mode and reviewed reason/state; numeric offered/marker version, commit hex, recorded UTC timestamps | Existing passive policy/target status; no discovery; unavailable check time is unknown |
| Providers/components | Fixed public GitHub/Bitbucket names; custom providers/components get consistent placeholders; bounded local declaration/version/admission/selection/availability facts | At most 32 in-memory providers and fixed known extension metadata; declarations do not prove remote access or publisher identity |
| Storage | configured/default source, logical encrypted sidecar label, available runtime state, authentication Not checked; privileged configured location only on screen | Reuse Settings owner's passive projection; no status(), recovery scan, credential enumeration, capacity write probe or repair |
| Packages | at most 100 rows, scalar type/provider/source/policy and package/repository/ref identities replaced consistently; counts describe included window | One advisory is_ready read and SQL with explicit LEFT-clipped columns, no hydration/require_ready/schema changes; no new aggregate |
| Activity | at most 100 rows: numeric ID, hex correlation, UTC timestamps, fixed operation/status/effect/reason/stage codes, placeholder subject identity, known provider or placeholder | Explicit scalar SQL projection with field clipping, existing history scope; omit all message/remediation/context/actor/adoption token/raw facts; no pruning |
| Diagnostics | at most 8 rows per run: fixed status and reviewed code, code-derived safe detail/remediation; booleans for credential/repository scope; completion/expiry, provider implementation attribution | Server-produced only; unknown arbitrary provider prose omitted explicitly; latest per provider/current administrator/site |
| Logging | existing state/active-until/expiry; at most 400 entries / 256 KiB, each 4096 bytes; fixed reviewed message templates and scalar safe code/correlation context only | Existing snapshot and lazy expiry cleanup; unrecognized lines omitted with coverage; no external logs or capture start |

Strings use field-specific positive syntax or fixed enum membership. Private
identities are replaced with encounter-order placeholders, never predictable-name
hashes. Numeric versions are at most 80 bytes; reviewed codes at most 96, provider
IDs 32, private package/ref keys 255, repository keys 512. Log JSON parsing is
bounded to depth 4. Invalid UTF-8/control characters never enter export. No raw
credentials/IDs/labels, keys, recovery material, paths, URLs, actor identities,
configuration, headers, responses, exceptions or arbitrary log text are exported.
Preview escaping is additional protection, not the export boundary.

## Diagnostic input storage

One fixed nonautoload site option contains a versioned map, capped at 64 live
administrator/provider slots, 8 KiB per entry and 512 KiB encoded document.
Internal site/user/provider keys never enter reports. Each entry contains only
the allowlisted representation, server completion ordering and implementation
attribution; no private target/credential locator. Scope says whether one
repository and/or saved credential was checked, without claiming all access.

Validity ends exactly 3,600 seconds from completion. Reads never extend it.
Five bounded compare-and-swap attempts preserve other providers/admins and choose
the greatest completion timestamp, with a server nonce as deterministic tie
breaker. Delayed older writes cannot replace newer completions. Saturation or
storage failure preserves immediate results and reports retention unavailable.
Expired entries are pruned on subsequent diagnostic writes; reads filter expiry
even when physical cleanup is delayed. Deactivation clears the fixed option
best effort; uninstall deletes/verifies it through existing local-data custody.
No new cron, report store or history browser. Attribution stays attached to the
implementation that ran, including after provider replacement.

## JSON format

The version 1 root has `schema_version`, `capture_started_at`, `capture_ended_at`,
`coverage`, `observation`, and `sections`. Each section has `state`, `observed_at`,
and `data`. Sections are `environment`, `installation`, `storage`,
`managed_packages`, `diagnostics`, `activity`, and `logging`; their named fields
contain fixed sanitized facts and bounded records. Missing or unavailable data is
identified by section state or an explicit reason. Strings such as `Unknown`,
`Not checked`, and `Not included` are sentinels, not measured values; a null
count is unknown rather than zero. Diagnostic coverage reasons may coexist.
Private identities are aliases shared across sections in capture order. When the
encoded byte ceiling is reached, whole section data is replaced by an explicit
`output_byte_limit_exhausted` reason and the section is marked Truncated; the
metadata, original section state, and JSON syntax remain intact. Consumers should tolerate additional
fields within version 1 and check coverage before interpreting absence. Incompatible
format changes require a new schema version.

## Frozen outputs and housekeeping

Generate captures pretty-printed UTF-8 JSON once in request memory. Preview, Copy JSON and browser JSON Download use those exact bytes; filename is timestamped without hostname.
No JavaScript: selectable JSON supports manual copy/save. Source expiry cannot
change an existing preview. No sensitive-detail toggle, auto sending or telemetry.

TemporaryDebugCapture snapshot can create/tighten its 0600 lock and delete an
expired owned capture. Existing credential-expiry security protections remain.
Reporting adds no provisioning, repair, schema preparation, recovery, additional
diagnostics beyond the requested fresh sweep, or capture startup. This is not a strict filesystem-immutability claim. Inactive
capture cannot distinguish never captured, expired or deleted. No lost-entry
count is invented. Optional deployment totals are omitted: the existing aggregate
enters require_ready and recent Activity is not a lifetime deployment journal.

If Booster/admin cannot load, use the established WordPress recovery and hosting
support routes, sharing only independently reviewed evidence. The report cannot
collect evidence through an unavailable bootstrap or recover pre-capture events.

## Pre-edit review correction: captured GitHub declarations

Independent source review identified that an injected `expectation()` reader
would repeat GitHub active-manifest discovery (up to 1,024 active plugin entries)
merely to fill a passive component summary. Reject that extra scan. Add one
internal `GitHubProviderSelection::support_observation()` method, projected 45
production lines, returning only constructor-captured selection/admission state,
selected kind, declared API/compatibility metadata, safe version from at most one
8 KiB selected-plugin header, and internal generation/fingerprint attribution.
No authority reload, candidate re-discovery, provider call, health test or recovery
scan. Facts reuses that one request-local observation for report fields and
retained diagnostic attribution. It is explicitly an earlier bootstrap
observation, not fresh remote access evidence or verified continuity.

Actual source growth exceeded the initial 1,100-line estimate: before this
correction it was 1,400 additions / 114 removals. The separately counted 120-line
passive-reader boundary and 269-line CAS/expiry owner are substantive costs rather
than hidden existing behavior. This correction adds one internal owner method and
one private projection dependency, no concrete type, public extension API or
persistent state. Final exact production/test/documentation deltas are recorded
with delivery evidence.

## Verification and custody

The diagnostic store also captures the initiating administrator/site scope before
provider work and conditions retention on that same scope afterward. A provider
that changes the current WordPress user cannot reassign evidence; immediate
results remain visible when retention is refused. Implementation attribution is
captured before provider work from the same bootstrap declaration observation.

The maintained `tests/WordPress/support-snapshot-smoke.php` runs after the existing
CI database smoke. It checks actual SQL/CAS behavior, the combined row budget,
redaction and numeric Activity ordering: clipping `id` to a string must not cause
99 to sort before 105 when timestamps tie. Activity retains closed stage/effect
codes so needs-attention and operator-reviewed work remain distinct; reviewed
attention is never converted to successful deployment evidence. Supported
package sources are `branch` and `release_asset`.

Disposable WordPress 7.0.4 / PHP 8.2.30 / MySQL 8.0.35 proof passed activation,
Core database smoke and 445 support assertions. Five complete WordPress report
POST paths (valid, denied capability, forged nonce, extra fields and wrong route)
showed no DDL or Booster operational writes. Existing WordPress cache/cron/update
transient housekeeping was observed and is separate from report collection.
The report action selects restricted bootstrap even on an obsolete query route;
Dispatcher still rejects that route and grants no action authority.

Desktop/mobile Chrome proof covered the five native links, default/invalid
routing, diagnostic separation, explicit generation, byte-identical clipboard
and UTF-8 download, safe filename, no browser errors and no-JavaScript manual
text. Screenshots were inspected after page transitions settled. This is source
and disposable-fixture evidence, not retained-site, deployment or release
acceptance. Settings owns the complete storage cutover independently; a direct
Installation request has no invented authenticated health or repair eligibility.

Final measured production delta: 1,529 additions / 122 removals; tests: 2,144
additions / 56 removals. Three concrete production types, one bounded temporary
option, no table, cron, public extension seam or generated-report state. The
initial production estimate was exceeded by the explicit read/retention and
captured-declaration boundaries described above; tests/docs do not offset it.
The subsequent Settings projection correction changes 42 production lines and
removes 14 against the prior source head (including moved projection lines),
adds 107 test lines, and stays within its separate pre-edit budget. It adds no
type, public seam, collector or state. Its standalone tests prove projection
and redaction; Settings-owned composition must prove durable-check integration.
