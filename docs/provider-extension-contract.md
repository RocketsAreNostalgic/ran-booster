# Provider extension contract

RAN Booster Provider API 10 accepts trusted repository providers through its late
registration action. A provider plugin attaches a callback from its main plugin
file during normal plugin loading:

```php
add_action(
	'ran_booster_register_providers',
	static function ( \RAN\RepositoryProvider\ProviderRegistry $registry ): void {
		if ( ! defined( 'RAN_BOOSTER_PROVIDER_API_VERSION' )
			|| 10 !== RAN_BOOSTER_PROVIDER_API_VERSION ) {
			return;
		}

		$registry->registerWithCredentialStore(
			'example',
			static fn (
				\RAN\RepositoryProvider\ProviderCredentialStore $credentials,
				\RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader $deliveryEvidence
			): ExampleProvider => new ExampleProvider( $credentials, $deliveryEvidence )
		);
	}
);
```

Booster defines the integer `RAN_BOOSTER_PROVIDER_API_VERSION` marker before the
registration action can run. The callback must check for exact Provider API 10.
`Requires Plugins: ran-booster` only tells WordPress about the package
dependency; it does not replace this exact runtime marker check or make a
mismatched provider contract safe.
Provider API 10 publishes no logging facade, generic service resolver, Core
container, credential writer, sidecar path or database/deployment repository.
Providers report bounded diagnostics and operation results. Core owns logging
at its call boundaries and never supplies a logger to provider code.
Booster fires the action once on `plugins_loaded` at priority 100, after plugin
files have loaded and before the dashboard, dispatcher, repository picker or
webhook controller is resolved. It then seals the registry. This works whether
the provider plugin file loads before or after Booster because both attach
their callbacks before `plugins_loaded` runs. Registration after sealing fails;
a newly activated provider becomes available on the next request.

`registerWithCredentialStore()` is the required path for a provider that reads
stored credentials. Booster verifies that the requested code is novel before it
issues two read-only values bound to that code, then verifies that the returned
provider uses the same code before atomic registration:

- `ProviderCredentialStore` exposes display-safe `credentialProfiles()`, one
  selected/default `credentialMaterial()` read and boolean
  `hasWebhookProfile()` readiness. It has no provider argument, write methods,
  path access or webhook-secret access.
- `AuthenticatedWebhookDeliveryEvidenceReader` exposes only
  `latestAuthenticatedDelivery()` for the already-bound provider. It has no
  provider argument and exposes neither the deployment database repository nor
  general attempt history.

Core binds both values before invoking the callback, so retaining either value
cannot cross provider namespaces. Repeated exact credential reads mean an
activated credential-bearing provider is trusted with all credentials saved
under its code. Core does not authenticate the provider publisher, and cannot
control private provider logging or exfiltration after authorized disclosure.
The factory and provider constructor must remain local and non-I/O and must not
read either supplied value during construction: provider policy becomes active
only after the factory returns successfully. Validators, discovery clients and
archive clients may retain the credential store and read it after registration;
webhook diagnostics may retain the evidence reader. Both use Core's live state
without receiving its storage implementations.

Registration failures expose fixed, redacted messages and retain no upstream
exception. A rejected provider publishes neither provider nor policy state, so
the same provider code may be corrected and registered again during the same
registration window.

The current collision and same-vendor coexistence behavior is characterized in
[Provider registration and coexistence](provider-registration-and-coexistence.md).
Provider API 10 rejects an exact duplicate code but does not reserve vendor
aliases, identify two implementations of the same vendor, or merge their
capabilities and state.

### Optional repository-webhook operation

A provider may implement both `RepositoryWebhookFitness` and
`RepositoryWebhookManagement` for the exact operation
`repository-webhook-management/3`. These interfaces expose only the five
closed actions `setup`, `check`, `reconfigure`, `remove` and `test`, with
matching read-only `assessSetup`, `assessCheck`, `assessReconfigure`,
`assessRemove` and `assessTest` methods. There is no operation dispatcher,
callable, provider client, transport or credential handle in the public contract.

