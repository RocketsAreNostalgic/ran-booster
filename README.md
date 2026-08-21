# RAN Booster

Deploy WordPress plugins and themes directly from Git repositories you control.

GitHub works out of the box, with support for public and private repositories. An alpha Bitbucket extension is also available.

RAN Booster does not require a RAN-operated licensing service, OAuth relay, or
deployment proxy: your WordPress site connects directly to the Git provider
you configure.[^direct-connection]

## From repository to WordPress

1. **Choose a repository.** Start with a public GitHub repository without
   adding a credential, or add a site-controlled token for a private one.
2. **Choose how to follow it.** A managed package can track a branch or its
   published releases.
3. **Choose when Booster may act.** Every package is explicitly Disabled,
   Manual, or Automatic. Installing a package never silently opts it into
   unattended deployment.
4. **Let WordPress do the replacement.** Booster checks the repository and
   downloaded package before handing the change to WordPress, then checks the
   result and records what happened.

### Branches, webhooks, and Push-to-Deploy

For branch deployments, an administrator can replace the installed package
manually or enable **Push-to-Deploy**. When Push-to-Deploy is enabled, a push to
the selected branch can queue the matching package for deployment. Booster
accepts the webhook only when it carries a valid signature for the secret
configured on the site. If the provider retries it, Booster recognizes the
retry instead of deploying the same push twice.

#### Guided GitHub webhook setup

Setting up webhooks can be a bit daunting, so Booster brings the callback
details, secret, repository context, and setup checks together. Where the
credential permits it, Booster can also create, check, reconfigure, and remove
the GitHub webhook.

You still choose whether the package becomes Automatic; creating a webhook does
not make that decision for you.

### Published release tracking

Booster can track published releases and surface eligible updates through
WordPress's native update flow. Manual does not ask WordPress to install
releases automatically; Automatic permits WordPress's updater to install an
eligible release.

If an eligible GitHub repository is not yet set up to publish releases, Booster
can assess it and open a draft pull request with a pre-written release workflow.
You review and merge that pull request; Booster never merges it or changes the
default branch itself. The
[package update guide](docs/package-update-orchestration.md) explains how branch
and release deployments differ.

## Requirements and support

- WordPress 7.0 or newer
- PHP 8.2 or newer with the Sodium and Zip extensions
- MySQL 8.0 or newer, or MariaDB 10.11 or newer, with InnoDB available
- Single-site WordPress; multisite is not supported in this Beta

Fresh activation explicitly checks Sodium and rejects multisite before Booster
writes its own state. WordPress and PHP compatibility are also declared in the
plugin metadata, while Zip is required for archive workflows. If an active site
later falls outside an operational requirement, the site stays bootable but
Booster pauses affected management and deployment operations until the
requirement is restored.

