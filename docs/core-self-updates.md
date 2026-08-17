# Booster Core self-updates

## Purpose

This guide defines when RAN Booster may consume its own GitHub release feed and
allow WordPress to replace the installed `ran-booster/` directory. It protects
source checkouts and local development work without treating `localhost` as a
development signal.

The risk is a native WordPress update, not Booster's managed-package deployment
path. When WordPress installs a plugin update it clears and replaces that
plugin's destination directory. A manual **Update now**, a bulk update, or
another direct use of WordPress's plugin upgrader can therefore remove a
checkout's `.git` directory, development files, and uncommitted work.

Booster prevents that accidental replacement with three coordinated controls:

1. a positive release marker generated only inside verified Booster release
   ZIPs;
2. an explicit `auto`, `enabled`, or `disabled` site override; and
3. a shared-updater runtime-only mode that preserves runtime arbitration
   without registering Core's native discovery or installer hooks.

## Default policy

The default mode is `auto`.

| Installation state                             | Core release-feed request             | WordPress update offer                        | Background Core update | Shared updater runtime            |
| ---------------------------------------------- | ------------------------------------- | --------------------------------------------- | ---------------------- | --------------------------------- |
| Source checkout with `.git` or `composer.json` | No                                    | No                                            | No                     | Still participates in arbitration |
| Directory without a valid release marker       | No                                    | No                                            | No                     | Still participates in arbitration |
| Verified release ZIP with a matching marker    | Yes, through normal bounded discovery | A newer valid release may be offered manually | Forced off             | Still participates in arbitration |
| Explicit `disabled` override                   | No                                    | No                                            | No                     | Still participates in arbitration |
| Explicit `enabled` override                    | Yes                                   | A newer valid release may be offered manually | Forced off             | Still participates in arbitration |
| Invalid override                               | No                                    | No                                            | No                     | Still participates in arbitration |

`auto` fails closed. Missing, unreadable, malformed, symlinked, oversized, or
wrong-version marker files disable native self-update discovery.

An ordinary source checkout never needs a local configuration change. The
absence of an official marker is sufficient to keep Core runtime-only.

## Configuration

Set the mode in `wp-config.php` before WordPress loads plugins:

```php
define( 'RAN_BOOSTER_SELF_UPDATE_MODE', 'disabled' );
```

The accepted values are:

- `auto`: use the verified release marker and source-tree checks; this is the
  default and recommended value;
- `enabled`: allow native discovery without requiring the marker; use only for
  a disposable self-update test; and
- `disabled`: force runtime-only behavior even for an official release ZIP.

Any other value fails closed as `disabled` and appears as invalid configuration
in Troubleshooting.

Hosts that manage configuration through environment variables may bridge a
strictly allowlisted environment value in `wp-config.php`:

```php
$ran_booster_update_mode = getenv( 'RAN_BOOSTER_SELF_UPDATE_MODE' );
if (
	is_string( $ran_booster_update_mode )
	&& in_array( $ran_booster_update_mode, array( 'auto', 'enabled', 'disabled' ), true )
) {
	define( 'RAN_BOOSTER_SELF_UPDATE_MODE', $ran_booster_update_mode );
}
unset( $ran_booster_update_mode );
```

Do not set `enabled` in a contributor's working checkout. If explicit update
testing is required, use a disposable WordPress installation or a clean copy
whose replacement is expected.

## Why hostname and WordPress environment flags are not authority

Booster intentionally does not use any of these as the self-update decision:

- `localhost`, `.local`, loopback addresses, ports, or Local site names;
- `WP_ENVIRONMENT_TYPE`, `wp_get_environment_type()`, or
  `wp_is_development_mode()`;
- `WP_DEBUG`; or
- Booster's separate development-safety detector for managed packages.

Users legitimately install Booster on localhost, staging, production, and
custom domains. Those values describe where WordPress is running, not whether
the installed Booster directory is disposable.

The release marker is a positive packaging signal, while the explicit override
is an operator decision. Source indicators such as `.git` and `composer.json`
are additional defence in depth in `auto`.

## Official release marker

