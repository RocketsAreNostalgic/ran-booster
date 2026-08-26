# WordPress-native administration composition contract

RAN Booster Core exposes a small set of WordPress actions and filters through
which compatible add-ons can contribute to existing Booster administration
surfaces.

Core also retains a separate, bounded public add-on tab API for a plugin whose
workflow genuinely requires its own dashboard surface. The hooks documented
below replace the former Documentation and Package Extension registries; they
do not replace or deprecate dashboard-tab registration.

The hooks belong to concrete Core surfaces. Core owns their routes,
capabilities, base projections, tables, wrappers, validation, escaping and
unavailable states. Add-ons may enrich the documented data or append one
bounded section; they may not replace a Core template or receive the service
container, database repositories, provider clients, credential store or
secrets sidecar.

This is not a WordPress sandbox. An installed plugin is trusted PHP. The
contract limits accidental coupling and what Core deliberately supplies; it
does not make a malicious plugin safe.

Eligible add-on-owned forms contributed through these surfaces can opt into
Core's shared HTMX lifecycle and feedback without carrying their own UI
updater. The separately versioned
[Enhanced administration interaction API](enhanced-admin-interactions.md)
documents that transport and presentation contract; it does not expand any
composition hook or authorization boundary described here.

## Add-on generation and service delivery

An administration add-on must check the exact Add-on API generation before
attaching its callbacks:

```php
if ( ! defined( 'RAN_BOOSTER_ADDON_API_VERSION' )
	|| 16 !== RAN_BOOSTER_ADDON_API_VERSION ) {
	return;
}
```

Add-on API 16 publishes only the named service documented for its surviving
ready action. Core does not deliver an add-on logging facade, generic resolver
or container.
Provider API 10 remains a separate contract. Provider add-ons must continue to
perform the exact checks described in the
[Provider extension contract](provider-extension-contract.md).

Core fires the portability service-ready action once on `plugins_loaded` at
priority 100, after provider registration and before it resolves the dashboard
and request dispatcher:

```php
do_action(
	'ran_booster_portability_ready',
	$portability
);
```

The argument is a `\RAN\AddOn\Portability\PortabilityFacade`. This is a
request-local delivery point for one exact, already-public safe facade. It is
not a view hook or service locator. An add-on must attach its listener during
normal plugin loading, retain the supplied facade for the current request only,
and perform no remote work in the ready callback. A late listener is not
replayed. Core catches a failed ready listener and continues without exposing
its exception to the administrator.

Release administration is bundled in Core. Add-on API 16 does not publish a
release-tracking ready action, a prospective-release ready action, or a
prospective-release version marker. Core's fixed release controls call its
internal typed coordinators after resolving the selected provider's exact
release facets. A plugin must not treat those internal coordinators as a public
service-delivery API.

Portability API 2 is an independently versioned adoption-only contract for a
trusted source bridge. Its consumer checks exact Portability API 2, not Add-on
API 16. The separate
[Portability API contract](portability-api.md) documents its candidate, nonce,
review, Apply, source-ownership, recovery, and cleanup boundaries.

Core exposes three zero-argument presentation hooks for removable migration
bridges:

- `ran_booster_overview_render_migration_prompt` after the Overview's primary
  setup choices.
- `ran_booster_portability_render_migration_modes` after Transporter's Create
  and Open choices.
- `ran_booster_portability_render_migration_flows` after Transporter's native
  Create and Open flows.

A source bridge renders nothing unless it can verify its exact supported source
evidence. It owns and escapes a bounded prompt plus one matching mode/flow pair;
Core remains source-agnostic and exception-contains each hook independently.
These hooks are not a registry, dashboard tab or whole-view replacement, and
they supply no Core service or data.

Core's release-management controls obtain each mutation's purpose-specific
nonce action from the internal `ReleaseTrackingFacade`. The action is bound to
the operation, package type, exact identifier and current source revision. The
controls create the WordPress nonce, render it only in the matching form and
verify the protected `admin_post_*` request before passing the same nonce to the
coordinator. Core independently derives the action again and rechecks the
administrator capability and nonce before it considers package identity,
eligibility or a source transition. Obtaining an action string or rendering a
nonce authorizes nothing.

Release tracking uses explicit channels:

```php
$preflightNonce = wp_create_nonce(
	$releaseTracking->nonceAction(
		'preflight',
		$type,
		$identifier,
		$expectedSourceRevision,
		$channel
	)
);
$preflight = $releaseTracking->preflight(
	$type,
	$identifier,
	$expectedSourceRevision,
	$channel,
	$preflightNonce
);
$releaseTracking->enable(
	$type,
	$identifier,
	$expectedSourceRevision,
	$channel,
	$nonce
);
$releaseTracking->changeChannel(
	$type,
	$identifier,
	$expectedSourceRevision,
	$channel,
	$nonce
);
```

