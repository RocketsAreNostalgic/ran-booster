# RAN Booster

An internal WordPress plugin for managing theme and plugin deployments from repository providers.

This repository began as a GPL fork. The inherited GPLv2 text and source
provenance are retained in [license.txt](license.txt) and [NOTICE.md](NOTICE.md).
RAN Booster has no licensing, updater, cloud OAuth, or repository-picker dependency
on the original vendor. GitHub is bundled and optional provider add-ons use
the same public contracts.

## Project history

RAN Booster began as a GPL fork of WP Pusher 3.0.13, a deployment plugin created
by Peter Suhm. We are grateful for Peter's original work and its central idea:
use WordPress's updater to deploy plugins and themes from version-controlled
repositories. That idea remains the foundation of RAN Booster.
WP Pusher changed ownership in 2021 and is now a separate product. RAN Booster
is an independent fork maintained by Rockets Are Nostalgic and is not affiliated
with or endorsed by Peter Suhm, WP Pusher, or its current owners. Source
provenance and licensing are recorded in [NOTICE.md](NOTICE.md) and
[license.txt](license.txt).

The inherited release relied on vendor-hosted licensing, OAuth, and external
repository-picker services. RAN Booster replaces those with site-controlled
provider credentials, signed target-local webhooks, explicit deployment policies,
and validated repository archives.

WP Pusher 3.0.13 shipped with built-in support for several Git hosts through its
vendor-hosted OAuth relay. RAN Booster bundles GitHub. Additional providers can be contributed
through a documented `ran_booster_register_providers` hook without modifying
Booster itself (see [provider extension contract](docs/provider-extension-contract.md)).
The [provider registration and coexistence characterization](docs/provider-registration-and-coexistence.md)
records the current exact-code collision protections and their limits.
Premium functionality contributes to existing Core screens through the
[WordPress-native administration composition
contract](docs/admin-composition-contract.md). Add-ons whose workflow genuinely
requires a separate dashboard surface may instead use the retained public
add-on tab API documented by the same contract.
Add-on-owned mutations on eligible Core surfaces can use the
[enhanced administration interaction API](docs/enhanced-admin-interactions.md)
for Core-managed HTMX refreshes, busy states, persistent errors and transient
success feedback without shipping their own UI updater.

## Custom git vendors

RAN Booster supports custom git vendors through the
`ran_booster_register_providers` action. Use it to register a new provider,
define its metadata and capability contracts, and wire in diagnostics,
credential policy, repository browsing, and webhook handling as needed.
Provider plugins must require exact Provider API 10. Core publishes no add-on
logging facade; providers return bounded diagnostics and operation results.
An unexpected provider failure may travel only as request-local diagnostic
evidence for Core to log at its troubleshooting boundary; it is never included
in the serialized result or administrator copy.
WordPress's `Requires Plugins` header can express the package dependency, but it
does not prove contract compatibility. Every provider or ordinary add-on must
also fail closed unless each API marker it consumes is present at the exact
documented generation.

Core publishes no global service-container accessor, credential writer, bulk
credential plaintext enumerator or deployment repository. Ordinary add-ons
receive only purpose-specific facades. A credential-bearing provider factory
receives a read-only credential store and authenticated webhook-delivery
evidence reader, each permanently bound to its own provider code; neither
accepts a provider argument. These supported-contract limits do not claim
confidentiality from hostile PHP running in the same WordPress process.

GitHub remains a bundled, Core-owned provider module. Provider API 10 makes that
module obey the same ordinary vendor boundary as external providers; it does
not authorize extracting GitHub into another package or release stream.

Start with the [custom git vendor setup guide](docs/custom-git-vendors.md).
Before choosing a provider code, also read the current
[registration and coexistence behavior](docs/provider-registration-and-coexistence.md).

GitHub recommends fine-grained personal access tokens for scripted and automated
API access. Booster keeps provider credentials as authenticated ciphertext in an
owner-only private JSON file outside
the WordPress and plugin directories. Its independent key is stored in the
non-autoloaded `ran_booster_secrets_key_v1` WordPress option. WordPress
deployment constants remain available for explicitly configured credentials.

## Runtime requirements

- WordPress 7.0 or newer
- PHP 8.2 or newer
- PHP Sodium extension
- Single-site WordPress; multisite is not supported in this Alpha
- MySQL 8.0 or newer or MariaDB 10.11 or newer, with InnoDB available

