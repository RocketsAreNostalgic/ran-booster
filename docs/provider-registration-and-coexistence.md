# Provider registration and coexistence: current state

**Status:** Current-source characterization for Provider API 10, reviewed on
17 August 2026. This document records behavior that exists now. It does not
reserve new vendor names, change registration, or authorize the proposed
hardening work.

## Short answer

Booster currently prevents two provider implementations from owning the same
exact provider code. The bundled GitHub provider registers `gh` before the
public provider-registration action, so a community provider cannot also
register `gh` through the supported API or receive the credential store bound
to that code.

That is the strongest current overlap protection. It is code-level isolation,
not vendor-level exclusivity:

- another GitHub implementation can register a distinct code such as
  `acme-github`;
- Core does not currently identify it as another GitHub implementation, warn
  about the overlap, or hide either implementation;
- capabilities, credentials, webhook routes, package identity, locks, and
  delivery evidence are not merged across the two codes; and
- the first exact-code collision is rejected, but the resulting exception can
  currently interrupt the remaining registration action and Booster bootstrap.

An active WordPress plugin runs in the same PHP process as Booster. These
supported API boundaries reduce accidental cross-provider access; they do not
authenticate a plugin publisher or isolate secrets from hostile PHP with
filesystem, database, hook, or process access.

## Registration lifecycle

Core publishes the exact integer marker
`RAN_BOOSTER_PROVIDER_API_VERSION = 10`. Compatible provider plugins attach a
callback to `ran_booster_register_providers` during normal plugin loading and
must fail closed unless the marker is exactly the generation they support.

On `plugins_loaded` at priority 100, Core:

1. creates the `ProviderRegistry`;
2. registers the bundled GitHub implementation as `gh`;
3. fires `ran_booster_register_providers` once; and
4. seals the registry.

This ordering means the bundled `gh` claim is established before community
callbacks run. Registration after sealing is rejected, and a provider
activated during the current request becomes available on the next request.

The relevant composition paths are `RAN/BoosterServiceProvider.php`,
`ran-booster.php`, and `RAN/RepositoryProvider/ProviderRegistry.php`.

## What exact-code registration protects

The registry is keyed by a normalized `ProviderCode`. A second registration
for an already accepted code is rejected before Core issues a credential store
or invokes the second provider factory. A factory that returns a provider with
a different code is also rejected, and failed registration publishes neither
provider nor secret policy state.

For a credential-bearing provider, Core supplies a read-only
`ProviderCredentialStore` and an
`AuthenticatedWebhookDeliveryEvidenceReader`, both already bound to the
requested code. Neither accepts a provider selector. This makes the provider
code a custody boundary rather than a display-only identifier.

The registration path therefore guards against these accidental cases:

| Attempt                                                                      | Current outcome                                                                 |
| ---------------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| Community provider registers `gh`                                            | Rejected before it receives a `gh` credential store or runs its factory.        |
| Two add-ons register the same unclaimed code                                 | The first accepted provider remains registered; the second registration throws. |
| Factory returns a provider under another code                                | Rejected atomically; no provider or policy is published.                        |
| Provider registers after sealing                                             | Rejected.                                                                       |
| Provider requests another provider's credentials through the supported store | No supported selector exists; the store remains bound to its registration code. |

The security contract and its negative credential tests live in
[`provider-extension-contract.md`](provider-extension-contract.md) and
`tests/RepositoryProvider/ProviderSecretPolicyContractTest.php`.

## What the registry does not identify

`ProviderCode` currently reserves only Core administration route names such as
`overview`, `portability`, `documentation`, and `troubleshooting`. It does not
reserve vendor aliases such as `github`, `gitlab`, or `bitbucket`, and it does
not reserve the `ran-` prefix.

Provider metadata validates a safe label, owner label, repository base, and
other presentation values. Core does not currently require a canonical vendor
family, compare repository hosts for uniqueness, inspect plugin basenames, or
authenticate the publisher named in metadata.

Consequently, a community plugin can legitimately register
`acme-github` with a GitHub repository base. That provider will appear beside
the bundled implementation. Current Core does not:

- warn that both implementations target the same vendor;
- choose a preferred implementation;
- let an administrator hide the bundled implementation;
- combine the stronger capabilities of both implementations; or
- migrate packages, credentials, webhooks, or history between them.

