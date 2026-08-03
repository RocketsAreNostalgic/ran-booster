# Deployment execution

## Database admission boundary

Every managed-package or deployment-attempt storage operation first uses the
same request-cached database capability preflight as activation and schema
upgrade. The supported envelope is MySQL 8.0 or newer or MariaDB 10.11 or newer
with InnoDB available; MySQL 8.4 LTS is the production recommendation. SQLite,
PostgreSQL, unknown engines and unverified database-translation drop-ins are
rejected before custom-table access.

An already-active incompatible site is not deactivated and receives no repair
DDL. Package storage, Transporter, manual and webhook deployment, and the worker
remain paused. The webhook route stays present and returns a retry-safe
unavailable response. Read-only compatibility Troubleshooting remains
available so an operator can restore a supported database without losing the
stored tables or schema-version option.

This guide documents the current runtime deployment path in RAN Booster. It is
based on the implementation in `RAN/Deployment/` and the supporting provider,
storage, and WordPress integration types.

For the operator-level trigger sequence and every Booster, shared-updater, and
WordPress handoff for both published releases and tracked branches, begin with
the [package update orchestration guide](package-update-orchestration.md).
Before proposing a new shared updater, installer, lock, receipt, credential
lifecycle, or release/branch abstraction, also review the private durable
package update decision register.

## What the runtime path does

A deployment begins when `DeploymentCoordinator` receives a manual command or a
queued web hook attempt. The coordinator only runs after `PackageMutationGuard`
confirms the current site is a supported single-site WordPress installation and
that filesystem mutation is allowed.

The runtime path is intentionally serial:

1. Admission and claiming happen in the deployment-attempt table.
1. The coordinator prepares the provider archive and performs preflight.
1. WordPress's native `auto_updater.lock` is acquired before filesystem mutation.
1. The coordinator re-checks the target package, verifies the provider head,
   confirms the artifact, and calls the WordPress core upgrader.
1. The attempt is finished with a terminal `DeploymentOutcome`.

Branch-source manual installs and updates share this execution machinery. Web
hook entries are admitted as queued attempts and can later be claimed by the
worker path. Prospective published-release installation uses the separate
request-local release facade and does not create a deployment attempt.

## Core classes

The main runtime types are:

- `RAN\Deployment\DeploymentCoordinator`
- `RAN\Deployment\DeploymentAttemptRepository`
- `RAN\Deployment\DeploymentAttempt`
- `RAN\Deployment\DeploymentOutcome`
- `RAN\Deployment\DeploymentState`
- `RAN\Deployment\DeploymentRequest`
- `RAN\Deployment\DeploymentArchivePreflight`
- `RAN\Deployment\PackageMutationGuard`
- `RAN\Deployment\WordPressWorkerWakeup`

`DeploymentCoordinator` owns the orchestration. It accepts a manual
`PackageOperation`, validates the request shape, persists or claims an attempt,
prepares the archive, acquires the native WordPress lock, and executes the
mutation through the WordPress upgrader layer.

`DeploymentAttemptRepository` is the durable queue and journal. It stores one
row per attempt and uses the same table for admission, claim, transition, and
history queries.

`DeploymentOutcome` is the closed terminal result. It does not store arbitrary
provider output; it only stores one of the fixed outcome codes that map to a
final attempt state.

`DeploymentState` is the only persisted attempt state machine:

- `queued`
- `running`
- `succeeded`
- `failed`
- `needs_attention`

## Deployment history retention

The attempt table has a 200-row default ceiling. Admission transactions prune
only the minimum number of oldest `succeeded`, `failed`, or operator-resolved
`needs_attention` rows, ordered by creation time and then ID. `queued`,
`running`, and unresolved `needs_attention` rows are protected. If protected
rows leave insufficient capacity for a complete single, batch, or zero-target
webhook admission, that admission rolls back without deleting or partially
inserting rows.

Operators may raise the ceiling with a canonical integer from 200 through
100000:

```php
define( 'RAN_BOOSTER_MAX_ATTEMPT_ROWS', 500 );
```

An invalid or lower value uses 200 and appears in Troubleshooting. Delivery
replay memory is bounded by retained webhook rows; expected-head validation
continues to reject stale deployments after an old delivery record is pruned.
History reads remain limited to 100 rows per request and use the existing
cursor-based Load more pagination.

## Repository archive limits

GitHub and Bitbucket return an archive of the whole repository. A configured
package subdirectory is inspected after download and does not reduce the
download size. `DeploymentArchivePreflight` therefore applies one target-local,
site-wide policy to every provider deployment:

