# Portability API 3

Portability API 3 is Core's narrow, request-local adoption boundary for a
trusted source bridge. It reviews one already-installed plugin or theme and may
adopt that package into Booster with deployment policy forced to Disabled.

It is not the Transporter Blueprint format, a general migration API, or an
installer. The [Transporter Blueprint guide](portability-briefcase.md) documents
the separate Blueprint V1 ZIP workflow.

## Version and service delivery

A consumer must require exact Portability API 3:

```php
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! defined( 'RAN_BOOSTER_PORTABILITY_API_VERSION' )
			|| 3 !== RAN_BOOSTER_PORTABILITY_API_VERSION ) {
			return;
		}

		add_action(
			'ran_booster_portability_ready',
			static function (
				\RAN\AddOn\Portability\PortabilityFacade $portability
			): void {
				// Retain this request-local facade only for this request.
			},
			10,
			1
		);
	}
);
```

The ready action fires once on `plugins_loaded` at priority 100, after provider
registration is sealed and before Core binds its dashboard. A late listener is
not replayed. The callback receives only the portability facade; it does not receive
Core's container, repositories, provider clients, credential store, filesystem
paths, or cleanup authority.

Portability API 3 is independently versioned. A source bridge does not need
Add-on API 17 or Provider API 14, although Core continues to use its registered
providers internally.

A source bridge may separately feature-detect Core's additive
`TransporterRowAdminInteractionFacade` to return one checked or imported source
row without a page refresh. Core derives the target from bounded presentation
identity based on the installed candidate, never from a source row ID, source
key or source revision. This presentation transport does not change
Portability API 3 authorization, review, Apply, target verification or
source-cleanup ownership. See
[Enhanced administration interaction API](enhanced-admin-interactions.md).

## Candidate contract

Construct one immutable
`\RAN\AddOn\Portability\PortabilityCandidate` with:

- `type`: `plugin` or `theme`;
- `identifier`: installed plugin basename or theme stylesheet;
- `display_name`: non-empty display-safe text, at most 191 bytes;
- `provider_code`: a registered provider code;
- `repository`: a normalized repository locator without embedded credentials,
  query, or fragment;
- `branch`: non-empty and at most 255 bytes;
- `subdirectory`: `null` or a normalized relative path of at most 255 bytes;
  and
- `credential_id`: `null` or an existing Core credential-profile ID matching
  `[A-Za-z0-9_-]{3,64}`.

The candidate deliberately has no source row ID or fingerprint, provider-issued
repository ID, public/private assertion, credential value, path, ZIP, artifact,
session, requested policy, or cleanup instruction. Core independently resolves
provider identity and privacy.

## Review and Apply

The bridge owns its source state and uses the facade in this order:

1. Fresh-read and validate the source record.
2. Build the current candidate.
3. Derive the review action with
   `nonce_action( 'review', $candidate )`, create that WordPress nonce, and call
   `review( $candidate, $nonce )`.
4. Display the returned closed action, reason, message, and opaque `v1:`
   fingerprint.
5. Before Apply, fresh-read the source again and require its bridge-owned
   fingerprint to be unchanged.
6. Derive the Apply action with
   `nonce_action( 'apply', $candidate, $review->fingerprint )`, create that
   WordPress nonce, and call
   `apply( $candidate, $review->fingerprint, $nonce )`.
7. Remove source authority only when Apply returns `target_verified = true` and
   a final exact source comparison succeeds.

`nonce_action()` scopes a nonce; it neither creates a nonce nor grants authority.
Core independently checks `manage_options`, the applicable plugin or theme
installation capability for Apply, the nonce, installed identity, provider
resolution, credential selection, privacy, current review action, and managed
state.

Review returns one of:

- `adopt`: installed and unmanaged;
- `managed`: already managed with the exact expected tuple;
- `protected`: conflicting, stale, or malformed management exists; or
- `blocked`: identity, provider, credential, destination, capability, nonce, or
  runtime checks prevent safe adoption.