Saved credential IDs are display-safe inputs. The provider resolves their
plaintext only through its already-bound `ProviderCredentialStore` and only
inside the selected fixed call. Webhook management accepts one saved credential
ID. Setup may select one applicable saved signing-secret profile or request a
new repository-scoped secret; only setup and reconfigure receive the Core-held
signing secret.
The fixed management placement also requires `WebhookNormalizer` on that same
aggregate, proving that provider registration supplied the signing policy Core
needs to create and retain webhook profiles. Operation facets without that
policy receive no UI or route authority.

Immediately before each management call, Core invokes the matching read-only
assessment with the same credential source. The provider must remotely compare
the repository locator with the stable repository ID supplied by Core. Execution
continues only for `supported`, `suitable|unknown` and
`observed|inferred|unknown_by_design` evidence. A mismatch is `insufficient`;
unavailable or stale evidence fails closed before remote mutation. Fitness does
not otherwise grant execution authority, and its one-call budget remains
separate from the fixed management budget.

Core places one fixed repository-row action and selected-repository panel only
when both operation interfaces and `WebhookNormalizer` resolve to the same
registered provider aggregate. The
provider's bounded metadata supplies its code and label; the provider owns
credential use, remote behavior and the bounded plain-text remediation returned
by its operation result. Missing, partial or incompatible facets create no
action, panel, documentation, asset or mutation authority. This placement is
not a capability enumerator, generic form schema, callback registry or provider
HTML seam.

Check and remove deliberately receive Core's canonical callback URL as well as
the recorded hook ID. This is the minimum input needed for the provider to
prove that the exact remote hook is owned by the selected Core target before
readback or mutation; the URL is not a configurable transport seam.

#### Management presentation

Core owns one fixed administration form and operation route for providers whose
single registered aggregate implements both webhook operation facets and the
signing-policy normalizer. Core derives only
the bounded provider code and label from metadata; providers cannot supply
fields, HTML, callbacks or route behavior. The fixed host owns authorization,
credential-source choice, result bounds and the schema-4 recovery record, while
the provider facets own credential use, remote behavior and bounded plain-text
remediation. Core publishes no renderer registry, callable transport, generic
dispatcher or raw credential handle.

`RepositoryWebhookFitnessResult` and `RepositoryWebhookOperationResult` admit
only bounded, closed, non-secret evidence. Setup and reconfigure can establish
`configured_pending_delivery`, not delivery verification. Remove confirms
success only after exact `404` absence readback. Providers must preserve the
documented call, byte and timeout ceilings in the
[fitness characterization](characterization/provider-owned-repository-webhook-fitness.md).

Core serializes each target's assisted operations with a nonblocking advisory
database lock keyed by provider code and stable repository ID. The lock has no
table, option, file or durable record; contention fails before local or remote
mutation. If explicit release is uncertain, primary failed, ambiguous, partial
or confirmed-absence evidence is preserved; only an otherwise successful
non-absence result becomes `operation_lock_release_failed`. A sole existing
endpoint found during setup is not adopted because
its signing secret is unreadable. The result is recovery-required and omits the
hook ID so it cannot seed a later assisted removal; only explicit reconfigure
may replace that secret.

## Required provider surface

Every provider implements `RepositoryProvider`. That contract directly requires
metadata, a bounded diagnostics object, repository resolution and immutable
archive preparation: every registered provider therefore has a useful manual
install/update path. Metadata supplies an open, stable `ProviderCode`; IDs start
with a lowercase ASCII letter and may be followed by up to 31 lowercase ASCII
letters, digits or hyphens.
The built-in page IDs `overview`, `documentation`, `troubleshooting` and
`portability` are reserved.

