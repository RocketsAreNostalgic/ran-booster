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

No transaction spans provider calls or filesystem mutation. If a process stops
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
