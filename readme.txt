=== RAN Booster ===
Tags: git, deploy, deployment, github
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.2
<!-- x-release-please-start-version -->
Stable tag: 1.0.0-beta.5
<!-- x-release-please-end -->
License: GPL-2.0-only
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Deploy WordPress themes and plugins directly from supported repository providers.

== Description ==

RAN Booster is an internal repository deployment plugin for WordPress. GitHub
is the bundled provider for repository discovery, immutable installs and
updates, and signed Push-to-Deploy. Compatible provider add-ons use the same
public provider contract and retain their own credential and webhook guidance.
It does not use a licence service, vendor updater, cloud OAuth flow, or external
repository picker service.

The plugin and theme installation screens include a native repository picker.
For operational providers, it can search public repositories without a token or
list repositories accessible to a saved credential profile. Manual repository
account/name entry remains available and is verified through the provider API.

When the optional Release Deployments add-on and negotiated Prospective Release
API 4 are both available, a not-yet-installed plugin or theme can be inspected
without downloading its ZIP. Install performs fresh fingerprint-bound
acquisition and delegates mutation to WordPress Core; the target must be absent
and inactive, remain inactive after install, pass identity checks and then be
adopted by Booster. Partial installed-but-unmanaged, uncertain-state and cleanup
outcomes require inspection before linking or retrying.

Managed package settings use one Automation control for Branch and Published
releases: Disabled blocks Booster-managed replacement, Manual requires an
explicit action, and Automatic permits that source's normal automatic path.
Branch Reinstall deliberately replaces the saved branch copy, including local
changes, after confirmation; it does not look for a newer release. Published
releases use WordPress's native Update action.

Each managed package settings page has a separate Danger zone. **Unlink** stops
Booster management while leaving files and WordPress activation unchanged.
**Unlink and delete** is a distinct, server-confirmed action: it is
source-revision fenced and lock-protected; plugins are deactivated and use
WordPress's uninstall/delete path, while active themes, parent themes,
dependencies, unsafe paths and in-flight package work are refused.

Private repositories use named, provider-scoped credential profiles saved to a
site-owned secrets file outside this plugin. Tokens are never stored in the
WordPress database. Deployment-provided constants take precedence when present.
Push-to-Deploy uses manually configured, signed provider webhooks. Secrets can
follow provider policy. GitHub supports one secret shared by repositories under
a canonical organization or user, or an exact secret bound to one stable
repository ID; the exact repository secret takes precedence.
Each provider tab shows the callback URL, required event, repository context,
and manual setup links beside the saved local secrets. Every repository still
needs its own remote webhook; a saved local secret alone is not a ready hook.
The optional Assisted Hooks add-on can set up, check, reconfigure and remove
GitHub repository webhooks with a separate fine-grained token granting
Webhooks: Read and write permission. It reuses an applicable Core profile or
creates an exact repository profile. Reconfigure sends the current Core secret
and callback settings to the identified remote hook; it does not replace the
secret. The token is used only for the submitted operation and is never saved.
Assisted Hooks never enables Automatic deployment, and manual webhook setup
remains available without it.

Webhook signatures authorize deployment after WordPress accepts a request;
they do not protect the host, PHP workers, or WordPress bootstrap from traffic.
Use public HTTPS with certificate verification, the provider's JSON delivery
option, one unique generated secret per repository, and only the required push
event. Do not cache, challenge, redirect, or transform either the `/wp-json/`
callback or its `?rest_route=` form. Provider delivery history is authoritative
for timeouts and response status. GitHub does not automatically redeliver failed
deliveries. Enable Bitbucket Request History in advance and use its request UUID
only as a cross-reference; do not assume it is stable across automatic attempts.
Compare the provider identifier with the Provider request ID shown for webhook
attempts in Booster Activity; absence from Activity is inconclusive for probes,
ignored events, and zero targets.

