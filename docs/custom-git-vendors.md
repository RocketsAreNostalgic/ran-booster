# Custom git vendor setup

RAN Booster exposes a single runtime extension seam for custom git vendors:
`ran_booster_register_providers`. Use it to register a trusted repository
provider that can resolve repositories, expose diagnostics, prepare immutable
archives, and optionally support browsing, credential policy, and web hook
normalization.

This guide explains the contract, the core types, the recommended setup
methodology, and the troubleshooting surface that goes with a new vendor.

## When to use this hook

Use `ran_booster_register_providers` when you need to add a git vendor that is
not already built into Booster. Common cases include:

- a new hosting platform;
- a private enterprise Git service;
- a niche source control system with a Git-compatible API; or
- a vendor-specific adapter that needs to read credentials from Booster's
  provider-bound sidecar store.

If the provider needs credentials, register it from the main plugin file during
normal plugin loading so Booster can seal the registry after all callbacks have
had a chance to run.

## Registration pattern

A provider attaches its callback on `plugins_loaded` before Booster seals the
registry:

```php
add_action(
  'ran_booster_register_providers',
  static function ( \RAN\RepositoryProvider\ProviderRegistry $registry ): void {
    if ( ! defined( 'RAN_BOOSTER_PROVIDER_API_VERSION' )
      || 10 !== RAN_BOOSTER_PROVIDER_API_VERSION ) {
      return;
    }

    $registry->registerWithCredentialStore(
      'example-vendor',
      static fn (
        \RAN\RepositoryProvider\ProviderCredentialStore $credentials,
        \RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader $deliveryEvidence
      ): \RAN\RepositoryProvider\RepositoryProvider => new ExampleVendorProvider( $credentials, $deliveryEvidence )
    );
  }
);
```

Use `registerWithCredentialStore()` when the provider reads stored credentials.
Its callback receives two read-only values already bound to the requested
provider code. `ProviderCredentialStore` exposes display-safe profiles, one
selected or default credential and the boolean `hasWebhookProfile()` diagnostic
readiness check. `AuthenticatedWebhookDeliveryEvidenceReader` exposes only
`latestAuthenticatedDelivery()` for that provider. Neither value accepts a
provider argument, selects another provider, writes state or exposes sidecar
paths, signing material, a credential writer or a database/deployment
repository. The factory must remain local and non-I/O, and the returned provider
must use exactly the code that was requested. Activating a credential-bearing
provider therefore trusts it with credentials saved under its code;
registration order is not publisher authentication, and Core cannot control the
provider's private code after authorized disclosure.

Provider API 10 supplies no logger, service container or generic service
resolver. An
unexpected caught diagnostic failure may be attached only to a bounded
request-local `ProviderDiagnosticResult` for Core to log; it is omitted from
serialization and administrator copy.

## Core contract

Every custom vendor implements `RAN\RepositoryProvider\RepositoryProvider`.
That interface requires four responsibilities:

- `getMetadata()` returns the provider's typed metadata.
- `getProviderDiagnostics()` returns the bounded troubleshooting suite.
- `resolveRepository()` maps a lookup request to a repository descriptor.
- `prepareArchive()` produces an immutable archive request and must fail closed
  if an expected branch is no longer the current head.

The provider code itself is represented by `RAN\RepositoryProvider\ProviderCode`.
Codes are open and stable, but they must match Booster's syntax rules and must
not collide with the reserved built-in identifiers `overview`, `portability`,
`documentation`, or `troubleshooting`.

### Metadata

`RAN\RepositoryProvider\ProviderMetadata` packages the public-facing vendor
identity:

- provider code;
- label;
- repository URL base;
- owner label; and
- optional admin metadata.

The constructor enforces the URL and text rules that make the metadata safe for
rendering and link generation. The repository URL base must be HTTPS, must have
a host, and must not contain user info, query strings, or fragments.

If the provider has admin-facing credential kinds or web hook scopes, include
`RAN\RepositoryProvider\Admin\ProviderAdminMetadata` and keep its labels,
placeholders, descriptions, scope names, and web hook guidance within the
constructor limits.

