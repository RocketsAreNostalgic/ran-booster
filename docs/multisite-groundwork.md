# Multisite groundwork and implementation boundaries

## Status and initial target

Booster currently supports single-site WordPress only. This document records
groundwork for [Core #573](https://github.com/RocketsAreNostalgic/ran-booster/issues/573);
it does not enable multisite, migrate state, or change existing operations.

The owner selected this initial implementation target on October 30, 2026:

- One WordPress network, with Booster managed from Network Admin and activated
  network-wide. Per-site activation of Booster is outside the initial target.
- Network administration owns management of the installed package files,
  deployment policy, provider selection, credentials, and recovery. Site-level
  plugin activation and theme selection remain distinct WordPress operations.
- Multiple networks sharing package files are deferred. Separate WordPress
  installations sharing plugin/theme directories are also outside this target.
  A future implementation must refuse excluded topologies explicitly; a network
  ID or database prefix alone does not prove exclusive filesystem ownership.
- Work only with package roots already accepted by Booster's path/custody
  checks. Custom or shared roots do not gain support through this document.

This is a planning target, not a support announcement. The current runtime
quarantine remains until the implementation and composed acceptance below are
complete. The updater libraries' narrower existing guarantees do not enable
multisite in Core.

## Current ownership versus the implementation contract

Source baseline: Core `0dea2b658a12d3211ef3302f1dd04d039e65bfde`, with locked
Branch beta.13 `b1cdb63d8fded52e372054a4461d0a056f9998d3` and Release beta.15
`2283850d81a3a5b0dfa7fee07beddaba8bf02883`. Refresh these observations before
implementation; the right column defines obligations, not a selected SQL schema.

| Concern | Current source behavior | Required owner for the initial target |
| --- | --- | --- |
| Installed plugin/theme files | Shared package roots; WordPress performs installation | One installation mutation authority covering every actor sharing the target files, including native WordPress |
| Managed package/source policy | `ran_booster_table_name()` selects the base prefix on multisite | One authoritative package/source record for each shared installed target; no competing per-blog policies |
| Attempts, deduplication and recovery | `Database::attempt_table_name()` uses the current blog prefix; repositories capture the table | Same management owner as the shared target; one unresolved-work and recovery history |
| Activity | `ActivityStore` captures the current-prefix table; schema readiness reads a blog option | Reporting belongs to the operation's management owner; unavailable/partial history stays explicit |
| Provider selection and approval | `GitHubSelectionStore` binds the current options table and locks `active_plugins` | Network-management authority with an authoritative activation/selection snapshot, approval identity and defined lock order |
| Credentials and encryption key | `SiteKeyStore` reads blog options; `SecretsFile` selects one configured sidecar | File, key, configuration, credential access, backup/restore and cleanup share one management owner |
| Webhook installations and endpoint | Blog option/CAS records; default endpoint uses current `rest_url()` | Canonical receiver, signing profile, record, deduplication and queue have the same fixed owner |
| Worker wakeup | One args-free event in current-blog cron | One explicitly chosen execution/schedule context for the management owner's queue |
| Mutation lock | Core delegates to Branch's current-blog `auto_updater.lock` adapter | Exclusion follows shared installed files and coordinates with native WordPress, not whichever blog is ambient |
| Release target fence | Base-prefix storage; key includes network/type/installed identity | Retain bounded discovery/operation fencing; it is not a replacement for the installation mutation barrier |
| Activation and theme usage | Core admitted facts describe current-site options; Branch compares `active:bool` | Explicit site/network intent and sufficient postconditions for every admitted consumer |
| Preferences and previews | Notice acknowledgments use user metadata; other form/preview state has its own current scope | Keep actor/presentation state distinct from package authority; define contextual identity where needed, without promoting everything to shared state |
| Configuration and uninstall | Converted-site cleanup requires main-site/base-prefix custody before shared removal | Distinguish site/network detach from installation uninstall; shared resources have one proven cleanup owner |

The relevant implementations are [bootstrap package naming](../ran-booster.php),
[database lifecycle](../RAN/Storage/Database.php),
[attempt repository](../RAN/Deployment/DeploymentAttemptRepository.php),
[Activity](../RAN/Activity/ActivityStore.php),
[selection storage](../RAN/Storage/GitHubSelectionStore.php),
[key storage](../RAN/Secrets/SiteKeyStore.php),
[secrets custody](../RAN/Secrets/SecretsFile.php),
[webhook installation storage](../RAN/Admin/WebhookManagement/Installation/WordPressInstallationStore.php),
[endpoint composition](../RAN/AddOn/WebhookAssistance/AssistedWebhookFacade.php),
[worker wakeup](../RAN/Deployment/WordPressWorkerWakeup.php),
[notice preferences](../RAN/Admin/NoticePreference.php), and
[cleanup](../RAN/Uninstall/LocalDataRemover.php).

## Actor and operation authority

These are design constraints for later implementation. Current capability and
nonce checks remain unchanged. The exact native capability combinations and
scope-bound nonces must be specified and tested in #578; an admin URL, a role
name, or a client-supplied blog/network ID is never sufficient authority.

| Actor | Permitted responsibility in the initial target | Required boundary |
| --- | --- | --- |
| Network administrator | Manage shared package files, source policy and provider selection | Fresh network-management authority plus the native capability for the exact install/update/delete operation; preserve file-modification policy |
| Network administrator with credential-management authority | Provision, use, recover or remove the management owner's credential storage | Separate authorization for the protected credential operation, purpose nonce and exact resource ownership; installation permission does not imply credential access |
| Site administrator | Use site activation/theme controls allowed by native network policy | No authority to change Booster's shared source policy, credentials or package files; site activation must not be confused with network activation |
| Signed webhook or scheduled worker | Execute already admitted work for the fixed management owner | Verify delivery/job identity, current package/source/credential policy and mutation authority; no ambient administrator privilege or unsigned tenant selection |
| Read-only visitor or denied actor | Receive only already permitted bounded output | No provider/credential resolution or mutation through passive reads or denied actions |

Provider approval must include the actual network-active implementation inventory
with equivalent authoritative snapshots and rereads. Simply unioning an extra
plugin list into discovery would leave approval identity and lock ordering
undefined. #574 owns that integration; #576 owns activation intent and usage
semantics. Their inventory contract can be agreed without waiting for both
implementations to finish.

## Execution and cleanup invariants

1. One installed package has one management authority and one shared-file
   mutation barrier. Release discovery fences and source-specific journals retain
   their responsibilities; do not introduce a competing updater or second lock.
2. Installation, site activation, network activation and theme use are distinct.
   Updates preserve the relevant state. New installs remain inactive until a
   separate authorized activation. Deletion requires complete consumer/dependency
   evidence and a policy for consumers changing during assessment.
3. Capture the operation's management and execution context at admission. A
   changed ambient blog/network must not silently redirect credential resolution,
   schema checks, journal access, provider calls, scheduling or new mutation.
4. Context loss stops new work but does not erase existing custody. Finalization
   must address the original owner's exact lock, artifact and journal marker;
   refusal based only on the new ambient context must not strand ownership or
   release another owner's resource. Interrupted or uncertain work stays visible.
5. File, database key and configuration ownership agree through creation,
   restore, recovery and uninstall. Never initialize over an existing incomplete
   store, infer an empty store from a failed read, or clear another owner's lock.
6. Inventory and reporting identify incomplete/unavailable evidence. A network ID
   in telemetry is correlation, not persisted deployment or credential authority.

These obligations do not select table names, option APIs, key IDs, migration
formats or new public contracts. In particular, neither replacing every option
with a network option nor adding a blog ID to every key establishes the owners
above. Implement only the smallest change justified by a named current invariant
and the accepted future target.

## Current quarantine evidence

Existing behavioral coverage already exercises the real Core boundaries:

| Boundary | Maintained proof |
| --- | --- |
| Converted multisite boots only the recovery allowlist | `tests/WordPress/BootstrapRuntimeQuarantineTest.php` |
| Fresh activation rejects before schema/wakeup work; supported activation still works | `tests/WordPress/ActivationRequirementsTest.php` |
| All manual package actions and dispatcher rejection before provider resolution | `tests/Deployment/PackageMutationGuardTest.php`, `PackageMutationGuardDispatcherTest.php` |
| Release registration/facades, package operations, storage, attempts, stale worker, webhook and Transporter refusal | `tests/Runtime/UnsupportedMultisiteMutationBoundaryTest.php` |
| Converted-installation cleanup refuses a non-main site or wrong options table before shared deletion | `tests/Uninstall/LocalDataRemoverTest.php` |

Run these cases rather than replacing them with source-text checks. This Core
groundwork adds no duplicate tests or runtime guards. Branch #107 owns the
missing real default-adapter refusal-before-mutation proof. Release #145 reuses
its existing main/subsite, fence, runtime-selection and completion tests and adds
only missing host-context composition coverage. Historical
[quarantine evidence](evidence/multisite-quarantine-2026-07-28/README.md) is not a
fixture to restore or fresh acceptance of the current tuple.

## Delivery order and deferred owners

Groundwork now: this document under
[#573](https://github.com/RocketsAreNostalgic/ran-booster/issues/573),
[Branch #107](https://github.com/RocketsAreNostalgic/ran-wp-branch-updater/issues/107)
and [Release #145](https://github.com/RocketsAreNostalgic/ran-wp-release-updater/issues/145).
Completing them leaves the following implementation deferred:

| Order | Owner | Deliverable |
| --- | --- | --- |
| P1, after ownership decisions | [Core #574](https://github.com/RocketsAreNostalgic/ran-booster/issues/574) | Package/journal/Activity/provider authority and migration/recovery policy |
| P1, after decisions; can overlap #574 | [Branch #108](https://github.com/RocketsAreNostalgic/ran-wp-branch-updater/issues/108) | Shared-file/native WordPress exclusion and exact-origin cleanup |
| P1, after state mapping | [Core #575](https://github.com/RocketsAreNostalgic/ran-booster/issues/575) | Credential/key/configuration custody, restore and recovery |
| P1, after state/lock contracts | [Core #576](https://github.com/RocketsAreNostalgic/ran-booster/issues/576) | Activation and consumer-wide deletion safety; matching Branch facts if required |
| P1, after state/credentials/shared lock | [Release #146](https://github.com/RocketsAreNostalgic/ran-wp-release-updater/issues/146) | Composed Release context/fence qualification |
| P2, after custody | [Core #577](https://github.com/RocketsAreNostalgic/ran-booster/issues/577) | Receiver, worker, retry and execution-context integration |
| P2, after backend semantics | [Core #578](https://github.com/RocketsAreNostalgic/ran-booster/issues/578) | Network permissions, administration and detach/uninstall lifecycle |

P1/P2 describe delivery order, not current defect severity. P2 remains required
before support. Independent work can overlap once its governing contracts are
settled. Existing package-update and portability
[decision registers](package-update-orchestration-decision-register.md) retain
their restrictions; this document does not reopen rejected frameworks, source
migrations or whole-site transport. Storage UI/backend and other design-only
issues retain their existing owners and scopes.

## Qualification required before admission changes

Use separately authorized disposable installations, synthetic credentials and a
recorded exact Core/Branch/Release tuple. Do not test by changing a retained site.

| Scenario | Required result and evidence |
| --- | --- |
| Supported single-site before/after | Existing activation, operations, credentials, policies and recovery remain qualified |
| Network activation; per-site activation attempt | Accepted topology initializes the intended owner once; excluded activation refuses before side effects |
| Two blogs addressing the same files | Core, Branch, Release and native WordPress agree on mutation exclusion and target state |
| Multiple networks or cross-install shared roots | Explicit refusal where ownership cannot meet the admitted target; no silent per-network write authority |
| Construct/admit in A, then change context to B | No new credential/provider/database/mutation work under mixed context; cleanup addresses only A's exact owned resources |
| Restore A after context change | Deliberately permitted continuation or explicit invalidation; no implicit retargeting |
| Network-active/site-active plugins; child/parent themes | Update preserves consumers; deletion refuses incomplete usage evidence and handles races |
| Key/file missing, mismatched, restored or partially initialized | Truthful safe failure/recovery with no overwrite or secret disclosure |
| Duplicate webhook, cron retry, restart or source change | Correct owner, deduplication, current policy and recoverable uncertain outcomes |
| Restricted actor, stale nonce or forged scope | Real action boundary refuses before access/mutation; UI visibility is not the guard |
| Site/network detach, full uninstall and failed cleanup | Retained consumers/shared resources survive narrow detach; full owned cleanup and retries are separately proved |
| Excluded topology converted from single-site | Existing quarantine/recovery remains usable without destroying shared material |

Keep source checks, artifact provenance and installed/operator acceptance
separate. Every implementation PR requires independent review of its exact base
and head and the applicable repository gates. #573 remains open through composed
qualification and the explicit support decision. Groundwork completion, passing
tests or a library release alone does not authorize changing Core admission,
merging, publishing or clearing existing acceptance holds.

Groundwork budget: production code 0, runtime/API/protocol/schema/key changes 0;
documentation only in Core. Updater test/documentation deltas are recorded in
their own changes. Any later implementation must report production, test and
documentation deltas separately before adding new concepts or persistent state.