Provider admin metadata is rendered directly after construction, so its typed
constructors enforce the display-safety boundary. Labels are limited to 160
bytes, summaries to 2,000 bytes, and placeholders, descriptions and webhook
guidance to 500 bytes. Credential-kind, credential-field and webhook-scope
identifiers retain their lowercase letters, numbers, underscores and hyphens
syntax and are limited to 64 bytes. Required text must be non-empty; optional
text may be empty. All raw display text and URLs must be single-line and free
of control characters, including before surrounding whitespace is trimmed.
Documentation links are limited to 2,048 bytes and must be public HTTPS URLs
without username or password components; query strings and fragments are
allowed. The repository URL base remains stricter and permits neither query
strings nor fragments. Providers should treat constructor rejection as a
registration-time metadata error rather than attempting to sanitize unsafe
content later.

Admin metadata may include an ordinary `ProviderNavigationPlacement`. Its group
is `git-host` or `other-provider` and its slot is an integer from 1 through
10,000. These values are provider-declared ordering metadata, not reserved
GitHub or Bitbucket privileges. Providers without an explicit placement sort in
the `other-provider` group at slot 10,000; equal placements are ordered by the
stable provider code.

Provider metadata supplies concise setup and webhook guidance; it does not
register or render a full add-on guide. A provider add-on that contributes
non-interactive documentation attaches separately to the provider-specific
structured WordPress filter:

```php
add_filter(
	'ran_booster_documentation_sections_after_provider_' . $providerCode,
	$callback,
	10,
	3
);
```

That callback belongs to the
[WordPress-native administration composition contract](admin-composition-contract.md),
not the Provider API. It receives the current structured section list,
canonical Documentation URL and administration scope, not the provider
registry or provider implementation.

Repository locators are provider-owned opaque strings. Booster preserves their
accepted bytes, rejecting all-whitespace values, control characters and values
longer than 512 bytes, but does not impose an `owner/repository` shape.
`resolveRepository()` must return a
`RepositoryDescriptor` containing the provider code, canonical locator, stable
provider repository ID, privacy, default branch, selected credential ID and a
single safe package slug for the initial WordPress installation. Stable
repository IDs and package slugs are limited to 191 bytes. Providers with
nested namespaces may therefore accept locators such as
`group/subgroup/package` without core changes.

Lookup, browse and archive-reference inputs are receiver-bound and therefore do
not repeat the selected provider code. Core selects one registered provider
before constructing them. Provider identity remains mandatory on descriptors,
webhook events and stored package/deployment data because those values cross
back into provider-neutral Core. Core rejects a descriptor whose provider does
not match the selected aggregate; add-ons must not dispatch these input values
to a provider without first selecting it through `ProviderRegistry`.

The provider package slug is transient: Booster uses it only to relocate and
find the package during initial installation. Managed plugin updates derive the
slug from the installed WordPress plugin file; managed theme updates derive it
from the installed stylesheet. The package table persists the provider code,
opaque locator and stable repository ID, not a provider-specific slug or legacy
host alias.

Archive preparers must return an HTTPS URL with a host and without URL
userinfo or fragments. Providers must not place reusable credentials,
authorization material or long-lived secrets in archive URLs, including query
parameters. Booster applies this generic safety floor before streaming the
archive into its private preflight file; WordPress receives only that verified
local file. Providers remain responsible for any stricter origin, path and
signed query policy required by their service.

Provider API 10 owns two shared helpers for ordinary vendor implementations:

- `GitReferenceSyntax::isValidNamedReference()` applies Core's bounded generic
  branch/ref syntax check without assuming a particular hosting vendor.
- `AuthenticatedPreparedArchive` implements `PreparedArchive` and owns the
  one-request authentication, redirect scrubbing, current-head verification and
  cleanup lifecycle. Its optional authorizer is consumed only for the exact
  archive URL, and reusable authorization must not survive a redirect.

Providers may enforce stricter vendor syntax and archive-origin policy. They
must not weaken the shared authentication cleanup lifecycle or reproduce Core's
storage or deployment machinery.