Use the [issue tracker](https://github.com/RocketsAreNostalgic/ran-booster/issues)
for ordinary support and non-sensitive defects. Follow
[SECURITY.md](SECURITY.md) for confidential vulnerability reporting and
[CONTRIBUTING.md](CONTRIBUTING.md) before proposing a change.

## Installation

RAN Booster is in Beta. Download `ran-booster-<version>.zip` and its `.sha256`
from [GitHub Releases](https://github.com/RocketsAreNostalgic/ran-booster/releases),
verify the checksum, and install the ZIP through WordPress. GitHub's generated
**Source code** archives are not installable plugin packages.

## Deactivating and deleting Booster

Deactivation, updates, and reinstalling Booster preserve its managed packages,
deployment history, and encrypted credentials. Deleting Booster through
WordPress permanently removes the local data it can verify belongs to Booster.
Export a Blueprint, remove remote webhooks, and revoke provider credentials
before uninstalling.

## Operating and extending Booster

### Move sites with Transporter

Transporter creates a password-protected Blueprint ZIP for selected managed
packages and, when explicitly selected, eligible saved credentials. It gives
you a reconstruction path for a site move without requiring a development
checkout. Blueprints do not include webhook secrets, provider-side hooks,
deployment history, or WordPress content, and every restored package begins
with deployment **Disabled**. See the
[Transporter Blueprint guide](docs/portability-briefcase.md).

Credential transfer is a copy operation. It does not move the source site's
encryption key, revoke or rotate the source credential, or recreate
provider-side access and webhooks.

### Credentials and deployment evidence

Saved provider credentials, such as personal access tokens, are encrypted in a
private file outside WordPress and its plugin directories. The encryption key
is held separately in the WordPress database, so a copy of either one alone
cannot reveal the saved tokens.

Booster keeps recent Deployment activity on the site. It reports whether work
succeeded, failed, or needs attention; an uncertain result is not presented as
success. Troubleshooting adds on-demand checks and a temporary log with
sensitive values removed, without turning Booster into a remote monitoring
service.

The [deployment execution guide](docs/deployment-execution.md) covers webhook
verification, deployment recovery, history retention, and repository archive
limits. The [Core self-update guide](docs/core-self-updates.md) covers updates
to Booster itself, which are separate from the plugins and themes it manages.

### Durability & recovery

Deployment truthfulness is a design priority. Every branch-source deployment
records one durable attempt row with an explicit state
(`queued` → `running` → `succeeded` / `failed` / `needs_attention`), bound to a
provider-issued repository identity and an immutable commit. Booster validates
the archive's exact bytes, identity, and paths before WordPress touches the
filesystem, verifies the result afterward, and rejects stale events before
they can roll back newer code; an exact replay creates no additional attempt.

What administrators should watch for:

- An ambiguous outcome is flagged `needs_attention`, never reported as
  success. Review those rows in Deployment activity; every attempt carries a
  stable support reference.
- An interrupted deployment is never silently taken over. A qualified operator
  resolves it through a single protected reconciliation action after
  confirming the process has stopped.
- Troubleshooting provides bounded, on-demand diagnostics; nothing persists
  raw webhook bodies, headers, or credentials.

The [package update orchestration guide](docs/package-update-orchestration.md)
maps every trigger and handoff, and the
[deployment execution guide](docs/deployment-execution.md) covers the runtime
model and day-to-day webhook operations.

### Deployment history retention

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

### Repository archive limits

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

### Provider add-ons

Compatible provider add-ons can support additional Git services without
modifying Booster Core. The alpha Bitbucket add-on currently uses manual
webhook setup rather than Core's guided GitHub webhook management. Third-party
provider add-ons may have their own service dependencies and policies.

Provider authors should start with the
[custom Git provider guide](docs/custom-git-vendors.md) and the
[provider extension contract](docs/provider-extension-contract.md).

## Development

For Core development, use PHP 8.2, Composer 2, Node.js 24.11.0, and pnpm 11.7.0:

```sh
composer validate --strict --no-check-publish
composer install --no-interaction --prefer-dist --no-progress
pnpm install --frozen-lockfile
composer check
pnpm check
```

Build and verify the runtime-only deployment archive from the exact committed
tree:

```sh
version="$(sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' ran-booster.php)"
bash scripts/build-release.sh HEAD "$version"
bash scripts/verify-release.sh "build/ran-booster-${version}.zip" "$version" HEAD
```

The authoritative build and release process is in [RELEASE.md](RELEASE.md).

## Project history and license

RAN Booster began as a GPL fork of WP Pusher 3.0.13, created by the legendary
[Peter Suhm](https://petersuhm.com/). It retains the original idea of using
WordPress's updater to deploy plugins and themes from version-controlled
repositories while replacing the inherited vendor-hosted licensing, OAuth, and
repository-picker dependencies with site-controlled connections. RAN Booster
is independent and is not affiliated with or endorsed by Peter Suhm, WP Pusher,
or its current owners.

The project is distributed under GPL-2.0-only, the conservative interpretation
of the upstream package's `GPLv2` declaration. Source provenance is recorded in
[NOTICE.md](NOTICE.md) and [license.txt](license.txt); released changes are in
[CHANGELOG.md](CHANGELOG.md).

[^direct-connection]: This direct-connection claim covers Booster Core and RAN first-party provider integrations. Your or your provider's hosting, proxy, CDN, and network services may still be in the path. Third-party extensions may have their own dependencies and policies.