`preflight()` is the read-only counterpart to enablement's exact verifier. It
requires the current administrator capability and a nonce bound to the package
type, identifier, source revision and channel. It accepts only an eligible
branch-managed plugin or theme at that exact revision, performs no source
transition, cache invalidation or package mutation, and returns `null` when
authorization or binding cannot be proved. A non-null bounded result reports
the existing verifier outcome; it does not authorize later enablement.

The internal typed contract is
[`ReleaseTrackingFacade`](../RAN/AddOn/ReleaseTracking/ReleaseTrackingFacade.php),
whose `preflight()` return type is `?ReleaseTrackingPreflight`, together with
the immutable
[`ReleaseTrackingPreflight`](../RAN/AddOn/ReleaseTracking/ReleaseTrackingPreflight.php)
value object. Its stable result codes are `ready`, `release_unavailable`,
`invalid_release_assets`, `preflight_unavailable`,
`release_version_mismatch`, `release_header_missing`,
`release_header_invalid` and `release_archive_unreadable`. Consumers read only
`code()`, `ready()`, `packageRoot()`, `latestVersion()`, `releaseUrl()`,
`releaseTag()`, `packageHeaderVersion()` and `reasonCode()`. The stable `code()`
remains the workflow category; `reasonCode()` is an optional, allowlisted,
display-safe cause for actionable diagnostics and never contains provider
response bodies, URLs, paths, tokens or exception messages. Unknown or unprovable
authorization, package, revision, channel or result binding fails closed as
`null`; it must not be interpreted as a new result code or as permission to
enable release tracking.

`changeChannel()` changes future eligibility only. It increments the source
revision, resets Automatic to Manual and invalidates native update state; it
does not install, downgrade or alter package files. `ReleaseTrackingStatus`
exposes the canonical `stable` or `prerelease` channel.

Prospective installation is an optional Core control. Core exposes it only when
one registered provider aggregate supplies the complete release capability set
required by the operation. It does not publish a prospective-release marker or
deliver the internal facade to another plugin.

The facade accepts exactly:

```php
$providerCodes = $prospective->supportedProviderCodes( $type );
$prospective->listCandidates( $type, $repository, $channel, $nonce );
$prospective->inspect( $type, $repository, $releaseId, $tag, $channel, $nonce );
$prospective->install(
	$type,
	$repository,
	$releaseId,
	$tag,
	$expectedFingerprint,
	$channel,
	$nonce
);
```

`supportedProviderCodes()` returns the bounded complete-product provider list
for `plugin` or `theme` using request-local configuration only. It performs no
repository resolution, credential access, remote request, discovery or
mutation. Core keeps the prospective source unavailable when the selected
provider is absent from that list. The complete-product list requires
the exact listing, inspection, acquisition, metadata and native-target facets.
Each operation also checks its purpose-specific facet before resolving the
repository or invoking provider work and returns `unsupported_provider` when it
is absent.

`$channel` must be exactly `stable` or `prerelease`; Core rejects any other
value before resolving credentials or invoking the updater. The channel is
carried through candidate listing, exact inspection and exact acquisition, and a
successful installation persists it for subsequent native WordPress update
registration. `stable` accepts stable releases only. `prerelease` accepts
prereleases and a later stable promotion. Stable remains the default for
existing saved configuration.

All three operations recheck `manage_options`, the applicable WordPress install
capability and the operation/type nonce derived from `nonceAction()`.
Candidate listing returns at most eight display-safe summaries without
downloading a ZIP. Inspection downloads, validates and discards the exact ZIP,
then returns bounded metadata and a strict continuity fingerprint; installation
freshly reacquires the exact release, verifies the fingerprint and package,
refuses an already installed, managed or active target, delegates mutation to
WordPress Core, verifies that the new install remains inactive, and reports
adoption separately. The facade never exposes
credentials, signed URLs, internal descriptors, archive paths or provider
responses. A partial provider capability set exposes no prospective choice and
grants no repository, credential, remote, download, or mutation authority.

## Add-on dashboard tabs

An add-on may register one self-contained dashboard tab during the existing
registration lifecycle:

```php
add_action(
	'ran_booster_register_admin_tabs',
	static function ( \RAN\Admin\AdminAddOnRegistry $registry ): void {
		$registry->register(
			new \RAN\Admin\AdminAddOnTab(
				'example-addon',
				'example',
				__( 'Example', 'example-addon' ),
				static function ( \RAN\Admin\AdminAddOnContext $context ): void {
					// Render escaped, add-on-owned administration markup.
				},
				7,
				7,
				7,
				7
			)
		);
	}
);
```