`prepareArchive()` resolves the requested branch, tag or commit exactly once to
an immutable commit and builds the archive URL from that resolved value.
`PreparedArchive::getResolvedRef()` exposes it to the deployment journal. For an
automatic request with an expected branch, the provider checks the branch while
preparing and supplies `verifyCurrentHead()` to repeat that fixed provider-owned
check immediately before WordPress mutates the package. Manual preparations use
a no-op verification. Provider resolution and head verification are limited to
three 15-second requests in total. Private authentication applies only to the
exact first archive request and must be removed before any redirect.

If a provider add-on is deactivated, Booster retains those stored identities
and presents the package as unavailable. It must not substitute another
provider. Deployment and provider actions remain disabled until the same code
is registered again; unlinking the management record remains available.

The supplied `ProviderDiagnostics` object owns a fixed, bounded set of checks
including credential configuration/authentication and repository
reachability/scope. A provider may add a bespoke prerequisite while remaining
within the coordinator's overall check, call and deadline limits. It must use
the same provider client, credential loader, validator and resolver as discovery
and deployment. It returns only `ProviderDiagnosticResult` objects containing:

- `status`: `pass`, `warning`, `fail` or `not_configured`;
- a stable machine code;
- a safe, single-line message; and
- safe remediation text; and
- only for an unexpected caught failure, an optional request-local `Throwable`
  for Core-owned observability.

Core validates the bounded display fields before it logs that request-local
failure at the troubleshooting call boundary. `ProviderDiagnosticResult::toArray()`
omits the failure, so it is never administrator copy, persisted diagnostic
state or a transport value. Expected denial, not-found, rate-limit, invalid-data
and ordinary unavailability outcomes remain typed results without a failure.
Providers receive no logging facade and must not attach upstream messages,
headers, responses or credentials to the safe display fields.

The request permits at most five remote calls and has a monotonic deadline of
ten seconds. Troubleshooting renders at most eight diagnostic rows in total:
five Core-local rows and at most three rows from the selected provider,
including optional webhook readiness. If a provider returns more than the
remaining slots, Core truncates the results and marks the report
`result_limit_exhausted`. Provider code calls `claimRemoteCall()` immediately
before each request and passes the returned remaining timeout to its production
client. Raw responses, headers, exceptions and credentials must not be returned.
Supplying the diagnostics object during registration must be a local,
non-network operation; remote work starts only from `diagnose()`.

## Optional capabilities

Repository browsing, credential-form validation and webhook normalization are
separate optional capabilities. In particular, a provider may omit
`WebhookNormalizer`; manual deployment remains available and Push-to-Deploy
fails with the normal unsupported-capability result. A provider that omits
webhooks must also publish no webhook scopes in its admin metadata.

The registered provider object is the provider's open set of optional
capabilities. Each capability is a purpose-specific interface extending
`ProviderCapability`, and Core resolves it by its exact interface name. The
bare marker, the base `RepositoryProvider` interface, concrete classes and
non-marker interfaces are not capability contracts. A provider-owned marker
interface can use the same resolver without a Core branch, but its presence
does not create Core UI, routes or authority. Core does not enumerate
capabilities, negotiate versions, or accept string descriptors; a breaking
change to a published capability interface requires a new Provider API
generation.

`RepositoryReleaseMetadata` is an independent, local release capability. It
maps a provider-owned repository reference to its canonical WordPress Update
URI and maps one exact release tag to the provider's public release-details
URL. Implementing it performs no discovery or download and does not, by itself,
enable release deployments. Core keeps release tracking unavailable when the
facet is absent or returns no canonical Update URI.