MySQL 8.0 is the tested compatibility floor; MySQL 8.4 LTS is the production
recommendation. SQLite, PostgreSQL, and unverified `db.php` database-translation
drop-ins are not supported.

Fresh activation fails before plugin side effects when either encrypted-storage
requirement is missing. An already-active site remains bootable after an update,
shows a persistent warning on Booster and Plugins screens, and pauses managed
credential and webhook operations; anonymous public-repository and package-only
Transporter Blueprint paths remain available.

Fresh activation also fails before schema changes when the database requirement
is not met. If an active site is moved outside the supported database envelope,
Booster leaves its tables and schema-version option unchanged, keeps read-only
compatibility Troubleshooting available, and pauses package storage,
Transporter, manual and webhook deployment, and the deployment worker. Restore
a supported database before resuming those operations; Booster does not
deactivate itself or attempt automatic database conversion.

Complete database copies within the supported MySQL/MariaDB and InnoDB envelope
are best effort. Before a database move:

1. Export a current Blueprint containing every managed package.
2. Explicitly select any eligible file-stored credentials that the target
   needs; they are copied only inside the password-protected archive.
3. Preview the ZIP successfully and retain it off-site.
4. Keep the normal database and filesystem backup.

A Blueprint is the supported reconstruction route when raw Booster tables
cannot be trusted, but its target must still meet the database requirements.
It does not carry deployment-attempt or delivery-replay history, webhook
secrets or provider-side hooks, constants, locks or worker state, or the source
deployment policy. Every installed or adopted package starts with deployment
**Disabled**. Cross-engine table migration is not a Booster feature.

Every carried credential requires an explicit target choice: import the copied
material, use a current saved credential for the same active provider, or leave
its packages unchanged. There is no credential or anonymous fallback. Import
does not remove, revoke or rotate the source credential, and Blueprint V1
cannot reconstruct a source-local automatic-removal date from either current or
legacy archives.

Database schemas 8 and 9 existed only in untagged development checkouts and
will not be migrated by the next release. Before updating such a checkout, keep
a database backup and export a current Blueprint. If the development version
reports an unsupported old schema, do not change only the schema-version
option: restore the matching checkout long enough to export, then uninstall it
and install a supported release before applying the Blueprint.

