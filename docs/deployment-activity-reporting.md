# Deployment activity reporting

## Bounded implementation decision

Core must commit the deployment transition and its Activity projection together,
and both existing stores must apply one ordinary-history policy. Independent
retention settings would leave the accepted requirement incomplete. A new policy
class, event queue or outbox is unnecessary for these two existing stores on the
same database connection.

The follow-up repair is allocated 60 added / 25 removed production lines
(35 net): one shared-policy method on ActivityStore, existing journal pruning,
and the deployment slug projection. It adds zero concrete types or persistent
state and two site configuration keys. Final measured deltas are recorded in
the PR.

## Persistence and readback

The engine-review repair is allocated 20 added production lines in the existing
ActivityStore, with no new type, schema or public reporting interface. Coupled
correlation writes must inspect the live InnoDB engine while holding the table's
metadata lock inside the journal transaction. A schema marker or install-time
check alone cannot guarantee rollback after a later table-engine change.

The deployment-attempt table remains the operational ledger for queue admission,
worker recovery, mutation fences and operator resolution. The Activity table is
the source for the Troubleshooting Activity list and its bounded history.

Each deployment attempt uses its journal correlation as the Activity correlation.
Core creates the incomplete Activity row in the same InnoDB transaction as
admission. Queue claims, resolved-reference and mutation progress, terminal
outcomes, confirmed worker reconciliation and operator review update that row
inside the transaction that changes the journal. A missing or rejected Activity
write aborts that transaction. The reporting API therefore cannot claim a
terminal result while the corresponding journal transition rolls back, and the
journal cannot admit new work without its Activity row.

Caller-supplied correlation admission and correlation-addressed progress/completion
inspect the live Activity table engine and required indexes before writing. Their table read holds a
metadata lock through the caller's transaction, preventing a concurrent engine
conversion between verification and commit. A non-InnoDB or unreadable table
rejects the write so the journal transaction rolls back; it cannot leave an
orphan report. Verification requires the full primary ID, unique correlation,
history and context-summary indexes; a prefix or composite correlation index
does not satisfy correlation uniqueness. Installation verifies the same contract.
History reads do not inspect schema. This guard performs no DDL or automatic
engine conversion.

For these journal transitions, Activity is a deployment dependency: unavailable
or incompatible reporting storage blocks the transition. This is the accepted
consistency trade-off. Ordinary optional check reporting retains its separate
failure policy. A database rollback cannot undo a filesystem mutation; an
uncertain outcome remains recoverable through the operational journal.

No transaction spans Git provider calls or filesystem mutation. If a process stops
after a filesystem mutation begins, the Activity row remains incomplete until
the operational ledger can confirm or reconcile the outcome. A needs-attention
row stays visibly uncertain; operator review records progress but does not turn
uncertainty into success or failure.

Activity facts contain only the bounded deployment projection needed by the
history view: attempt ID, package slug, manual/webhook origin, state, requested and resolved
references, mutation and finish times, operator-review time and user ID, and
the normalized outcome code. The
repository credentials, remote payloads and full deployment request are not
copied into the Activity record. The activity list reads rows from the Activity
store only. The deployment ledger remains available for explicit detail and
recovery actions.

Both stores use one ordinary-history policy, defaulting to 30 days and 2,000
rows. Sites may set `RAN_BOOSTER_HISTORY_DAYS` (integer 1–365) and
`RAN_BOOSTER_HISTORY_ROWS` (integer 200–100000). Invalid settings use the
corresponding default and are reported by Troubleshooting. The former
`RAN_BOOSTER_MAX_ATTEMPT_ROWS` setting is retired; clean-start has no compatibility
alias. The two datasets have different row populations and safety exceptions,
so shared settings do not promise identical retained membership.

Activity pages have a limit of 25. Queued/running deployment rows and unresolved
needs-attention rows remain visible outside those history windows and are
excluded from Activity pruning. Once an operator records a review, that row
returns to ordinary history retention; review records the decision without
inventing a deployment outcome. The query remains page-bounded even when older
unresolved work is present.

Journal admission first prunes at most 100 aged terminal/resolved rows, then
reserves space against the same configured row ceiling by removing only eligible
oldest records. Queued, running and unresolved records cannot be removed to make
room; an admission with insufficient capacity rolls back. Retained delivery
acknowledgments continue to bound replay memory. Activity pruning is also bounded
to 100 candidates and protects current-context summaries as well as unresolved
deployments. Maintenance is performed during lifecycle work, with no reader DDL
or new scheduler.
This change does not import pre-existing journal history; clean-start adoption
remains the rollout policy.