`RepositoryReleaseCandidateListing` is the independent, read-only remote facet
for listing at most eight published releases for one resolved repository,
package type and exact `stable` or `prerelease` channel. It returns only typed,
bounded candidate values; an empty list means that no eligible release exists,
while an exception means listing was unavailable. The provider owns its remote
calls, credential use and response normalization. The facet downloads no
archive and grants no inspection, acquisition, installation or mutation
authority. Publication time and expected ZIP names remain in the typed value
only because the unchanged standalone facade still validates them; they grant no
artifact authority. The bounded list wrapper enforces the result limit, typed
members and unique provider identities at the capability boundary. List order
is the provider's bounded inspection preference. Core inspects at most the first
two candidates in that order, continues only when the provider classifies the
package as incompatible, and accepts only exact listing-to-inspection identity
continuity. A vanished, corrupt or contradictory preferred release fails closed
without falling through to an older release. The facet does not by itself make
the complete release product available. Until those remaining operations have
their own provider facets,
Core's complete-product projection continues to advertise only the bundled
GitHub implementation. A provider implementing candidate listing alone remains
available to an authorized listing consumer but receives no complete-product UI
or later-operation authority. The temporary standalone-add-on facade can project
only positive integer release identities; opaque provider identities remain
valid contract values but require the later hard cut before that facade can
consume them.

When a release listing or inspection read cannot be completed with the supplied
repository access profile because of credential/access denial, rate limiting, or
transport failure, it must throw the typed
`RAN\RepositoryProvider\RepositoryReleaseReadUnavailable`. Core alone may use that signal to retry a
public repository through its separately configured public lookup profile. Do
not use this exception for an empty eligible-release list, an invalid release,
or package incompatibility; return the relevant typed result for those cases.
Do not catch the signal and replace it with a generic exception, and do not
retry or switch credentials inside a provider.

`RepositoryReleaseInspector` is the independent remote facet for inspecting one
exact provider release. It accepts the package type, resolved repository,
opaque provider release identity, exact tag and `stable` or `prerelease`
channel. The provider acquires, verifies and discards the archive within the
call, and returns one bounded `RepositoryReleaseInspection` containing only the
provider release identity, tag, version, provider commit identity, package root,
main file and opaque fingerprint. The value exposes no local path, URL,
credential, archive or artifact handle. `RepositoryReleaseInspectionRejected`
distinguishes `no_releases`, `invalid_release` and package `incompatible`;
other exceptions mean the inspection operation was unavailable. Only
`incompatible` permits Core to try the next provider-ordered candidate. The
facet grants no installation,
updater or mutation authority and does not by itself make the complete release
product available.

`RepositoryReleaseAcquirer` is the independent custody facet for freshly
acquiring the exact release selected after inspection. It accepts the same
resolved repository, package type, provider release identity, tag and channel,
plus the opaque fingerprint returned by inspection. The provider must
re-describe, re-download and revalidate the release before returning one
`RepositoryReleaseArtifact`. That artifact exposes no URL, path, credential or
archive bytes. It permits one typed handoff to Core and otherwise owns bounded
discard. `RepositoryReleaseAcquisitionRejected::invalidRelease()` is the only
release-domain rejection. If cleanup of provider-owned bytes fails before Core
can take custody, the provider must instead throw the bounded
`cleanup_failed` rejection; Core reports that failure without attempting an
install. Other exceptions mean acquisition was unavailable.
The facet grants no WordPress installation or adoption authority. Core retains
the updater claim through `PreparedArtifact`, owns installation and installed
readback, and discards the exact claim after use.

`RepositoryReleaseNativeTargets` is the exact optional capability for joining
WordPress's native plugin or theme update flow. Core supplies the resolved
repository, Core-derived metadata path, installed identity, channel and
deployment policy. The provider owns collision detection, lazy credential use,
updater construction and registration, and projects only a typed
`RepositoryReleaseNativeTarget`. Its `RepositoryReleaseNativeTargetStatus` is a
bounded passive value: it contains normalized availability, offered-version,
check-time, failure and candidate-validation fields, never the provider's raw
updater object, diagnostics array or internal runtime state. Refresh returns an
exact boolean. Missing capabilities, failed registration, invalid status and
failed refresh all fail closed. Release tracking requires this capability and
`RepositoryReleaseMetadata` on the same registered provider aggregate;
metadata alone is not eligibility. Core retains package enumeration, metadata
path derivation, authority snapshots, WordPress hook timing, locks, stale-offer
suppression and package-source transitions. Native target implementations must
perform credential use, remote acquisition and download only after Core's
earliest `upgrader_pre_download` authority fence; the bundled GitHub updater
runs its acquisition filter at `PHP_INT_MAX`.