Use an optional `ProviderNavigationPlacement` when the provider should declare
its position in Core's provider navigation. Choose the ordinary `git-host` or
`other-provider` group and a slot from 1 through 10,000. No slot is reserved for
a built-in provider. Omitted placement falls back to `other-provider` at slot
10,000, and equal placements are ordered by provider code.

### Diagnostics

`RAN\RepositoryProvider\ProviderDiagnostics` is the troubleshooting suite.
Its `diagnose()` method accepts a `RAN\RepositoryProvider\ProviderDiagnosticRequest`
and returns a list of `RAN\RepositoryProvider\ProviderDiagnosticResult` records.

The request enforces:

- at most 5 remote calls;
- at most 10 seconds of total time; and
- optional credential and repository context.

Diagnostics should stay bounded, local where possible, and redacted. Use the
request's `claimRemoteCall()` method before each outbound call so your code
respects the remaining time budget.

For an unexpected exception that the provider must catch to return a bounded
result, pass the original `Throwable` only through the optional request-local
failure field. Core first validates the result's status, code and safe display
text, then logs the failure at its troubleshooting boundary.
`ProviderDiagnosticResult::toArray()` omits it. Expected provider outcomes use
ordinary typed results without a failure; there is no Provider API logger.

### Repository resolution and archive preparation

A vendor's `resolveRepository()` method should convert a lookup request into a
`RAN\RepositoryProvider\RepositoryDescriptor`.
That descriptor is the canonical handoff into Booster's deployment pipeline and
contains the selected provider, repository identity, locator, privacy, default
branch, credential selection, and package slug.

`prepareArchive()` receives an archive request and must return a verified
`RAN\RepositoryProvider\PreparedArchive`. Archive URLs must be HTTPS, must have
a host, and must not contain user info or fragments. Providers must not place
reusable secrets in archive URLs.

Provider API 10 supplies `GitReferenceSyntax::isValidNamedReference()` for the
generic bounded branch/ref syntax check and `AuthenticatedPreparedArchive` for
the one-request archive authentication, redirect scrubbing, head verification
and cleanup lifecycle. A vendor may impose stricter syntax or origin rules, but
must not weaken that authentication cleanup or import Core deployment/storage
implementations.

### Optional capabilities

Custom vendors can add optional capability interfaces when the vendor supports
them:

- `RAN\RepositoryProvider\RepositoryBrowser` for repository browsing.
- `RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser` for public-only
  browsing with one explicitly selected access profile and typed
  provider-default applicability.
- `RAN\RepositoryProvider\ProviderCredentialPolicySupplier` for credential
  normalization and deployment constant mapping.
- `RAN\RepositoryProvider\SubmittedCredentialValidator` on the returned
  credential policy for new or replacement credential-shape checks.
- `RAN\RepositoryProvider\WebhookNormalizer` for push-to-deploy web hook
  normalization and signing policy.
- `RAN\RepositoryProvider\RepositoryWebhookSettingsLink` to link a managed
  repository directly to its provider-owned webhook settings screen.
- `RAN\RepositoryProvider\RepositoryWebhookFitness` and
  `RAN\RepositoryProvider\RepositoryWebhookManagement` together for the exact
  `repository-webhook-management/3` setup, check, reconfigure, remove and test
  operation, with matching read-only `assessSetup`, `assessCheck`,
  `assessReconfigure`, `assessRemove` and `assessTest` methods. The provider
  owns its fixed vendor calls; Core owns authorization, binding, secret custody
  and bounded results. Core does not generate a
  management form, credential schema or route from the backend capability.
- `RAN\RepositoryProvider\RepositoryReleaseMetadata` for the provider's
  canonical Update URI and public release-details URL. This is local metadata
  only; it does not opt the provider into discovery, archive inspection,
  downloads or WordPress updates.
- `RAN\RepositoryProvider\RepositoryReleaseCandidateListing` for a bounded,
  read-only list of typed release candidates for one resolved repository and
  stable or prerelease channel. The provider owns remote calls and credential
  use; the facet grants no download, inspection, installation or mutation
  authority.