## Storage review at the expected workload

The primary workload is approximately ten managed plugins/themes, with modest
headroom around 20–50. Package rows remain current management state, attempts
remain operational authority, and Activity remains reporting. Temporary debug
capture has a separate lifecycle. Earlier 200-row journal/evidence limits were
storage limits, not verified plugin-count caps. A possible agency service would
coordinate separate sites and does not justify fleet infrastructure here.

A deployment creates one Activity row per admitted target; progress and outcomes
update it. Branch checks are explicit actions; release checks begin on updater
discovery events. Workflow, webhook, credential validation and requested
diagnostic operations also create rows. History and package-summary rendering
create none. As an illustrative assumption, two checks per package per day plus
two other operations gives 22/42/102 daily rows at 10/20/50 packages, or
660/1,260/3,060 over 30 days. Actual cadence depends on invoked operations; it has
not been measured on a production site.

Daily Activity maintenance attempts at most 20 batches of 100 deletions. Under
that illustrative workload, seven missed days add 154/294/714 rows, ordinarily
recoverable in one maintenance run. Safety exclusions, concurrent changes and
storage errors can reduce deletion throughput. Retention is a target with safety
exceptions, not an absolute table-size guarantee. The 100,000-row configuration
ceiling remains unchanged, but is not a qualified workload or product target.

Executed presenter measurements show 30/60/150 summary statements for 10/20/50
packages, with zero writes. Native CI measured ten-package summary SQL at about
9–20 ms with 2,000 fixture rows; this does not justify a new batch-summary API.
These timings are disposable-CI evidence, not production-site latency promises.

The prior pruning batch used 1/11/101 statements for 0/10/100 candidates. Native
CI measured 100 deletions at 0.65–1.19 seconds with 2,000 rows and 2.9–5.5 seconds
with 10,000 rows. That demonstrated cost justifies a small bounded change, allocated
at most ten net production lines with no new type, public seam or persistent state.
Pruning now selects at most 100 candidates and deletes that set in one statement,
retaining each captured status and rechecking current observation and deployment
safety protections. Empty batches remain one read; nonempty batches use two
statements. The native concurrent-completion proof also checks that an unchanged
candidate is deleted while a newly protected observation survives. The same
fixture measurement records the resulting timings without a timing gate. No
continuation mechanism, cache or new scheduler is added.

The two configuration option stores use bounded compare-and-swap retries, with
cache refresh, so updates to different entries cannot overwrite one another.
Credential retries apply only the requested fields, preserving concurrent manual
and provider observations. Exhausted retries retain the existing explicit save
failure. Branch verification already uses fresh reads under its mutation lock.

## Branch working-copy refusal

The published branch reason journal refines only `policy_blocked` with the typed
`TARGET_IS_WORKING_COPY` reason. Core ignores foreign detail and all other reasons.
Cleanup supersession and post-fence observations cannot create this explanation.

Core passes the existing observed attempt to the terminal transaction. Selection,
adoption, attempt ID, correlation, request and pre-fence state must still match.
A changed observation omits the fact; failed storage still aborts the transaction.
The terminal refinement opts into strict selection reads, including nested reads.
One-shot SQL failures and malformed authority escape to the transaction owner;
confirmed unavailable providers remain ordinary mismatches. Other callers stay tolerant.
The failed refinement transaction writes no terminal outcome, Activity completion
or refusal fact. Under #842, the coordinator then settles the stopped runner in a
fresh transaction: `worker_stopped` before the fence, or `interrupted` after it,
with `deployment_settled_by: runner_throw`. Its finished log follows commit.
If that settlement also fails, the attempt remains running without a finished log.
A healthy retry through the coordinator is a new attempt, not replay of a settled row.
The completion and optional Activity fact `branch_refusal: target_is_working_copy`
commit together. The existing repository completion log receives that singleton
only after commit. The coordinator's separate completion log remains generic.

Manual failure feedback, the background notice and Activity list/detail use one
fixed Core sentence from exact retained, correlated Core install/update evidence.
Missing, pruned, unavailable or contradictory evidence keeps generic policy copy.
HTTP status, outcome, correlation and signed feedback transport remain unchanged.
Attempt JSON, email/filter data and support exports omit the optional reason.