`RepositoryWebhookSettingsLink` is an independent display capability. It maps
one provider-owned repository locator to that repository's stable HTTPS webhook
settings screen. Core treats locators as opaque, validates the returned URL, and
shows plain repository text when the capability is absent, throws, or returns
an unsafe URL.

`CredentialValidationResult::valid()` accepts no argument when expiry is not
reported. A validator may instead return bounded expiry metadata:

```php
return CredentialValidationResult::valid(
	CredentialExpiryReport::known( '2026-12-31T23:59:59Z' )
);
```

Use `CredentialExpiryReport::unknown()` only when the provider performed a
successful check but supplied no trustworthy expiry value. Leave the result's
expiry property `null` when the provider does not implement expiry reporting.
Core stores only the normalized UTC timestamp and provider-check time; providers
must never return or persist raw response headers. Provider-reported expiry
takes precedence over Booster's optional manual profile date. A provider that
does not report expiry remains fully compatible and receives the same manual
fallback, row status, and administrator notice behavior.

A provider that implements `RepositoryBrowser` receives one public-owner or
one selected-credential `RepositoryBrowseRequest`. It must claim every remote
call from that request, apply its response-size limit and return a
`RepositoryBrowseResult`. It may follow provider-owned opaque pagination only
while the request has capacity. Results stop at the shared 200-item limit; if a
later page fails, the provider returns the already validated rows with one of
the result's fixed partial reasons. Providers must not scan every saved
credential, expose cursors, retry requests or return upstream response text.
The current per-picker budget is five calls and eight seconds, with 256 KiB per
response and 1 MiB across the request.

`CredentialedPublicRepositoryBrowser` is a narrower, additive browsing
capability. It allows a public-owner request to carry one provider-local access
profile ID and returns typed metadata stating whether one profile may be
configured as the provider-wide default. Core requests this capability before
forwarding any authenticated Public-mode request; a provider that implements
only `RepositoryBrowser` continues to receive anonymous public-owner requests
and rejects forged credentialed-public requests through the normal
unsupported-capability path.

Credentialed public browsing remains public-only. Providers must not return
private descriptors or attach the selected lookup profile to result
descriptors. The profile authorizes only repository browsing and exact
server-side save verification; it is not a durable package credential. During
that exact verification, `RepositoryLookupRequest::$publicOnly` is `true`.
Providers may use that context to relax a restriction that applies only to
durable private access, but must then reject any private exact result. All
ordinary, Accessible-mode and deployment lookups receive `false`.

Credential-bearing providers implement `ProviderCredentialPolicySupplier` and
return a provider-owned `ProviderCredentialPolicy`. The policy normalizes the
provider's credential kinds and configuration, declares only the deployment
constants that provider understands, and converts those constants into the same
canonical credential record. This keeps the sidecar's file operations atomic
and provider-neutral while preventing unknown provider IDs from reaching file
inclusion or secret decoding. Credential policy lookup during registration is a
local, non-I/O operation. File-backed display and storage checks are Core-only
structural operations. Core revalidates only the selected, non-expired stored
credential under its current provider policy immediately before delivery, and
provider callbacks never run while Core holds the sidecar lock. A displayed
file-backed profile is stored; its provider validity is checked on use.

A credential policy may additionally implement the optional
`SubmittedCredentialValidator`. Core calls
`validateSubmittedCredential( $metadata, $secret )` only for newly submitted or
replacement material; existing saved, constant-backed and imported credentials
continue to bypass this shape check so a later provider-format change cannot
make historical material unreadable.