`scripts/build-release.sh` generates `ran-booster-release.json` only inside the
temporary staged release archive. The marker is never committed to the source
tree.

Its exact schema is:

```json
{
	"schema": "ran-booster-core-release",
	"schema_version": 1,
	"version": "0.1.0-alpha.24",
	"commit": "0123456789abcdef0123456789abcdef01234567"
}
```

The version must exactly match the installed plugin `Version` header, and the
commit must be 40 lowercase hexadecimal characters. Extra fields are rejected.
`scripts/verify-release.sh` confirms the marker, archive path set, selected
immutable commit, plugin header, locally calculated ZIP digest, and locked
updater identity agree.

The marker is a distribution-provenance signal, not a cryptographic
authenticity claim. GitHub's release identity and asset SHA-256, the updater's
local re-hash, and exact archive-header checks remain the trust boundary.

Copying or hand-authoring a marker is not a supported installation workflow.
Use a verified release ZIP.

## Shared-updater handoff

Core always submits its target to the shared updater before `plugins_loaded`.
That registration is needed so bundled updater copies select one compatible
runtime deterministically and retain the prospective-release API used by
managed packages.

When Core policy disables native discovery, it passes both:

```php
autoUpdatePolicy: 'disabled',
nativeDiscovery: false,
```

The selected runtime attaches passive
`inactive / native_discovery_disabled` diagnostics but does not construct
Core's native updater. It registers no Core self-update discovery, plugin
information, auto-update, upgrader, completion, notice, refresh, or HTTP work.

The `disabled` automatic-update policy is retained as defence in depth. It must
not be treated as a substitute for runtime-only mode because disabled policy
alone can still consume the feed before deciding not to offer the release.

When discovery is enabled, Core retains `forced-off` automatic policy. A newer
verified public release can appear in WordPress's manual update UI, but Booster
does not opt itself into unattended installation.

Managed plugin and theme release targets are separate. Their configured Manual,
Automatic, or Disabled policies are not changed by the Core self-update mode.

## Administrator experience

The shared updater's raw Core notice is suppressed for the exact
`RocketsAreNostalgic/ran-booster` target. Nontechnical administrators are not
shown repository names, updater error codes, private/public feed guidance, or
manual-ZIP instructions in a global banner.

On Booster and Plugins screens, a qualified administrator viewing a detected
source checkout instead sees one persistent informational notice:

> **RAN Booster development detected:** Core updates are disabled to protect
> this source checkout.

The notice appears only for the positive `source_checkout` reason. An old,
hand-copied, or otherwise unverified installation remains safely runtime-only
without being mislabeled as a development checkout.

The passive state is available to qualified administrators at:

```text
RAN Booster → Troubleshooting → Diagnostics → Core updates
```

The ordinary message explains whether Core updates are available or safely off.
Updater state, diagnostic code, selected runtime, offered version, check times,
and release-marker provenance remain collapsed under **Technical details**.
Opening the page and expanding those details performs no remote request.

Core self-update checks do not create Booster Deployment activity. The shared
updater's own debug events use WordPress debug logging and are not represented
as Booster's temporary debug capture.

## Operator workflows

### Normal development

Keep the mode unset or set it to `auto`. Confirm the plugin directory contains
the expected source metadata. Troubleshooting should report that Core updates
are off for a source installation.

Do not use **Update now** as a test against the working checkout. Use a
disposable installation.

### Disposable self-update test

1. Install a clean copy in a disposable WordPress installation.
2. Back up or discard any local changes.
3. Set `RAN_BOOSTER_SELF_UPDATE_MODE` to `enabled`.
4. Trigger a normal WordPress update check.
5. Confirm only a newer verified release is offered.
6. Run the manual update and verify the installed version and plugin state.
7. Remove the override or restore `auto`.

`enabled` bypasses source and marker detection intentionally. It does not enable
background installation.

### Emergency freeze

Set the mode to `disabled`. Core remains loaded and continues participating in
shared-updater runtime arbitration, but it makes no self-update feed request and
cannot create a native Core update offer.