Switching a package from Branch to Published releases does not remove an
existing repository webhook or local signing-secret setup. The release-managed
package ignores pushes, but another branch-managed package using the same
repository may still need that hook. Keep the setup for a temporary source switch. For long-term
release management, site or repository retirement, or callback or credential
changes, first confirm that no branch package still needs it. Remove the remote
provider webhook before removing an unused local secret, and preserve shared
owner secrets that still serve other packages. Assisted Hooks can use its
verified Remove workflow for an identified hook. Without it, remove the hook in
the provider UI and then use Manage secrets for unused local material.

Credential profiles accept an optional expiry date. Successful GitHub
validation records GitHub's reported token expiry when present; providers
without equivalent metadata use the manual date. Administrators see
dismissible notices across WordPress admin from 30 days before expiry, with
urgent styling from seven days and after expiry. Missing metadata remains
unknown. Booster performs no background polling, email, token generation, or
automatic rotation.

Booster does not maintain a durable operational log. Deployment activity keeps
bounded, display-safe deployment outcomes, while Troubleshooting runs explicit
diagnostics. Its optional Logging capture records only sanitized Booster events
for 60 minutes, keeps no more than 400 entries, and is removed after a 24-hour
retention window. It neither enables nor replaces WP_DEBUG_LOG and omits PHP,
WordPress, theme, and other-plugin messages.

== Installation ==

1. Install the plugin in the normal WordPress way.
2. Use Install a plugin or Install a theme, then pick a public repository or
   enter `owner/repository` manually. Public repositories need no credential.
3. Only for a private repository, add the required access profile from the
   selected provider tab. The provider-specific guidance links to current
   permissions.
4. For Push-to-Deploy, use the selected provider tab to save and copy an
   appropriately scoped webhook secret. Add the displayed Payload URL, event,
   JSON content type, and same secret to the repository, test delivery, and only
   then deliberately set the package to Automatic.

== Supported beta operations ==

WordPress 7.0 is the declared beta minimum; CI exercises the 7.0.1 and 7.0.2
patch releases on PHP 8.2. The supported envelope is a single-site installation
using MySQL 8.0 or newer or MariaDB 10.11 or newer, where Booster's
managed-package and deployment-attempt tables use InnoDB,
WordPress's direct filesystem method is available and WP-Cron works. Booster
does not restrict the WordPress options-table engine. File modifications,
outbound provider HTTPS requests and secure writes to WordPress temporary,
content, plugin and theme directories must be available. Multisite and network
activation are not supported for package deployment.

MySQL 8.0 is the tested compatibility floor and MySQL 8.4 LTS is recommended
for production. SQLite, PostgreSQL and unverified db.php database translators
are unsupported. Unsupported fresh activation stops before schema changes. If
an active site's database becomes unsupported, Booster leaves stored data
unchanged, pauses package storage, Transporter and all deployment paths, and
keeps read-only compatibility Troubleshooting available. Restore a supported
database to resume. Raw moves within the supported MySQL/MariaDB envelope are
best effort. Before a move, export every managed package in a current
Blueprint, optionally include eligible file-stored credentials in its
password-protected archive, preview the ZIP, retain it off-site, and keep the
normal database and filesystem backup. The target must still satisfy Booster's
database requirements. Blueprints omit deployment-attempt and delivery-replay
history, webhook secrets and provider-side hooks, constants, locks and worker
state, and source deployment policy. Installed and adopted packages start
Disabled. Booster does not perform cross-engine table migration.

Booster runs one sequential package mutation at a time and records one of five
states: queued, running, succeeded, failed or needs attention. It uses
WordPress core's updater and temporary-backup behavior, but does not provide a
custom rollback, automatic retry, recovery graph or recurring worker schedule.
Deployment history is capped at 200 rows. Booster prunes only the oldest
successful or failed rows when admitting new work and never prunes queued,
running or needs-attention rows. `RAN_BOOSTER_MAX_ATTEMPT_ROWS` may raise the
ceiling to a canonical integer no greater than 100000; invalid or lower values
use 200 and appear in Troubleshooting. The 100-row activity-page read bound and
Load more pagination remain independent.