RAN Booster is distributed under GPL-2.0-only, the conservative interpretation
of the upstream package's `GPLv2` declaration. The canonical repository is
[RocketsAreNostalgic/ran-booster](https://github.com/RocketsAreNostalgic/ran-booster).
Released changes are recorded in the Release Please-owned
[CHANGELOG.md](CHANGELOG.md). Accepted unreleased Conventional Commits are
summarized in the active Release Please proposal when one is open; dirty local
work is in neither record.

## Current status

- **Extensions** — the Core-owned Extensions page lists the remaining first-party
  beta extensions using local WordPress plugin state and release-bundled
  artwork. Free downloads remain disabled until their public repositories and
  releases are ready for people; Sponsor install controls remain disabled
  and link separately to access information.
- **GitHub** — public repositories need no credential. Private repositories use
  named personal access token profiles: narrowly scoped fine-grained tokens for
  known resource owners, or a classic token when one credential must span several
  organisations. The GitHub tab co-locates Push-to-Deploy secret creation with
  the callback URL, event, repository context and manual setup links. Successful credential
  validation records GitHub's token-expiration response header when GitHub
  supplies it.
- **GitHub webhook management** — Core sets up, checks, reconfigures and removes
  GitHub repository webhooks using a fine-grained token with Webhooks: Read and
  write permission. An administrator can paste a request-only token or select
  an eligible saved GitHub credential. Core resolves a saved token only inside
  the fixed operation and never exposes the credential or signing secret to UI
  code. The GitHub repository table and selected-repository panel use the same
  provider-scoped, display-safe site and repository readiness result, including
  public HTTPS, repository identity and local signing-secret coverage. GitHub
  signing secrets are either bound to a canonical GitHub organization or user
  and shared by its repositories, or bound to one stable GitHub repository ID.
  Exact repository scope takes precedence. Assisted setup reuses an applicable
  Core profile or creates an exact repository profile; explicit reconfiguration
  sends the current Core secret and callback settings to the identified remote hook.
  Replacing a secret remains a separate Core action. A saved local secret does
  not prove that a remote hook exists, so the add-on labels remote state as
  last observed. The add-on never enables Automatic deployment; manual webhook
  setup remains available without it. Each assisted operation rechecks the
  stable repository identity with the same saved or request-only credential and
  takes a target-keyed, non-persistent database lock before remote work.
- **Release Deployments add-on** — contributes release status and actions to
  Core's managed Plugins and Themes tables and appends package-specific
  settings through the same bounded WordPress-native composition contract.
  Core renders the shared rows and controls; the add-on owns capability- and
  nonce-checked WordPress handlers, while Core's facade independently
  reauthorizes and performs mutations. When the selected updater runtime
  supplies updater prospective API 4, Core publishes its independent
  Prospective Release API 5. Its local `supportedProviderCodes()` projection lets callers
  keep unsupported providers out of the prospective workflow before any
  repository check. Callers explicitly choose the bounded `stable` or
  `prerelease` channel for discovery, inspection and installation; discovery
  returns bounded metadata without downloading a ZIP. Inspection downloads,
  validates and discards the exact ZIP; install performs a second,
  fingerprint-bound acquisition and verifies archive bounds, GitHub and local
  SHA-256, headers, Update URI and package identity before WordPress Core
  installs. The target must be absent and
  inactive, remain inactive, pass installed identity checks and then be adopted
  by Booster. `installed_but_unmanaged` is an umbrella partial outcome: inspect
  installed version and activation before linking or retrying; uncertain-state
  and cleanup failures remain distinct. A missing capability hides only the
  prospective first-install choice; existing managed-release controls remain
  usable. The add-on has no separate Booster screen, although the public add-on
  tab API remains available to other add-ons.
- **Common package automation and repair** — package settings use one
  **Automation** control for both sources: Disabled prevents Booster-managed
  replacement, Manual requires an explicit action, and Automatic permits the
  source's normal automatic path. Branch **Reinstall** deliberately replaces
  the saved branch copy, including local changes, after confirmation; it is not
  newer-release discovery. Published-release packages instead use WordPress's
  native **Update** path for eligible newer releases. Exact installed-release
  Reinstall is intentionally unavailable because a safe durable post-mutation
  recovery contract was not justified; see the
  [package update orchestration guide](docs/package-update-orchestration.md).
- **Confirmed package removal** — the settings-page **Danger zone** separates
  **Unlink** (stop Booster management while preserving files and WordPress
  activation) from **Unlink and delete**. The latter is separately confirmed,
  source-revision fenced and lock-protected; plugins are deactivated and use
  WordPress's uninstall/delete path, while active themes, parent themes,
  dependencies, unsafe paths and in-flight package work are refused.
- **Provider add-ons** — compatible provider add-ons appear beside GitHub and
  retain their own credential, webhook and documentation guidance.
- Credentials come from deployment constants or the encrypted JSON file selected
  on Booster's protected Overview. Prefer an absolute private directory through
  `RAN_BOOSTER_ENCRYPTED_SECRETS_DIR`; the operator creates that owner-only
  `0700` directory and Booster manages `secrets.json` and its lock within it.
  The older `RAN_BOOSTER_ENCRYPTED_SECRETS_FILE` exact-file constant remains a
  transitional fallback. Both constants must evaluate to absolute paths. To
  express a location relative to `wp-config.php`, anchor it in that file with
  PHP's `__DIR__` and normalize it without `..`, for example
  `define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', dirname( __DIR__ ) . '/private/ran-booster' );`.
  The legacy exact-file equivalent is
  `define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', dirname( __DIR__ ) . '/private/ran-booster/secrets.json' );`.
  Raw relative strings are rejected because web, cron, and CLI processes may
  use different working directories. No secret plaintext is stored there or in the
  WordPress database. Conventional single-site POSIX installations can use
  automatic setup. File-backed profiles are structurally validated for display; their
  current provider validity is checked only when the selected credential or
  bounded webhook candidates are used.
- When the protected Overview reports a storage error, it keeps the manual
  override instructions visible. Replace an existing exact-file definition
  with `RAN_BOOSTER_ENCRYPTED_SECRETS_DIR` in `wp-config.php`, or set the
  directory constant from a non-empty environment variable there before
  WordPress loads plugins. Booster does not read an environment variable by
  itself. Automatic
  paths are based on the private parent of the detected WordPress boundary;
  that is usually operator-accessible on conventional
  `/home/account/public_html` hosting, but container deployment roots such as
  `/var/www/<deployment-id>` may require an explicit durable private path.
- A credentials restore requires the matching encrypted sidecar and
  `ran_booster_secrets_key_v1` database option from the same backup. Neither
  half is useful alone.
- Credential profiles accept an optional non-secret expiry date. A provider-reported
  expiry takes precedence after validation; providers without equivalent metadata use the manual date. Administrators
  receive a dismissible WordPress-admin notice at 30 days, an urgent notice at
  seven days, and an expired notice at the deadline. Unknown never means
  non-expiring, and Booster does not poll, email, generate, rotate, or refresh
  credentials automatically.
- Administrators can additionally enable **Credential Self Destruct** for a
  saved credential and choose a local removal date. Core withholds it once the
  earlier of that date or a trusted provider-reported expiry has passed, then
  removes the encrypted record on the next Booster admin request. This local
  retention setting is independent of the advisory expiry reminder.
- The PHP namespace is `RAN`, isolated from the upstream plugin.
- Managed Plugins and Managed Themes support WordPress-style bulk selection.
  Policy changes are atomic across the selection. Branch-only bulk
  **Reinstall** admits each safe package to Booster's existing queue, reports
  ineligible packages by reason, and deploys one package per worker run with an
  individual activity record. There is no cross-package rollback or durable
  batch record.
- Public GitHub Releases provide manual WordPress-native Booster updates through
  the shared RAN WordPress GitHub Release Updater Composer package. Its
  request-local broker safely arbitrates multiple bundled copies, while
  repository, tag, commit, exact asset, size, SHA-256 and embedded WordPress
  package-header bindings constrain an eligible update. A verified archive must
  identify the same release version as its GitHub tag before WordPress is
  offered an update. Booster follows prereleases only while its
  installed version is a prerelease, never enables auto-update, and leaves
  scheduling, notices and replacement to WordPress Core. This updates Booster
  itself; it is separate from the managed Release Deployments workflow for
  managed packages.
- Booster has no durable operational log. Bounded deployment outcomes live in
  Deployment activity and explicit checks live in Troubleshooting. The Logging
  panel can temporarily retain up to 400 sanitized Booster events for one hour
  without enabling `WP_DEBUG_LOG`; it is kept for no more than 24 hours after
  capture ends. It omits PHP, WordPress, theme, and other-plugin messages. If
  `WP_DEBUG_LOG` is already enabled, Booster also writes the same structured
  `[ran-booster]` events to WordPress logging without credential secrets, PAT
  values, or signed download URLs.
- A failed webhook deployment is surfaced outside Booster as a dismissible
  administrator notice. Affected managed plugins also receive a WordPress-style
  error row on the Plugins screen, and the site administrator receives one
  safe, actionable email when the attempt finishes. A newer attempt for the
  same package replaces the old status; manual deployment failures remain in
  Deployment activity without generating background-failure email.

## Feature comparison

RAN Booster adds a stronger deployment-truth and recovery model on top of
WP Pusher's core idea. The tables below compare RAN Booster to WP Pusher
3.0.13 specifically — the version this project forked from, not WP Pusher's
current product.

### Authentication and access

| Feature                 | WP Pusher 3.0.13        | RAN Booster                                                                                                                            |
| ----------------------- | ----------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| Provider authentication | Vendor-hosted OAuth     | Site-controlled credential profiles; never stored in the database                                                                      |
| Repository picker       | External vendor service | Native picker for bundled and compatible add-on providers; no external service dependency                                              |
| Credential storage      | Vendor-managed          | Authenticated encrypted credentials file (mode `0600`) outside the plugin directory and database; deployment constants can override it |

### Deployment safety

| Feature                             | WP Pusher 3.0.13         | RAN Booster                                                                                                                                               |
| ----------------------------------- | ------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Deployment outcome record           | Best-effort admin notice | Durable attempt journal with five explicit states (`queued` → `running` → `succeeded` / `failed` / `needs_attention`)                                     |
| Repository identity                 | —                        | Deployments are bound to a provider-issued stable repository identity and an immutable commit                                                             |
| Stale or replayed webhooks          | —                        | Rejected instead of being applied, so a delayed notification cannot roll back code that has already been updated                                          |
| Download verification               | —                        | Checks the archive's exact bytes, package identity, version, and file paths before touching the filesystem                                                |
| Result verification                 | —                        | Confirms the package's active state and files after WordPress Core finishes; an unclear result is flagged as `needs_attention`, never reported as success |
| Recovery from an interrupted deploy | No recovery path         | A guarded reconciliation action; Booster never silently takes over a stuck deployment                                                                     |
| Deployment policy                   | —                        | Disabled / Manual / Automatic, set explicitly per package or selection                                                                                    |

### Push-to-Deploy and operations

| Feature            | WP Pusher 3.0.13 | RAN Booster                                                                   |
| ------------------ | ---------------- | ----------------------------------------------------------------------------- |
| Push-to-Deploy     | Vendor-mediated  | Signed, site-local webhooks; configured manually per target                   |
| Diagnostics        | —                | On-demand, per-provider checks in Troubleshooting; nothing is persisted       |
| Deployment history | —                | Bounded Deployment activity with a stable support reference for every attempt |

For a webhook attempt, Activity also shows the provider's existing delivery
identifier as **Provider request ID**. Use that only to cross-reference GitHub
or Bitbucket delivery history, which remains authoritative for duration,
response status, timeout, and redelivery. A timeout can occur after Booster has
durably admitted work, while probes, ignored events, and zero-target deliveries
may create no Activity row.

GitHub does not automatically redeliver failed deliveries. For Bitbucket,
enable Request History before you need it and treat its request UUID only as a
cross-reference; do not assume it remains stable across automatic attempts.

HMAC protects deployment authority after WordPress accepts the request; it does
not protect the network, web server, PHP workers, or WordPress bootstrap from
traffic. Keep both WordPress REST callback forms uncached and untransformed.
Optional host or trusted-edge limits and current provider IP ranges are defence
in depth and never replace HMAC.

Switching a package from Branch to Published releases does not remove an
existing provider webhook or local signing-secret setup. The release-managed
package ignores pushes, but another branch-managed package using the same
repository may still need that hook. Retaining it is useful for a temporary
source switch.

For a long-term release source, site or repository retirement, or a callback or
credential change, review the retained setup. First confirm that no
branch-managed package still needs it. Remove the remote provider webhook
before deleting an unused local secret, and preserve owner-shared secrets or
other profiles that still serve branch packages. GitHub webhook management can
remove an identified hook through its verified Remove workflow. For other
providers, remove the hook in the provider UI, then use the provider screen's
**Manage secrets** action to remove only an unused local secret.

### Transporter and extensibility

| Feature            | WP Pusher 3.0.13    | RAN Booster                                                                  |
| ------------------ | ------------------- | ---------------------------------------------------------------------------- |
| Site migration     | Copy files manually | Password-protected blueprint ZIP; no development checkout required           |
| Provider extension | —                   | Documented, fixture-tested registration hook for adding custom git providers |

## Durability and recovery

Every branch-source deployment handled by `DeploymentCoordinator` records one
durable attempt in an InnoDB table. Five explicit states give those operations
a truthful history and a named support reference regardless of outcome:

- **Immutable source identity and expected-head enforcement.** Each deployment is
  bound to a provider-issued stable repository identity and an immutable commit.
  A delayed or replayed webhook targeting an older commit fails closed as
  `stale_event` rather than rolling back newer code.
- **Archive preflight.** Before any filesystem mutation, Booster validates the
  archive's exact bytes, package identity, version, path containment, and
  free-space readiness. A malformed or unsafe archive fails before WordPress
  touches a plugin or theme directory.
- **Core-first replacement with verified postconditions.** WordPress Core
  performs every branch-source install and update and owns maintenance mode and
  temporary-backup restoration. Booster verifies active state, package identity
  and cleanup afterward, and marks any ambiguous branch-source outcome
  `needs_attention` rather than claiming success.
- **Protected reconciliation.** An interrupted or abandoned deployment leaves a
  visible attempt row. A qualified operator transitions it through a single
  protected action after confirming the process has stopped. Booster never
  performs automatic stale-lock takeover.
- **Bounded Troubleshooting and Deployment activity.** Troubleshooting runs
  on-demand, per-provider diagnostic checks bounded to eight rows, five provider
  calls, and ten seconds. Its optional Logging capture is a single bounded,
  temporary file beside the credential sidecar, not a durable operational
  logging subsystem. Deployment activity shows bounded, redacted attempt
  history and the existing Provider request ID for webhook attempts. None of
  these surfaces stores raw webhook bodies, headers, provider-observed timing,
  or credentials.

The [package update orchestration guide](docs/package-update-orchestration.md)
maps every release and branch trigger and Booster-to-WordPress handoff. The
[deployment execution guide](docs/deployment-execution.md) covers the lower-level
branch runtime model. The
[Core self-update guide](docs/core-self-updates.md) documents source-checkout
protection, the official release marker, site overrides, administrator
messaging, and release verification.

## Deactivation and uninstall

Deactivation, updates and reinstalling over Booster preserve its managed
packages, deployment history and encrypted credentials. Deleting Booster
through WordPress permanently removes all verified Booster-owned local data,
including both custom tables, the encrypted credentials file and key, scheduled
work, notices and temporary capture files.

Before deleting Booster, export a password-protected Blueprint with the
selected packages and explicitly selected eligible file-stored repository
credentials that the target may need. Blueprints do not contain webhook
secrets, provider-side hooks,
constants or deployment history, and restored packages start with deployment
**Disabled**. Revoke provider credentials and remove remote webhooks separately
before uninstalling; bundled GitHub webhook management can remove an identified
GitHub hook when supplied with a fresh Webhooks-write token. Booster removes only a
`wp-config.php` definition that it can prove it created and leaves manually
authored configuration untouched.

## Deployment history retention

By default, Booster retains at most 200 deployment-attempt rows. When admitting
new work it prunes only the oldest successful, failed, or operator-resolved
needs-attention rows. Queued, running, and unresolved needs-attention rows are
never pruned; if those rows exhaust capacity, new work fails safely until an
operator resolves it. A site that needs more history may raise the ceiling with
a canonical integer from 200 through 100000:

```php
define( 'RAN_BOOSTER_MAX_ATTEMPT_ROWS', 500 );
```

An invalid or lower value falls back to 200 and is reported by Troubleshooting.
Deployment activity remains cursor-paginated with at most 100 rows per request.

## Repository archive limits

Provider deployments download a ZIP of the whole repository; selecting a
package subdirectory does not reduce that download. The target site defaults to
50 MiB compressed and 200 MiB expanded. A legitimate larger repository can use
one target-local, site-wide override in `wp-config.php`:

```php
define( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES', 150 * 1024 * 1024 );
```

The compressed value must be an integer from 1 MiB through 512 MiB. Booster
derives the expanded limit at four times that value and retains its other ZIP,
identity, path and free-space checks. The same policy covers every registered
provider's branch-source manual installs and updates, webhook updates, and
Transporter Blueprint installs. Prospective release installation instead uses
the shared updater's separate archive custody and bounds and creates no
deployment attempt. Keep committed development-only files out of the deployed
ref rather than treating a higher limit as a substitute for repository hygiene.

## Development

Use PHP 8.2, Composer 2, Node.js 24.11.0, and pnpm 11.7.0. Install the locked
dependencies and run the same quality checks used by CI:

```sh
composer validate --strict --no-check-publish
composer install --no-interaction --prefer-dist --no-progress
pnpm install --frozen-lockfile
composer check
pnpm check
find . -path './vendor' -prune -o -path './node_modules' -prune -o -type f -name '*.php' -exec php -l {} \;
```

Build and verify the runtime-only deployment archive from the exact committed
tree:

```sh
version="$(sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' ran-booster.php)"
bash scripts/build-release.sh HEAD "$version"
bash scripts/verify-release.sh "build/ran-booster-${version}.zip" "$version" HEAD
```

The builder fixes its timezone to UTC so the same commit produces identical ZIP bytes across hosts.

The release contract is one `ran-booster/` root containing only the runtime PHP,
`RAN/`, `assets/`, `views/`, `readme.txt`, `license.txt`, `NOTICE.md`, the
locked shared-updater runtime, and the generated
`ran-booster-release.json` provenance marker. Development documentation, tests,
agent/Dex state, workflows, release tooling, Composer and Node metadata, caches,
logs, archives, and secret sidecars are excluded. Plugin Check is limited to
its general, security, performance, and accessibility categories because RAN
Booster is deployed internally rather than submitted to the WordPress.org
plugin repository.