This is narrower than `DISALLOW_FILE_MODS`, filesystem permission changes, or
globally disabling updates, all of which affect unrelated WordPress package
operations.

### Missing or invalid marker

An installation made from a pre-marker ZIP, a hand-copied directory, or an
altered package remains runtime-only in `auto`. The installed plugin continues
working.

To restore ordinary manual update discovery, install a current verified Booster
release ZIP. Do not repair the marker by hand. The explicit `enabled` override
is reserved for disposable testing, not recovery on a maintained site.

### Release-feed failure

An official installation continues running when GitHub is unavailable, rate
limited, or returns an invalid release. Booster retries through the updater's
bounded cache and cooldown behavior. Troubleshooting shows a short friendly
message; exact codes and check times are available only under Technical
details.

If a release must be installed manually, obtain the one ZIP from the approved
GitHub Release and compare its SHA-256 with the release asset `digest` returned
by GitHub before installation.

## Release and verification sequence

The shared updater must be released before Core pins it. Reusing an older
package version is unsafe because a mixed older copy with the same version
could win path arbitration and lack the current native-ZIP or
`nativeDiscovery` behavior.

The dependency order is:

1. release the shared updater Composer library from an exact tag and commit;
2. update Core's Composer constraint and exact lock;
3. update every exact package-version assertion in build, verification, and CI;
4. run Core's focused and full checks;
5. commit the complete Core runtime and documentation slice;
6. build the Booster ZIP from that immutable Core commit;
7. verify the internal marker, GitHub asset digest and locally computed ZIP
   SHA-256; and
8. prove both source/runtime-only and official/manual-only behavior in
   disposable WordPress installations.

Release Please owns version proposals, version sources and changelogs. Its
action does not create the WordPress ZIP or publish the GitHub release directly.
An ordinary pull request builds one runtime archive and runs the full source and
WordPress matrix against it. Its merge to `main` reuses that evidence only when
the exact pull request, successful Quality run, immutable artifact and tested
Git tree all agree; missing or stale evidence, non-merge pushes and changes to
the trust-defining workflow files fall back to the full suite.

The automated Release Please pull request is reduced only after a fail-closed
validator proves that its head changes exactly the four expected generated
release files and keeps their versions and accepted changelog history aligned.
Quality builds the publishable ZIP from that exact pull-request head and runs a
focused install, activation and installed-identity readback. After the proposal
merges, main Quality admits and re-uploads those exact verified bytes. The
Release Please workflow then proves the merge and source identities, downloads
that exact Quality artifact, creates or resumes a draft, attaches and
byte-verifies the ZIP, and publishes only under the explicitly enabled
immutable-release contract.

The completed native-ZIP set is updater `v1.6.0-beta.1`, Core
`v0.1.0-alpha.29` and Release Deployments `v0.1.0-alpha.4`. Exact commits,
assets, digests, hosted replacement proof and controlled-site cutover are
retained in the private release review archive.

The hosted private-feed proof used a temporary process-only, host/path-scoped
token shim and then removed it. That proves the released archive and WordPress
replacement path, not supported private Core credential UX. Core's shipped
anonymous registration still fails closed against a private repository.

## Acceptance evidence

A releasable Core change proves:

- source `auto` resolves to disabled without consulting a hostname or general
  WordPress environment flag;
- valid official marker `auto` resolves to enabled;
- explicit `enabled` and `disabled` overrides win as documented;
- invalid configuration and every invalid marker shape fail closed;
- runtime-only still selects a compatible updater runtime;
- runtime-only registers no Core native hooks and makes no feed request;
- the exact raw Core updater notice is suppressed while managed package notices
  pass through;
- a source checkout shows only the scoped, nontechnical development notice;
- Troubleshooting reads passive bounded diagnostics and escapes every field;
- background Core installation remains forced off when discovery is enabled;
- the release ZIP alone contains the generated marker; and
- the verified archive exactly matches the committed Core, locked updater
  runtime, and generated marker allowlist.

See [Package update orchestration](package-update-orchestration.md) for the
larger managed-release and branch deployment model. Historical alternatives and
reconsideration triggers remain in the private architecture review archive.