Validation may throw `InvalidCredentialInput` with one of three provider-neutral
reasons: `invalid_configuration`, `credential_kind_mismatch` or
`invalid_secret_shape`. Its provider-owned display message must be non-empty,
single-line plain text of at most 512 bytes. Core rejects unknown reasons and
text containing control characters, markup, structured data, paths, credentialed
URLs, authentication/cookie headers, token-shaped values or authorization
material. An invalid bounded failure or any other exception collapses to Core's
one generic administrator-safe message. Core reconstructs an accepted failure
at its secret-custody boundary, so provider exception arguments never cross it.

### Transporter credential boundary

Core owns Transporter package and credential selection, password-protected
Blueprint custody, request-local decision parsing, temporary Preview material
and target-side persistence. A matching active provider receives only its
provider-bound selected material for the existing exact-repository verification
request. It receives no archive password, Blueprint parser, source profile ID,
cross-provider credential access, persistence authority or fallback choice.
Provider-native expiry may inform ordinary provider validation, but it is not a
Transporter transfer gate or permission-fitness claim. Local self-destruct
eligibility remains a Core custody decision made before export.

Webhook support remains optional. A webhook-capable provider implements
`WebhookNormalizer`, whose `getWebhookPolicy()` returns its
`ProviderWebhookPolicy`. That policy declares the small allowlist of request
headers Booster may retain, normalizes provider-owned webhook scope records and
declares any supported deployment constants. Signature ambiguity and semantic
header validation remain inside the provider normalizer. Booster retains no
authorization or unplanned request headers, and it passes only the selected
provider's declared headers to that normalizer.

Core structurally reads webhook profiles for display. Before signature
verification it revalidates only the requested provider's bounded candidate set
under the current webhook policy, outside the sidecar lock. Each provider
remains limited to 16 stored webhook profiles.

Webhook signing-secret scope codes are universally `owner` or `repository`.
Providers may relabel `owner` for their interface—for example **GitHub Owner**
or **Bitbucket Workspace**—but may not introduce additional logical scopes.

Core's separately versioned prospective-release facade resolves
`RepositoryReleaseCandidateListing` before repository resolution and maps its
typed values to the current add-on response. Candidate listing can therefore be
implemented independently without granting authority over inspection,
acquisition or installation. `supportedProviderCodes()` remains the
complete-product projection used by the unchanged add-on and currently exposes
only the bundled GitHub provider when it implements the listing facet. It does
not resolve a repository, read credentials or make a remote request.
Registering a provider, or implementing listing alone, does not imply complete
published-release support. Core rejects listing with `unsupported_provider`
when the selected provider omits the listing facet, and rejects later
prospective operations when the provider is absent from the complete-product
projection; both checks happen before repository resolution.

For an already managed branch package, Core composes
`RepositoryReleaseCandidateListing`, `RepositoryReleaseInspector` and
`RepositoryReleaseMetadata` for the protected preflight that precedes a source
transition. The provider owns candidate ordering, remote calls, credentials and
archive inspection. Core inspects at most two of the eight listed candidates,
falls through only for package incompatibility, and checks exact release
identity, tag, version, channel and installed package identity, computes the
version relationship, then performs its own source-revision CAS. A missing or
partial facet set is unavailable and cannot change package state.

The physically separate conformance plugin in
`tests/fixtures/ran-booster-fixture-provider/` registers a novel provider ID,
supplies a provider-owned diagnostics object, resolves a nested repository
locator, saves and validates its own credential through the provider-bound live
sidecar, prepares an archive and deliberately omits repository browsing and
webhooks. `tests/RepositoryProvider/ExternalFixturePluginTest.php` exercises the
contract without loading the fixture as core code, while
`tests/WordPress/fixture-provider-smoke.php` proves both plugin load orders,
package-form presentation, selected-provider diagnostics and explicit
unsupported-capability behavior in WordPress. The fixture is excluded from the
runtime release archive and requires no provider-name branches in Booster,
GitHub or Bitbucket code.

GitHub remains bundled and owned by Core under this same public boundary.
Provider API 10 proves ordinary-vendor independence; it does not authorize a
separate GitHub package, repository, dependency or release stream. Extraction
remains NO-GO unless separately approved after isolation evidence is complete.