This openness is useful for custom providers and self-hosted forges, but it
leaves coexistence decisions entirely with the administrator.

## Runtime isolation and remaining overlap

Provider code continues through the runtime:

- saved credentials and webhook secrets are stored under that provider code;
- managed package rows retain the selected provider code;
- webhook routes use `/webhooks/{provider}` and resolve that exact provider;
- deployment requests require the selected provider and verify that returned
  repository identity belongs to it; and
- optional capabilities resolve from the one provider aggregate registered
  for that code.

There is no capability fallback or composition. If `acme-github` implements
repository browsing but not webhook management or release discovery, it gets
only its own implemented behavior. It does not inherit bundled `gh`
capabilities.

The package table has a unique installed-target key for package type and
package name, so one installed plugin or theme cannot have two simultaneous
managed rows merely because two providers can see the same repository.
However, different provider codes have separate webhook routes, signing
material, deduplication, advisory locks, and delivery evidence. Core does not
coordinate two implementations pointed at the same remote repository.

## Bundled GitHub is not currently optional at runtime

The bundled GitHub module uses the ordinary Provider API boundary, but Core
always registers it as `gh`. There is no current preference to hide or disable
it.

Several first-party surfaces still know explicitly about `gh`, but repository
webhook-management placement no longer does. Core places its fixed controls for
any provider whose registered aggregate implements both exact webhook facets.
This does not transfer `gh` credentials or package identity to another code;
each provider remains isolated by its registered code.

Skipping bundled registration would not be a safe replacement mechanism. The
provider code is also the key for retained package identity and secret custody.
Allowing another implementation to claim `gh` would transfer supported access
to existing `gh` credentials. Leaving `gh` absent would instead make existing
packages unavailable, reject its webhook deliveries, and impair operations
that require its registered secret policy.

The existing Assisted Hooks compatibility check is a narrow historical bridge:
when its old standalone integration is detected, Core webhook-management
presentation is suppressed and an administrator warning is shown.
It is not a general provider-collision system.

## Failure behavior and operational caveats

Exact collisions are detected early, but not yet gracefully contained. A
duplicate registration throws through the shared WordPress action. Because
WordPress stops the current action dispatch when a callback throws, the failure
can interrupt later provider callbacks and the rest of Booster initialization.
A single catch around the whole action would keep Core alive but would not let
callbacks after the failing callback run.

Provider disappearance is handled conservatively after registration. Existing
package identity is retained and shown as unavailable; Core does not silently
substitute another provider. This prevents accidental authority transfer, but
it is an unavailable state rather than a migration facility.

## WP Pusher vendor baseline

The retained WP Pusher 3.0.13 source and the current WP Pusher Migrator confirm
three runtime provider families:

| Provider family | Historical code | Historical scope                                    |
| --------------- | --------------- | --------------------------------------------------- |
| GitHub          | `gh`            | GitHub.com                                          |
| Bitbucket Cloud | `bb`            | Bitbucket.org / Bitbucket Cloud                     |
| GitLab          | `gl`            | GitLab.com or a configured self-managed GitLab base |

The inherited Branch material was product marketing, not a fourth runtime Git
provider. RAN Booster currently bundles GitHub; Bitbucket is an optional
provider add-on; GitLab remains historical migration context rather than a
bundled current provider.

## Current guarantee

Today Booster can say:

> One accepted implementation owns one exact provider code, and supported
> credential access remains bound to that code.

It cannot yet say:

> Only one implementation of a vendor is present, overlapping implementations
> are grouped by canonical service endpoint, no more than one implementation
> may operate for that endpoint, or reserved vendor and RAN namespaces are
> enforced.

The earlier full service-ownership design for those guarantees is superseded.
No distinct-code pair has demonstrated overlapping operational authority at one
concrete target, and a shared repository URL base is only an overlap signal. It
does not by itself justify disabling providers that operate on different
packages or repositories.

No automatic precedence, displacement, shared webhook ingress or signing
migration, provider election or package handover is approved. Reconsideration
requires a concrete same-target overlap plus separate owner authority. A
product-wide rule permitting only one provider implementation for a repository
service would be an explicit product restriction, not a correctness property
inferred from current metadata.