The facade never returns `install`. Apply returns `adopted`, `unchanged`,
`blocked`, or `failed`. `target_verified` is true only for `adopted` or
`unchanged` after exact readback proves the identifier, provider identity,
repository, branch, subdirectory, credential profile, provider privacy, and
Disabled policy.

## Source bridge and operator ownership

Core is source-agnostic. A bridge must own source-version and schema checks,
source reads, source fingerprints, UI, and exact conditional source cleanup.
Core never loads source-plugin code or receives source storage identifiers.

The supported WP Pusher migration is implemented by the separate, removable
[RAN Booster WP Pusher Migrator](https://github.com/RocketsAreNostalgic/ran-booster-wp-pusher-migrator#readme)
plugin. Its bounded Overview and Transporter UI
uses the migration presentation hooks
documented in the [admin composition contract](admin-composition-contract.md);
those hooks do not expand this adoption-only API.

The safe operator sequence is:

1. Confirm exact WP Pusher 3.0.13, then deactivate it everywhere. It must not be
   site-active or network-active during assessment or cutover.
2. Install and activate the migrator. Review each retained row and configure a
   new Booster credential profile where a private repository requires one.
   Legacy credential values are never read or transferred.
3. Apply one row at a time. The package remains installed, becomes
   Booster-managed with Disabled policy, and its exact unchanged WP Pusher row
   is then removed.
4. Resolve any cleanup-pending row while WP Pusher remains inactive. Progress is
   reconstructed from the live source row and exact Booster target; there is no
   migration session or rollback that re-enables either deployment authority.
5. Only after no source rows remain, review WP Pusher's uninstall effects and,
   if desired, delete it through the normal WordPress Installed Plugins screen.
   WP Pusher's own uninstaller removes its settings and package table and
   attempts to revoke this site's license activation. This is a separate
   operator action; the bridge does not perform it.
6. Confirm the site's activation is gone in the WP Pusher dashboard. Revoke the
   site manually there if it remains.
7. Review and remove any WP Pusher Push-to-Deploy webhooks at their provider
   separately. The bridge does not know or delete remote hooks.
8. Verify the migrated packages, then deactivate and delete the migrator.
   Migrated packages remain managed by Core.

Review, cancellation, bridge deactivation, and bridge deletion remove no source
data. Beyond exact verified source-row deletion, the bridge leaves WP Pusher's
settings (including its license key and unknown options), package table, and
plugin files untouched. It does not run WP Pusher's uninstall routine or contact
its license service.

## Recovery from live truth

| Current source and target state                       | Safe interpretation and action                           |
| ----------------------------------------------------- | -------------------------------------------------------- |
| Source row exists; Booster target is absent           | Review and adopt                                         |
| Source row exists; exact Disabled target exists       | Retry exact conditional source-row cleanup               |
| Source row absent; exact Disabled target exists       | Package migration is complete                            |
| Source changed, duplicates exist, or target conflicts | Block; correct the live state and perform a fresh review |

The bridge must not persist a migration saga, session, ZIP, expiry worker,
credential bytes, or duplicate target-state record. Lost responses and retries
reconcile from the two live authorities.

## Deliberate limits

Portability API 3 does not provide:

- package installation, file replacement, activation, or deployment enablement;
- credential creation, transfer, display, validation, or deletion;
- source registration, callbacks, cleanup handles, or arbitrary Core services;
- Blueprint V2, whole-site, database, uploads, content, or multisite migration;
  or
- safe active-active operation between Booster and a source deployment plugin.

Material rejected approaches and their reconsideration triggers remain in the
private architecture review archive; they are not public runtime contracts.

## PHP naming transition

API 3 uses snake_case PHP methods, named parameters and promoted properties.
It requires a matching source bridge; API 2 callers are not compatible. The
serialized candidate keys, nonce action prefixes and review fingerprint format
remain unchanged, including canonical hash payload version 2. Renaming PHP
identifiers does not invalidate a previously generated review solely by changing
its hash inputs. Authorization and fresh review/apply checks remain mandatory.
