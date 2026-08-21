# RAN Booster

Deploy WordPress plugins and themes directly from Git repositories you control.

GitHub works out of the box, with support for public and private repositories.
There is no paid tier separating the two. RAN Booster Core does not require a
RAN-operated licensing service, OAuth relay, or deployment proxy: your
WordPress site connects directly to the Git provider you configure.

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

For branch deployments, an administrator can replace the installed package
manually or enable **Push-to-Deploy**. When Push-to-Deploy is enabled, a push to
the selected branch can queue the matching package for deployment. Booster
verifies that the webhook came from the configured provider. If the provider
retries it, Booster recognizes the retry instead of deploying the same push
twice.

GitHub webhook setup is guided: Booster brings the callback details, secret,
repository context, and setup checks together. Where the credential permits
it, Booster can also create, check, reconfigure, and remove the GitHub webhook.
You still choose whether the package becomes Automatic; creating a webhook does
not make that decision for you.

For release deployments, Booster can track published releases through
WordPress's native update flow. If an eligible GitHub repository is not yet set
up to publish releases, Booster can assess it and open a draft pull request with
the release workflow. You review and merge that pull request; Booster never
merges it or changes the default branch itself. The
[package update guide](docs/package-update-orchestration.md) explains how branch
and release deployments differ.

## Control, evidence, and recovery

Saved provider credentials are encrypted in a private file outside the
WordPress and plugin directories. The encryption key is held separately in the
WordPress database, so a copy of either one alone does not reveal the saved
tokens. Third-party provider add-ons have their own behavior and policies.

Booster keeps recent Deployment activity on the site. It reports whether work
succeeded, failed, or needs attention; an uncertain result is not presented as
success. Troubleshooting adds on-demand checks and a temporary log with
sensitive values removed, without turning Booster into a remote monitoring
service.

The [deployment execution guide](docs/deployment-execution.md) covers webhook
verification, deployment recovery, history retention, and repository archive
limits. The [Core self-update guide](docs/core-self-updates.md) covers updates
to Booster itself, which are separate from the plugins and themes it manages.

## Moving a site or leaving Booster

Transporter creates a password-protected Blueprint for selected managed
packages and, when explicitly selected, eligible saved credentials. It gives
you a reconstruction path for a site move without requiring a development
checkout. Blueprints do not include webhook secrets, provider-side hooks,
deployment history, or WordPress content, and every restored package begins
with deployment **Disabled**. See the
[Transporter Blueprint guide](docs/portability-briefcase.md).

Deactivation, updates, and reinstalling Booster preserve its managed packages,
deployment history, and encrypted credentials. Deleting Booster through
WordPress permanently removes the local data it can verify belongs to Booster.
Export a Blueprint, remove remote webhooks, and revoke provider credentials
before uninstalling.

## Beta, installation, and support

RAN Booster is in Beta. Download `ran-booster-<version>.zip` and its `.sha256`
from [GitHub Releases](https://github.com/RocketsAreNostalgic/ran-booster/releases),
verify the checksum, and install the ZIP through WordPress. GitHub's generated
**Source code** archives are not installable plugin packages.

Requirements:

- WordPress 7.0 or newer
- PHP 8.2 or newer with the Sodium and Zip extensions
- MySQL 8.0 or newer, or MariaDB 10.11 or newer, with InnoDB available
- Single-site WordPress; multisite is not supported in this Beta

Booster stops before making changes when a fresh installation does not meet
these requirements. If an active site later falls outside them, the site stays
bootable but Booster pauses affected management and deployment operations until
the requirement is restored.

Use the [issue tracker](https://github.com/RocketsAreNostalgic/ran-booster/issues)
for ordinary support and non-sensitive defects. Follow
[SECURITY.md](SECURITY.md) for confidential vulnerability reporting and
[CONTRIBUTING.md](CONTRIBUTING.md) before proposing a change.

## Providers and development

GitHub is bundled. Compatible provider add-ons can add other Git services
without modifying Booster Core. Provider authors should start with the
[custom Git provider guide](docs/custom-git-vendors.md) and the
[provider extension contract](docs/provider-extension-contract.md).

For Core development, use PHP 8.2, Composer 2, Node.js 24.11.0, and pnpm 11.7.0:

```sh
composer install --no-interaction --prefer-dist --no-progress
pnpm install --frozen-lockfile
composer check
pnpm check
```

The authoritative build and release process is in [RELEASE.md](RELEASE.md).

## Project history and license

RAN Booster began as a GPL fork of WP Pusher 3.0.13, created by Peter Suhm. It
retains the original idea of deploying WordPress packages from
version-controlled repositories while replacing the inherited vendor-hosted
licensing, OAuth, and repository-picker dependencies with site-controlled
connections. RAN Booster is independent and is not affiliated with or endorsed
by Peter Suhm, WP Pusher, or its current owners.

The project is distributed under GPL-2.0-only, the conservative interpretation
of the upstream package's `GPLv2` declaration. Source provenance is recorded in
[NOTICE.md](NOTICE.md) and [license.txt](license.txt); released changes are in
[CHANGELOG.md](CHANGELOG.md).