Core invokes the registration action on `plugins_loaded` at priority 100 and
then seals the registry. It rejects unsafe or duplicate keys, more than one tab
from the same add-on, late registration, incompatible API bounds and requests
for undeclared facades. Registered tabs appear after Core and provider tabs.

When selected, Core creates an immutable `AdminAddOnContext` only after the
`manage_options` check. The context supplies the tab key, canonical Booster
URL, `site` or `network` administration scope, Core/Add-on API versions,
and only the explicitly requested allowlisted facade. It
does not expose the service container, repositories, credentials, secrets or
arbitrary Core models. Core contains a renderer failure and keeps the remaining
dashboard usable.

Release management and repository webhook-management placement are Core code,
not add-on composition consumers. Bitbucket uses the provider-tab contract.
This does not remove the public tab capability for other add-ons.

## Fixed Extensions page

Core owns the **RAN Booster > Extensions** submenu at
`ran-booster-extensions`. The `manage_options`-gated page renders the remaining
release-bundled first-party cards in deterministic order. Its catalogue, local
placeholder images, installed-plugin reads, compatibility labels and failure
shell are all Core-owned.

The page uses WordPress plugin-card presentation language without invoking the
WordPress.org Plugins API or installer. It performs no remote request, mutation,
entitlement check, update registration or add-on callback. Free acquisition is
disabled until the corresponding repository and public release pass their
human-readiness gate. The page stores no sponsorship or entitlement state.

Installed and active state comes only from local WordPress plugin state. Exact
runtime compatibility remains owned by each installed extension's fail-closed
API guard. The page exposes no new action, filter, facade, registry or persistent
state, and its local stylesheet is limited to the Extensions screen hook.

## Provider repository surface

Core owns and renders the provider repository table. Its internal webhook
controls enrich rows and render the selected-repository panel only when the
selected provider resolves both `RepositoryWebhookFitness` and
`RepositoryWebhookManagement`, plus `WebhookNormalizer`, to the same registered
aggregate. Core validates
the resulting rows, preserves the fixed `core:webhook-management` action, and
permits only bounded historical records from its own schema. There is no public
row or panel composition hook, renderer callback or provider-supplied field
schema.

Missing, partial or incompatible webhook facets create no action, panel,
documentation section, asset or mutation authority. A complete non-GitHub
provider receives the same fixed Core placement; its bounded metadata supplies
the provider code and label, while its facet implementation owns remote calls,
credentials and provider-specific remediation.

## Package screen anatomy

Plugin and theme installation and management use the same information
architecture. Core renders, in order: page identity, **Repository
configuration**, **Advanced settings** with **Package source**, source-specific
settings and readiness, **Package operation**, any bounded management-only
sections, and **Danger zone**. Add-ons contribute inside those Core-owned
regions; they do not create a parallel installation or management layout.

The lifecycle changes available controls, not their order:

- prospective published-release installation selects and inspects one exact
  release; a managed package instead selects the Stable or Preview track for
  future WordPress updates;
- branch and published-release readiness differ, but occupy the same
  source-specific position;
- saved-package workflow assistance, status, reinstall and source-transition
  controls appear only when their required package state exists; and
- the management summary and Danger zone have no installation equivalent.

Plugin and theme screens may differ only where WordPress package identity,
activation or theme-state rules require it. Their headings, regions and source
flow remain shared.

## Managed-package surfaces

Core exposes display-safe package data through
`\RAN\Admin\AdminPackageProjection`. Its accessors are:

```php
$package->type();             // "plugin" or "theme".
$package->identifier();
$package->displayName();
$package->providerCode();
$package->source();           // "branch" or "release_asset".
$package->sourceRevision();
$package->deploymentPolicy(); // "disabled", "manual" or "automatic".
$package->settingsUrl();
```

An add-on may add bounded badges and status text to the managed Plugins or
Themes table:

```php
$rows = apply_filters(
	'ran_booster_admin_package_management_rows',
	$rows,
	$surface,
	$packageProjections
);
```

`$surface` is `plugin` or `theme`, and `$packageProjections` is keyed by package
identifier. Returned rows may address only those identifiers. Each row accepts
a bounded `status` string and a `badges` list whose tones are `neutral`, `ok`,
`pending`, `warning` or `error`.

Core obtains structured actions separately for each package:

```php
$actions = apply_filters(
	'ran_booster_admin_package_management_actions',
	$actions,
	$surface,
	$package
);
```

`$package` is an `\RAN\Admin\AdminPackageProjection`. The filter may append
namespaced link or POST actions. Core validates and escapes them before
rendering. Core package views do not contain release-specific action keys,
labels, forms, nonce values or handlers. The Core-owned release facade only
defines the nonce action scope that both authorization layers must verify.