- `RAN\RepositoryProvider\RepositoryReleaseInspector` for inspecting one exact
  release and returning bounded, path-free evidence. The provider owns archive
  acquisition, verification and disposal; the facet grants no installation,
  updater or mutation authority.
- `RAN\RepositoryProvider\RepositoryReleaseAcquirer` for freshly reacquiring
  one inspected release and returning a typed, single-use artifact. The
  provider owns remote access, verification and custody until handoff; Core
  alone installs, reads back and adopts the package.
- `RAN\RepositoryProvider\RepositoryReleaseNativeTargets` to construct and
  register provider-owned WordPress native update targets, detect an existing
  provider target, normalize passive status and perform an explicit refresh.
  Implement all five release-consumption capabilities together to make a provider
  eligible for managed published-release tracking. Core retains installed
  package enumeration, authority snapshots, mutation fences, locks and source
  transitions.
- `RAN\RepositoryProvider\RepositoryReleaseWorkflowManagement` for optional
  release workflow assessment, draft pull requests, outcome checks and template
  updates. It requires all five release-consumption contracts on the same
  provider. Core owns the shared controls and authorization; providers return
  immutable evidence and own their remote operations. See the
  [workflow capability contract](provider-extension-contract.md#optional-release-workflow-management).

Each optional capability stays behind Booster's capability gate. If the provider
omits an automation helper, Booster omits that helper's setup component without
removing deployment support. A claimed but incomplete helper stays visible and
disabled with a provider-configuration notice, rather than guessing a fallback.

Credential validation may optionally report a normalized UTC expiry through
`CredentialValidationResult::valid( CredentialExpiryReport::known( ... ) )`.
The existing zero-argument `valid()` call remains supported. Return
`CredentialExpiryReport::unknown()` after a successful check whose expiry
metadata is missing or malformed; do not pass raw provider headers into Core.
Vendors that report no expiry remain compatible and use Booster's optional
manual credential date.

`SubmittedCredentialValidator` may reject new or replacement material with
`InvalidCredentialInput`. Use only the provider-neutral reasons
`invalid_configuration`, `credential_kind_mismatch` or `invalid_secret_shape`
and a non-empty, single-line administrator message of at most 512 bytes. Core
rejects unknown reasons and unsafe, structured, token-shaped, path-like or
authorization-bearing text to one generic message. Existing saved,
constant-backed and imported credentials deliberately bypass this submitted
shape check.

## Methodology for a new vendor

1. Choose a unique `ProviderCode` and decide whether the provider needs admin
   metadata, credential storage, browsing, or web hook support.
1. Implement `RepositoryProvider` first so the core contract exists.
1. Add `ProviderDiagnostics` early so the vendor has a bounded troubleshooting
   path before any deployment work is attempted.
1. Add `ProviderCredentialPolicySupplier` if the vendor uses named credential
   profiles or deployment constants.
1. Add `SubmittedCredentialValidator` to that policy only when current
   submissions need a provider-specific shape check; test both accepted bounded
   copy and Core's generic fallback for unsafe failures.
1. Add `WebhookNormalizer` only if the vendor can normalize and authorize
   signed web hooks safely.
1. Add `RepositoryWebhookSettingsLink` only if the provider has a stable HTTPS
   webhook-settings route. Validate its repository locator inside the provider;
   Booster will omit invalid or unsafe URLs instead of guessing.
1. Add `RepositoryWebhookFitness` and `RepositoryWebhookManagement` together
   only when the provider implements the complete fixed webhook-management
   operation, including bounded assessment, authoritative readback and
   ambiguous/partial outcomes. Do not add an operation dispatcher or generic
   authenticated transport.
1. Add `RepositoryBrowser` only if you want in-WordPress repository discovery.
1. Add `CredentialedPublicRepositoryBrowser` only if the provider can
   authenticate public-owner browsing without returning private repositories or
   turning the lookup profile into a package credential.
1. Add `RepositoryReleaseCandidateListing` only when the provider can return a
   bounded typed list and distinguish no eligible release from operational
   failure without exposing upstream payloads.
1. Add `RepositoryReleaseInspector` only when the provider can acquire, verify
   and discard one exact release archive and return its bounded typed evidence.
   Use the two typed rejection reasons for no matching releases and invalid
   release contents; expose no local path, URL, credential or artifact handle.
1. Add `RepositoryReleaseAcquirer` only when the provider can freshly acquire
   the inspected release, compare its opaque fingerprint and return a
   single-use `RepositoryReleaseArtifact`. Do not return a path, URL, archive
   bytes, provider result payload or reusable claim. Report a bounded cleanup
   failure when provider-owned bytes cannot be discarded before handoff.
1. Test registration from the main plugin file with the version guard in place.
1. Verify the provider registers cleanly, seals cleanly, and surfaces the
   correct optional capabilities.

A good vendor implementation keeps object construction local and pure, defers
network work until the relevant method is called, and returns safe, bounded
values instead of upstream payloads.

## Troubleshooting

Use the diagnostics suite first. It is the fastest way to prove that the vendor
can authenticate, reach its repository, and satisfy the provider's own policy.

Common setup failures and what they mean:

- Invalid provider code: the code does not match Booster's allowed syntax or
  collides with a reserved identifier.
- Invalid metadata: the label, owner label, repository URL base, or admin
  metadata exceeds the constructor rules.
- Missing credential policy: the vendor exposes credential kinds or web hook
  scopes but does not implement the corresponding capability interface.
- Unsupported capability: Booster asked for browsing, credential policy, or
  web hook normalization, but the provider did not implement that capability.
- Diagnostics budget exceeded: the provider made too many outbound calls or
  exceeded the 10 second deadline.
- Stale branch head: archive preparation resolved a branch once, but the branch
  was no longer the current head before mutation.
- Unsafe archive URL: the provider returned a non-HTTPS URL, user information,
  a fragment, reusable credentials or other authorization material. A
  provider-owned signed query is allowed only under that provider's documented
  origin and expiry policy.

If the vendor registers in tests but not in WordPress, confirm that the callback
runs on the active plugin load path and that `RAN_BOOSTER_PROVIDER_API_VERSION`
is exactly `9`.

## Related types

The main types you will usually touch while adding a new vendor are:

- `RAN\RepositoryProvider\ProviderRegistry`
- `RAN\RepositoryProvider\RepositoryProvider`
- `RAN\RepositoryProvider\ProviderMetadata`
- `RAN\RepositoryProvider\ProviderCode`
- `RAN\RepositoryProvider\ProviderDiagnostics`
- `RAN\RepositoryProvider\ProviderDiagnosticRequest`
- `RAN\RepositoryProvider\ProviderDiagnosticResult`
- `RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader`
- `RAN\RepositoryProvider\RepositoryLookupRequest`
- `RAN\RepositoryProvider\RepositoryDescriptor`
- `RAN\RepositoryProvider\RepositoryBrowser`
- `RAN\RepositoryProvider\ProviderCredentialPolicySupplier`
- `RAN\RepositoryProvider\SubmittedCredentialValidator`
- `RAN\RepositoryProvider\InvalidCredentialInput`
- `RAN\RepositoryProvider\ProviderWebhookPolicy`
- `RAN\RepositoryProvider\RepositoryWebhookFitness`
- `RAN\RepositoryProvider\RepositoryWebhookManagement`
- `RAN\RepositoryProvider\RepositoryWebhookFitnessResult`
- `RAN\RepositoryProvider\RepositoryWebhookOperationResult`
- `RAN\RepositoryProvider\PreparedArchive`
- `RAN\RepositoryProvider\AuthenticatedPreparedArchive`
- `RAN\RepositoryProvider\GitReferenceSyntax`

For a deeper discussion of the provider contract itself, see the
[provider extension contract](provider-extension-contract.md).

The bundled GitHub module uses this same ordinary-vendor contract and remains
owned and shipped by Core. Provider API 10 does not authorize extracting it into
a separate package or release stream; extraction remains NO-GO.