Provider deployments download the whole repository ZIP, even when a package
subdirectory is selected. The target-site default is 50 MiB compressed and
200 MiB expanded. Operators can set a site-wide compressed limit from 1 MiB
through 512 MiB with `RAN_BOOSTER_MAX_ARCHIVE_BYTES` in `wp-config.php`; Booster
derives the expanded limit at four times that value. This policy covers manual
and webhook deployments and Transporter Blueprint installs without weakening the other
archive safety checks.

The [package update orchestration guide](https://github.com/RocketsAreNostalgic/ran-booster/blob/main/docs/package-update-orchestration.md)
maps release and branch triggers plus every Booster-to-WordPress handoff. The
[deployment execution guide](https://github.com/RocketsAreNostalgic/ran-booster/blob/main/docs/deployment-execution.md)
covers the core-first mutation boundary, provider authentication, immutable
identity, durable attempts, locks, webhook admission and safe outcomes. The
[Core self-update guide](https://github.com/RocketsAreNostalgic/ran-booster/blob/main/docs/core-self-updates.md)
documents source-checkout protection, the official release marker, the
site-level override and the manual-only update boundary. The review pack in the
source checkout records wider beta evidence and
operator-owned residual risks.

Browser checks and the disposable WordPress CI proofs are integration evidence
for these controls. They validate the installed WordPress path and do not
represent missing Core runtime code.

Credentials and webhook secrets are authenticated ciphertext in an owner-only
private JSON file outside the WordPress and plugin directories. Conventional
single-site POSIX installations can use the protected Overview's automatic
location. Containers and uncommon layouts can define
`RAN_BOOSTER_ENCRYPTED_SECRETS_FILE` as an absolute `secrets.json` path outside
the public web root on durable local storage whose immediate parent is owned by
PHP, readable and writable by PHP, and mode 0700. Its independent key is stored
in the non-autoloaded
`ran_booster_secrets_key_v1` WordPress option. Deactivation, updates and
reinstalling over Booster preserve the encrypted file, lock and key. Restore
the matching encrypted file and database key from the same backup; neither half
is useful alone. Protect, retain and dispose of whole-site backups as secret
material.

The PHP Sodium extension and single-site WordPress are required for a fresh
activation.

If an already-active site is updated without Sodium or on multisite, Booster
remains bootable and shows a persistent warning on Booster and Plugins screens.
Managed credential and webhook operations pause; anonymous public-repository
access and package-only Transporter Blueprints remain available.

Deleting Booster through WordPress permanently removes all verified
Booster-owned local data, including both custom tables, the encrypted
credentials file and its separate database key. Before deletion, export a
password-protected Blueprint with the selected packages and any file-stored
repository credentials they use that you may need. Blueprints omit webhook
secrets, provider-side hooks, constants and deployment history, and restored
packages start Disabled. Revoke provider credentials and remove remote webhooks
separately first. Booster removes only the exact `wp-config.php` definition it
created and leaves manually authored configuration untouched.

RAN Booster has no WordPress.org release, central authentication service or
unattended self-update. Source checkouts and unverified directories keep the
bundled updater in runtime-only mode: they do not consume Core's release feed or
create a native Core update offer. A verified release ZIP carries a
build-generated provenance marker and may advertise only a newer eligible
public GitHub Release. Repository, tag, commit, exact asset, size and SHA-256
bindings constrain the downloaded package. Unavailable releases, rate limits
and invalid assets fail closed as no update. Prerelease installations may
follow prereleases; stable installations remain on the stable channel.
Automatic updates remain disabled, and WordPress Core performs the
operator-triggered update. Booster remains excluded from its generic repository
deployment machinery. This updates Booster itself and is separate from Release
Deployments for managed packages.

== Development status ==

The plugin is currently being rebuilt from a GPL fork. Its PHP namespace is
`RAN`, so its runtime classes are isolated from the upstream plugin. Source
attribution and licensing are recorded in `NOTICE.md` and `license.txt`.