Credential validation and branch archive or head failures may record the original
provider failure's screened `source_code` as a diagnostic Activity fact. It never
selects an outcome or sentence. Branch settlement copies it from that attempt's
carried failure only when the runner reports the same outcome. Recording the
reference adds no selection or adoption reads or locks. The working-copy fact
retains its independent authority checks. Cleanup supersession and runner-throw
reconciliation omit the reference.
Rendering re-screens the stored reference for Core failed or rejected install,
update and credential-validation rows. It does not re-prove the writer's attempt
binding or interpret the code as an outcome or provider identity.
Invalid optional codes are omitted without discarding the outcome. The attempt
snapshot, failure email and support export do not include the code.

Native release message replacement remains held pending a suitable producer
boundary. Other branch reasons need separate work.

## Public actions

Site code and audit tools may use these WordPress actions. Each is per site: it
fires in the site whose tables recorded the event and keeps no network-wide
state. Booster still supports single-site WordPress only.

### `ran_booster_activity_recorded`

Fires once when an Activity record's outcome is stored, whatever the operation.
Listeners filter on `operation`: `install` and `update` are deployments; the
others include `release.check`, `branch.check`, `credential.validate`,
`diagnostics.run`, `webhook.*` and `workflow.*`.

The one argument is an array holding only these fields:

| Field | Value |
| --- | --- |
| `correlation` | The record's 32-character reference. |
| `operation` | The operation name, or empty. |
| `provider` | The provider code. |
| `subject_type` | `plugin`, `theme`, `repository` or `provider`. |
| `subject_identity` | The package, repository or provider the record is about, at most 255 bytes, or empty. |
| `status` | `succeeded`, `failed`, `rejected`, `skipped` or `needs_attention`. |
| `reason` | A bounded reason code, or empty. |
| `actor` | The WordPress user ID, or `0` when none is recorded. |
| `started_at` | UTC, `Y-m-d H:i:s`. |
| `completed_at` | UTC, `Y-m-d H:i:s`. |

Messages, remediation text, facts, source context and adoption tokens are not
passed.

Values are screened when the action fires, so older records get the same
treatment. `operation` and `reason` are empty unless they pass Booster's
identifier screen. `subject_identity` is empty unless it has the shape of its
subject type, with no whitespace, and is always empty for a repository identity
that a provider reported. That is a shape check, not secret detection.

A deployment's action fires after the journal transaction commits, so a
rolled-back outcome is not announced. Other outcomes fire straight after their
own write. When nothing listens on `ran_booster_activity_recorded` or `all`,
Booster neither reads the row back nor fires the action. When a listener is
attached, the action fires only if the stored record still holds that outcome;
otherwise, for example when a database session is lost before its commit,
Booster logs one code-only `activity.recorded` / `unconfirmed` line if
WordPress debug logging or Booster's capture is on. A lagging read replica or an
operator resolving a `needs_attention` attempt between `COMMIT` and that
read-back can likewise suppress (never falsely fire) an announcement. Starts,
progress, an operator's review and a repeated skip that Booster discards fire
nothing.

Providers must report completion only after their own transaction commits. A
completion reported inside an open transaction may be announced before it is
durable.

Listeners run synchronously in the request or worker that recorded the outcome,
so keep them quick and hand slow work off. A listener's exception propagates to
the caller, as with any WordPress action. The outcome is already stored, but the
caller's later work is skipped: a deployment skips its finished log, background
failure notification and next worker wakeup, and manual package or recovery
actions report the stored result as failed; credential validation skips saving
the expiry observation and sidecar date and shows an error for the stored
result; a branch check reports a stored `verified` or `branch_unavailable`
result as `unable_to_check`; the priority-1 release-check listener aborts the
updater loop so `CoreSelfUpdateCheck` records `orchestration_failed`; webhook
Validate or Repair skips its chained `test` ping; and where an outer `catch`
swallows the exception (such as webhook push and delivery observations), it logs
an Activity recording failure and leaves the hook on WordPress's current-filter
stack for the rest of the request. Catch whatever your listener can throw.
Delivery is not guaranteed, for example if the process stops after the write.
Activity remains the record.

```php
add_action(
	'ran_booster_activity_recorded',
	static function ( array $record ): void {
		if ( in_array( $record['operation'], array( 'install', 'update' ), true ) ) {
			error_log( 'Deployment ' . $record['status'] . ': ' . $record['subject_identity'] );
		}
	}
);
```