- 50 MiB compressed by default.
- 200 MiB expanded by default.
- An optional `RAN_BOOSTER_MAX_ARCHIVE_BYTES` integer in `wp-config.php` may set
  the compressed limit from 1 MiB through 512 MiB.
- The expanded limit is always four times the compressed limit.

For example:

```php
define( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES', 150 * 1024 * 1024 );
```

The effective compressed limit also bounds the provider response stream and the
initial temporary-space check. Entry-count, path-depth, package-identity,
containment and free-space checks remain independent. An invalid override fails
closed before download. The same preflight applies to provider branch-source
manual installs and updates, webhook updates, and package installation from a
Transporter Blueprint. Prospective published-release installation instead uses
the shared updater's archive custody and bounds. Neither adoption path downloads
an archive.

## Manual and web hook entry points

`DeploymentCoordinator::executeManual()` handles a protected administrator
request. For installs, it expects a complete package request that already
defines provider, repository, branch, package slug, credential ID, privacy,
and deployment policy. For updates, it reloads the managed package and compares
it to the submitted snapshot before claiming the attempt. A single manual
operation inserts and claims its row, then executes synchronously in that
administrator request. Bulk manual updates instead enter the durable queue.

`DeploymentCoordinator::acceptWebhook()` handles authenticated web hook events.
It groups matching events by package, enforces a single provider delivery, and
writes one attempt row per target. Empty deliveries are acknowledged with a
hidden delivery acknowledgement row and no worker mutation.

`DeploymentCoordinator::executeClaimed()` is the cron-only execution path for a
claimed queued attempt, whether it came from a bulk manual action or a web hook.
It refuses to run outside `wp_doing_cron()`.

## Mutation boundary

`PackageMutationGuard` defines the site envelope for package mutation.

- `assertSingleSite()` rejects multisite.
- `assertFilesystemMutationAllowed()` checks `DISALLOW_FILE_MODS` and
  `wp_is_file_mod_allowed()` before synchronous manual admission and again when
  execution begins.
- `assertWebhookDispatchAllowed()` applies the same single-site gate to web hook
  admission.
- `assertPluginFileAllowed()` prevents RAN Booster from attempting to manage
  its own plugin files.
- `assertDeploymentTargetCount()` caps web hook fan-out at 64 targets.

After archive preparation and lock acquisition, the coordinator separately
rechecks the frozen target, the provider head where applicable, artifact
identity, and maintenance state before writing the mutation fence and calling
WordPress. These checks fail closed rather than infer safety from the updater
lock.

## Booster updates

The self-target guard applies to Booster's generic repository deployment path.
Booster updates use a separate, manual WordPress-native adapter supplied by the
shared RAN WordPress GitHub Release Updater Composer package. Booster registers
its target before `plugins_loaded`; the package's request-local broker then
selects one compatible runtime when several plugins bundle the package. The
adapter checks its configured GitHub Releases feed and admits only a newer
release whose GitHub repository ID, release, tag, resolved commit, exact ZIP
asset, size and SHA-256 identify one candidate. Before the adapter offers it, the downloaded
ZIP is re-hashed and inspected for one eligible root plugin PHP header or a
theme's root `style.css`; its version must match the normalized tag and its
Update URI must identify the configured GitHub repository. A two-component
WordPress header such as `2.1` is equivalent to canonical tag `v2.1.0`. A
mismatch, missing or malformed header, unreadable archive, zero ZIPs or
multiple ZIPs is cached briefly and treated as no update. Network failures,
unavailable releases, rate limits and invalid metadata receive the same
fail-closed treatment.
Prerelease Booster versions use the prerelease channel, stable versions use the
stable channel, auto-update remains forced off, and WordPress Core retains
update scheduling, UI and replacement. No web hook or managed deployment
attempt may replace Booster itself.

The current Core repository is private and the shipped Core registration has
no credential. Anonymous discovery therefore fails closed; the temporary
process-only token shim used for the hosted release proof is not a supported
private Core credential path.

## Attempt records

`DeploymentAttemptRepository` projects the 22-column deployment attempt row into
`DeploymentAttempt` objects. The runtime model keeps the row narrow and does not
store arbitrary request bodies or raw provider responses.

The key fields are:

- `source`: `manual` or `webhook`
- `operation`: `install` or `update`
- `package_type`: `plugin` or `theme`
- `package_slug`: the managed package identity
- `provider` and `provider_repository_id`: the provider identity pair
- `requested_ref` and `resolved_ref`: the requested ref and the provider-verified
  immutable ref
