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
      || 7 !== RAN_BOOSTER_PROVIDER_API_VERSION ) {
      return;
    }

    $registry->registerWithCredentialStore(
      'example-vendor',
      static fn ( \RAN\RepositoryProvider\ProviderCredentialStore $credentials ): \RAN\RepositoryProvider\RepositoryProvider => new ExampleVendorProvider( $credentials )
    );
  }
);
```

Use `registerWithCredentialStore()` when the provider reads stored credentials.
The supplied store exposes display-safe profiles, one selected or default
credential under that provider code and the boolean `hasWebhookProfile()`
diagnostic readiness check. It cannot select another provider, read signing
material, inspect paths or write state. The factory must remain local and
non-I/O, and the returned provider must use exactly the code that was requested.
Activating a credential-bearing provider therefore trusts it with credentials
saved under its code; registration order is not publisher authentication, and
Core cannot control the provider's private code after authorized disclosure.

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

### Optional capabilities

Custom vendors can add optional capability interfaces when the vendor supports
them:

- `RAN\RepositoryProvider\RepositoryBrowser` for repository browsing.
- `RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser` for public-only
  browsing with one explicitly selected access profile and typed
  provider-default applicability.
- `RAN\RepositoryProvider\ProviderCredentialPolicySupplier` for credential
  normalization and deployment constant mapping.
- `RAN\RepositoryProvider\WebhookNormalizer` for push-to-deploy web hook
  normalization and signing policy.
- `RAN\RepositoryProvider\RepositoryWebhookSettingsLink` to link a managed
  repository directly to its provider-owned webhook settings screen.

Each optional capability stays behind Booster's capability gate. If the provider
omits a capability, Booster will keep the corresponding feature disabled rather
than guessing at a fallback.

Credential validation may optionally report a normalized UTC expiry through
`CredentialValidationResult::valid( CredentialExpiryReport::known( ... ) )`.
The existing zero-argument `valid()` call remains supported. Return
`CredentialExpiryReport::unknown()` after a successful check whose expiry
metadata is missing or malformed; do not pass raw provider headers into Core.
Vendors that report no expiry remain compatible and use Booster's optional
manual credential date.

## Methodology for a new vendor

1. Choose a unique `ProviderCode` and decide whether the provider needs admin
   metadata, credential storage, browsing, or web hook support.
1. Implement `RepositoryProvider` first so the core contract exists.
1. Add `ProviderDiagnostics` early so the vendor has a bounded troubleshooting
   path before any deployment work is attempted.
1. Add `ProviderCredentialPolicySupplier` if the vendor uses named credential
   profiles or deployment constants.
1. Add `WebhookNormalizer` only if the vendor can normalize and authorize
   signed web hooks safely.
1. Add `RepositoryWebhookSettingsLink` only if the provider has a stable HTTPS
   webhook-settings route. Validate its repository locator inside the provider;
   Booster will omit invalid or unsafe URLs instead of guessing.
1. Add `RepositoryBrowser` only if you want in-WordPress repository discovery.
1. Add `CredentialedPublicRepositoryBrowser` only if the provider can
   authenticate public-owner browsing without returning private repositories or
   turning the lookup profile into a package credential.
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
- Unsafe archive URL: the provider returned a non-HTTPS URL or included
  credentials, query strings, fragments, or other disallowed components.

If the vendor registers in tests but not in WordPress, confirm that the callback
runs on the active plugin load path and that `RAN_BOOSTER_PROVIDER_API_VERSION`
is exactly `4`.

## Related types

The main types you will usually touch while adding a new vendor are:

- `RAN\RepositoryProvider\ProviderRegistry`
- `RAN\RepositoryProvider\RepositoryProvider`
- `RAN\RepositoryProvider\ProviderMetadata`
- `RAN\RepositoryProvider\ProviderCode`
- `RAN\RepositoryProvider\ProviderDiagnostics`
- `RAN\RepositoryProvider\ProviderDiagnosticRequest`
- `RAN\RepositoryProvider\ProviderDiagnosticResult`
- `RAN\RepositoryProvider\RepositoryLookupRequest`
- `RAN\RepositoryProvider\RepositoryDescriptor`
- `RAN\RepositoryProvider\RepositoryBrowser`
- `RAN\RepositoryProvider\ProviderCredentialPolicySupplier`
- `RAN\RepositoryProvider\ProviderWebhookPolicy`
- `RAN\RepositoryProvider\PreparedArchive`

For a deeper discussion of the provider contract itself, see the
[provider extension contract](provider-extension-contract.md).