An add-on may append a bounded settings section to an individual managed
package:

```php
do_action(
	'ran_booster_admin_package_settings_sections',
	$package,
	$settingsUrl
);
```

`$package` is an `\RAN\Admin\AdminPackageProjection` and `$settingsUrl` is its
canonical settings URL. Core output-buffers this action and contains failure at
the section. The add-on owns escaping inside its markup and mutations belong in
its own `admin_post_*` handlers.

Package-source add-ons may additionally extend Core's source selector through
two narrow hooks:

```php
apply_filters(
	'ran_booster_admin_package_source_choices',
	$choices,
	$mode,
	$type,
	$package,
	$pageUrl
);

do_action(
	'ran_booster_admin_package_advanced_source_sections',
	$mode,
	$type,
	$selectedSource,
	$package,
	$pageUrl
);
```

The advanced action is only for source-specific fields such as branch,
subdirectory, release track and exact candidate selection. Add-ons may filter
`ran_booster_admin_package_advanced_source_summary` with the same mode, type,
selected source and projection arguments, but must return one bounded
plain-text summary.

Repository-scoped release automation belongs on the selected repository page:

```php
do_action(
	'ran_booster_admin_repository_release_sections',
	$repositoryRow,
	$returnUrl
);
```

Core owns the repository tabs and passes one normalized exact repository row.
Providers may render bounded package-specific workflow status and forms, but
must reauthorize the exact provider, repository ID, package identity and source
revision before mutation. Rendering must use local evidence only; remote
inspection requires an explicit administrator action.

## Structured administration actions

Provider-row and package-management actions use a keyed structure:

```php
array(
	'example-addon:refresh' => array(
		'label'         => __( 'Check published releases', 'example-addon' ),
		'type'          => 'post',
		'url'           => admin_url( 'admin-post.php' ),
		'hidden'        => array(
			'action'   => 'example_addon_refresh',
			'_wpnonce' => $nonce,
			'package'  => $packageIdentifier,
		),
		'disabled'      => false,
		'external'      => false,
		'described_by'  => '',
		'screen_reader' => $packageIdentifier,
	),
);
```

Keys must be bounded and namespaced with exactly one colon. `type` is `link` or
`post`. Links must not contain hidden fields. POST actions must target exactly
`admin_url( 'admin-post.php' )` and include bounded scalar `action` and
`_wpnonce` hidden values. Core fixes the method to POST and escapes every
rendered value.

Normalization proves only that Core can render the control safely. The
add-on-owned handler remains responsible for its first capability, nonce,
expected identity, expected source revision and operation validation before
calling a mutation. The release facade independently repeats the capability
and nonce checks against its Core-owned action scope before mutating anything.

Core automatically enhances normalized structured POST actions with its shared
busy and feedback lifecycle. Structured links remain navigation, and raw forms
rendered by an add-on are not intercepted. An add-on-owned raw form that needs
an allowlisted partial refresh must use the
[Enhanced administration interaction API](enhanced-admin-interactions.md)
explicitly.

## Documentation filters

Documentation uses append-only structured WordPress filters:

```php
$sections = apply_filters(
	'ran_booster_documentation_sections_after_provider_' . $providerCode,
	array(),
	$documentationUrl,
	$scope
);

$sections = apply_filters(
	'ran_booster_documentation_sections_before_about',
	array(),
	$documentationUrl,
	$scope
);
```

The dynamic provider filter runs immediately after the matching registered
provider's Core guide. The general filter runs before Core's About section.
`$documentationUrl` is the canonical Booster Documentation URL and `$scope` is
`site` or `network`. Each callback accepts and returns the section list;
WordPress callback priority supplies ordering when more than one plugin uses a
hook.

Each appended section supplies a stable `id`, plain-text `summary`, string or
callable `content`, and optional Boolean `open`. Core owns the disclosure
markup and includes every valid rendered section in the page's On this page
index. IDs must be unique across the whole Documentation page; the first valid
section owns an ID and a later contribution using it is not rendered.
Documentation content must not contain forms, nonces, AJAX or REST handlers,
remote calls, asset enqueueing, settings or deployment operations.

Core validates every section, captures callable content, sanitizes it with the
normal `wp_kses_post()` allowlist minus the `id` attribute and renders it only
when non-empty. Contributed content cannot own nested IDs: the Core-owned outer
section `id` is the section's only page anchor. If a content callback throws,
Core discards the captured output, logs a redacted failure and displays one
local unavailable message.

Deactivating an add-on removes its contributions on the next request; every
Core route and administration surface remains usable.