- `delivery_id` and `delivery_digest`: webhook delivery identity when relevant;
  administrator-only Activity displays `delivery_id` as **Provider request ID**
  for cross-reference with provider delivery history
- `state`: the current `DeploymentState`
- `mutation_started_at`: the mutation fence
- `outcome_code`: the closed terminal result code
- `request_json`: the canonical execution snapshot

`DeploymentAttempt::fromDatabase()` enforces the integrity rules for those
fields. For example, queued rows cannot already contain a mutation fence, and a
terminal row must contain both an outcome and a finished timestamp.

Core does not retain the raw webhook body, signature headers, or provider-side
duration. A provider timeout can occur after durable admission, so GitHub or
Bitbucket delivery history remains authoritative for status and timing. Probes,
ignored events, and zero-target deliveries may create no attempt; absence from
Activity is therefore inconclusive.

## Request-wide bootstrap characterization

Every supported single-site request loads the same Core bootstrap before
WordPress can distinguish a frontend, REST, admin, cron, WP-CLI, or updater
consumer. Managed release registration performs two package-list queries, then
one exact release-configuration query for each eligible release-managed target;
it also checks and hydrates returned package paths. Saved credential material
remains lazy and is not read merely to register those targets.

That eager registration is required before the updater broker fixes its
request-local target selection at the earliest `plugins_loaded` callback.
Deferring it based on `REST_REQUEST`, URI shape, admin, cron, or WP-CLI state
would make native update interception depend on an incomplete request
classifier. The Webhook V1 decision is therefore:

- **GO:** route registration itself performs no database lifecycle check and no
  failure log write. Storage readiness remains enforced when an authenticated
  delivery reaches admission.
- **NO-GO:** do not add request-wide lazy loading, caching, or context gates.
  The two list queries plus per-release-target lookups remain an acknowledged
  request-wide cost until an updater-owned contract can prove a smaller safe
  boundary.

For unrelated REST requests, this removes the only extra webhook-specific
bootstrap operation: the former schema check and possible repeated failure log.
For matched webhooks, the route now proceeds directly to bounded provider,
request, signature, and storage handling. No option, schema, cache, public API,
or persistent state is added by this decision.

## Recovery behavior

The terminal outcome codes are defined in `DeploymentOutcome`:

- `deployed`
- `no_change`
- `provider_failed`
- `provider_request_invalid`
- `provider_credential_rejected`
- `provider_access_denied`
- `provider_repository_missing`
- `provider_reference_unavailable`
- `provider_rate_limited`
- `provider_unavailable`
- `archive_compressed_too_large`
- `archive_expanded_too_large`
- `archive_limit_invalid`
- `preflight_failed`
- `downgrade_blocked`
- `lock_unavailable`
- `policy_blocked`
- `stale_event`
- `upgrader_failed`
- `activation_failed`
- `worker_stopped`
- `interrupted`
- `restoration_uncertain`
- `maintenance_remaining`
- `installed_version_mismatch`
- `activation_state_changed`
- `persistence_uncertain`

The code-to-state mapping is fixed:

- `deployed` and `no_change` finish as `succeeded`.
- operational failures finish as `failed`.
- `interrupted`, `restoration_uncertain`, `maintenance_remaining`,
  `installed_version_mismatch`, `activation_state_changed`, and
  `persistence_uncertain` finish as `needs_attention`.

This is the visible recovery contract: Booster records what happened, keeps the
attempt row, and leaves ambiguous results for operator reconciliation instead of
pretending they succeeded.

## Background failure notifications

After a webhook attempt reaches `failed` or `needs_attention`, Booster follows
WordPress's background-update pattern:

- the site administrator receives one email containing only the package slug,
  provider code, closed outcome message, support reference, and Deployment
  activity link;
- administrators see a dismissible error notice across WordPress administration
  screens;
- an affected managed plugin receives an inline error row on the Plugins screen;
  and
- Deployment activity remains the durable source of detail.

The notification path runs only after the terminal attempt has been written.
Email failure cannot change the deployment result or cause the package operation
to run again. Notice dismissal is per user and is fingerprinted from the current
set of failed attempts, so a newly failed attempt reappears. The current status
for a package is cleared when its newest attempt is no longer a webhook failure.
No credential value, provider response body, request header, repository secret,
or signed URL is included in the notice or email.

## What to read next

- [Package update orchestration](package-update-orchestration.md)
- [Provider extension contract](provider-extension-contract.md)
- [Custom git vendor setup](custom-git-vendors.md)
