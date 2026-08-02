# Secret boundary characterization

## Status and decision

This is source-backed Phase 0 evidence at Core baseline
`51a9e63fe5a414c63ef5ce086739d09e690e49b8`. It freezes the supported
secret and extension trust boundary without changing production behavior,
storage, schema, public APIs, API markers, provider accounts or remote state.

**GO:** use the trust tiers, allowed/forbidden contract and negative-conformance
matrix below as the entry gate for the later coordinated Alpha contract cut.

**Phase-zero NO-GO:** the baseline did not close ordinary add-on plaintext or
global-container access. The global/container and bulk enumerator portion is now
closed by the implementation checkpoint below; saved-secret Webhook Assistance
callbacks and the open logging contract remain. Do not publish a Core/add-on
compatibility claim until those remaining exceptions are removed, the exact API
tuple is updated and every output surface is proven from immutable artifacts.

## Global and bulk closure checkpoint — 2026-08-02

Core runtime commit `f25a09d614aec004cc9190423db8c79a1652d3d2`
removes the supported global/singleton container path and the unused plural
credential-plaintext enumerator:

- the live `Booster` instance and update-request filter are scoped inside the
  bootstrap closure, while WordPress callbacks retain only the references they
  require;
- `ran_booster()`, static singleton state, `getInstance()`, `setInstance()` and
  the container's self-binding are absent with no compatibility wrapper;
- `Booster::make()` and `bind()` remain public only for existing Core
  composition and are explicitly internal-by-contract. Making the entire
  container private was rejected as high-churn and does not provide hostile
  same-process confidentiality while Core implementation classes remain
  directly constructible;
- `PluginRepository::fromSlug()` now hydrates `Plugin` directly; and
- `SecretsFile::credentialMaterials()` is deleted rather than replaced with a
  private generic iterator. Display-safe profiles, one exact/default material
  read, the three-method provider-bound store and Core's bounded requested-
  provider webhook candidates remain unchanged.

The production change is +143/-198 PHP lines, net -55, with zero new production
type, public seam, API marker, hook, schema, option, durable state or remote
call. Tests add 91 net PHP lines and release/CI guards add 33 lines. The focused
boundary suite passes 64 tests/559 assertions; the full source gate passes 1,835
tests/11,115 assertions and 123 asset tests. Immutable archive and isolated
WordPress/load-order evidence is recorded at the later integration checkpoint.
The remaining Phase-one work is logging closure, provider trust/conformance and
the fixed-operation Assisted Hooks migration; this checkpoint makes no claim
that those seams are already closed.

## Frozen trust tiers

| Tier                                   | Supported authority                                                                                                                                                                                                                                                                          | Forbidden authority                                                                                                                                                                                                                                                                                            |
| -------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Ordinary add-on                        | Display-safe identifiers and projections, explicit non-secret intent, purpose-specific Core facades and bounded non-secret results. An add-on may separately own a request-only value for one confirmed operation.                                                                           | Core-held credential or webhook-secret plaintext, the Core container, provider registry or object, credential store, site key, codec, encrypted sidecar, storage path, generic resolver, Authorization material, signer, verifier, decrypter, authenticated transport or arbitrary "run with secret" callback. |
| Credential-bearing repository provider | The read-only `ProviderCredentialStore` supplied by `ProviderRegistry::registerWithCredentialStore()` and permanently bound to the registered provider code. It may read display-safe profiles, one selected credential in its namespace and only the boolean presence of a webhook profile. | Another provider code or record, bulk cross-provider enumeration, credential mutation, webhook material, site key, codec, encrypted sidecar, storage path, lock, general resolver or Core container. Provider registration is not publisher authentication.                                                    |
| Core secret/storage authority          | Secret storage, site key, authenticated envelope, lifecycle, exact provider/profile binding, generated webhook-secret custody, inbound verification and bounded result composition.                                                                                                          | Publishing storage authority to an extension or describing filesystem mode, PHP visibility or namespaces as same-process isolation.                                                                                                                                                                            |
| Host or edge                           | Pre-PHP availability, connection, request-size, timeout, cache/challenge and trusted-peer controls. A separately approved edge add-on may own its own credential.                                                                                                                            | Booster provider credentials, webhook signing material, a signing/verification oracle or a claim that headers/domain names authenticate the sender.                                                                                                                                                            |

First-party status does not change a tier. The administrator's installation and
activation decision is the trust decision; Core does not cryptographically
authenticate an extension publisher.

## Phase-zero supported call-site inventory

### Core storage and internal consumers

- `RAN/BoosterServiceProvider.php` creates `SecretsFile`, binds it in the
  current container and supplies provider-bound stores to `ProviderRegistry`.
- `RAN/Secrets/SecretsFile.php` owns credential and webhook persistence,
  constant overlays, temporary request material, the encrypted document and
  its storage path. Its baseline public `credentialMaterial()`,
  `credentialMaterials()`, `webhookMaterials()` and `path()` methods are Core
  implementation surfaces, not safe ordinary add-on contracts.
- `RAN/Secrets/BoundProviderCredentialStore.php` fixes a `ProviderCode` in its
  constructor and delegates only `credentialProfiles()`, one
  `credentialMaterial()` read and `hasWebhookProfile()`.
- `RAN/RepositoryProvider/ProviderCredentialStore.php` exposes no provider
  selector, write method, webhook-material read, key, codec, sidecar or path.
- `RAN/RepositoryProvider/ProviderRegistry.php` retains distinct
  credential-free `register()` and credential-bearing
  `registerWithCredentialStore()` routes. The live registry and first
  registration order are not plugin identity or confidentiality controls.
- Built-in Core consumers resolve plaintext internally in the GitHub browser,
  archive preparation, native release preflight/registration, portability and
  inbound webhook verification paths. These are not ordinary add-on seams.

### Published extension composition

- `ran-booster.php` publishes the provider registry only during
  `ran_booster_register_providers`, then seals it. A provider opting into the
  credential-bearing registration route is trusted with its provider
  namespace.
- The same bootstrap publishes purpose-specific facades on the Admin
  Interaction, Portability, Release Tracking, Prospective Release, Webhook
  Assistance and Webhook Cleanup ready hooks. It also publishes the Logging
  facade on several ready hooks.
- `RAN/Admin/AdminAddOnRegistry.php` admits only Core-owned named facade
  entries. `RAN/Admin/AdminAddOnContext.php` hands one selected facade and the
  Logging facade to one capability-checked administrator tab; neither type is
  an identity boundary against hostile PHP.
- The safe ordinary-add-on baseline is executable in
  `tests/Security/SecretBoundaryNegativeConformanceTest.php`: Admin Interaction,
  Portability, Release Tracking, Prospective Release and Webhook Cleanup may
  not acquire secret-authority types or methods.

### Phase-zero ordinary-add-on exceptions

The following are present at the baseline and must be removed or closed by the
later coordinated Alpha cut. The original characterization asserted their exact
baseline shape so a change could not be mistaken for completed proof; the
current test now records the closed subset explicitly.

Items 1–3 are closed by the checkpoint above. Items 4–6 remain downstream
acceptance gates.

1. `ran-booster.php` defines `ran_booster()`, which returns
   `Booster::getInstance()`.
2. `RAN/Booster.php` exposes public `getInstance()` and `make()`, allowing a
   caller to request currently bound implementations such as `SecretsFile`.
3. `RAN/Secrets/SecretsFile.php` exposes public bulk/plaintext and storage-path
   methods. PHP visibility is not a hostile-plugin boundary, but these methods
   remain a convenient supported-looking acquisition route.
4. `RAN/AddOn/WebhookAssistance/WebhookAssistanceFacade.php` publishes
   `withCredential()`, `provision()` and `reconfigure()` callbacks whose
   signatures carry sensitive parameters.
5. `RAN/AddOn/WebhookAssistance/AssistedWebhookFacade.php` passes a selected
   saved credential into the `withCredential()` callback and passes one
   webhook secret into the provision/reconfigure callback.
6. `RAN/AddOn/Logging/LoggingFacade.php` accepts add-on-authored free-form
   messages and context. `RAN/Logging/BoosterLogger.php` allowlists context keys
   and excludes exception messages/traces, but does not detect arbitrary secret
   values in an allowed string or the message itself.

These are current exposure facts, not permissions for new consumers.

## Request-only PAT exception

The request-only PAT remains a distinct, weaker, add-on-owned input for one
capability- and nonce-confirmed operation. It is not a Core saved credential,
is never assigned through `ProviderCredentialStore`, is not persisted,
rendered, logged, returned in a result or converted to a reusable handle, and
does not gain generic execution authority. Clearing a local variable after the
call is defense in depth, not revocation or same-process erasure.

The later saved-profile path has a different claim: an ordinary add-on may
submit a display-safe profile ID, while Core binds and the matching provider
uses the saved PAT inside one fixed operation. No saved plaintext crosses into
the add-on. The request-only fallback must not be used to weaken that claim.

## Frozen allowed and forbidden contracts

Allowed ordinary-add-on inputs and outputs are closed, operation-specific and
non-secret:

- provider code, safe profile ID, exact Core-resolved target identity and
  bounded non-secret operation fields;
- display-safe profile lifecycle metadata;
- capability, nonce, compatibility and readiness decisions; and
- typed results with closed status/reason vocabularies and safe authoritative
  readback receipts.

Forbidden ordinary-add-on inputs, outputs and callbacks include:

- `SecretsFile`, `ProviderCredentialStore`, `ProviderRegistry`, `Booster`, a
  service container, key store, envelope codec, path or encrypted document;
- raw credentials, webhook secrets, Authorization headers, signed request
  bodies, upstream response bodies or secret-derived fragments;
- a credential handle usable with arbitrary URL, method, headers or body;
- `withCredential()`, `withSecret()`, generic callable execution, signing,
  verification, decryption or authenticated-HTTP oracles; and
- arbitrary output fields that can place vendor/user text in logs, notices,
  support bundles, results or release archives.

The credential-bearing provider tier is allowed to receive only its bound
credential reader. Receipt of plaintext means Core cannot constrain what that
provider does in its private code or logger. Conformance proves the supported
Core boundary and cross-provider scoping, not provider honesty.

## Negative-conformance surface matrix

Use synthetic values assembled in test code. Never use realistic provider
formats, live values or customer-derived fragments. Test failures must name
only the surface and transform; they must not print the value.

Every later runtime and immutable-artifact gate must cover all cells:

| Surface                                                    | Exact  | Prefixed | Base64 | URL encoded | Fragmented/rejoined |
| ---------------------------------------------------------- | ------ | -------- | ------ | ----------- | ------------------- |
| Typed operation and fitness results                        | Reject | Reject   | Reject | Reject      | Reject              |
| Core and published add-on logs                             | Reject | Reject   | Reject | Reject      | Reject              |
| Administrator notices and transient/redirect feedback      | Reject | Reject   | Reject | Reject      | Reject              |
| Diagnostics, incidents, troubleshooting and support output | Reject | Reject   | Reject | Reject      | Reject              |
| Built release ZIP contents and path inventory              | Reject | Reject   | Reject | Reject      | Reject              |

The Phase 0 test freezes the five transforms and five named surfaces and proves
that its detector recognizes scalar and fragmented structured output without
printing the probe. Existing focused tests already prove several exact-value
redactions, and `scripts/verify-release.sh` rejects forbidden private/secret/log
paths plus credential-shaped archive content. Neither fact is universal value
detection. The later Alpha cut must inject every transform into each real
result/log/notice/support/archive fixture and prove rejection from the exact
coordinated artifacts.

## Coordinated cut acceptance delta

The later Core secret/provider runtime lane may replace the current-exposure
assertions only when one coordinated change proves all of the following:

1. **Complete:** `ran_booster()` and the supported global singleton/container
   acquisition path are absent, with no compatibility wrapper.
2. **Complete for the container slice:** no published ordinary add-on
   context/hook/facade resolves the container,
   registry, provider object, credential store, `SecretsFile`, site key, codec,
   storage path or encrypted sidecar.
3. **Complete:** `SecretsFile::credentialMaterials()` is removed; only one
   exact provider-bound material read remains in the provider contract.
4. Webhook Assistance no longer publishes `withCredential()` or any callback
   carrying a saved PAT or webhook secret. Setup/reconfigure secret use stays
   inside the matching provider's separately typed fixed operation.
5. The add-on logging seam is removed where Core can log its own events or is
   replaced with a closed Core-owned event/result schema after an exact API
   cut. No open message or arbitrary context compatibility adapter remains.
6. Old/new Core and add-on tuples, both load orders, missing/deactivated
   components, partial update and rollback all fail closed without remote work,
   state mutation or a legacy plaintext path.
7. Every canary matrix cell passes against source and deterministic archives.

Until then, the current-exposure test must remain green and explicit rather
than being weakened or silently deleted.

## Budgets and rollback

| Budget                                           | Phase 0 approved/actual delta           |
| ------------------------------------------------ | --------------------------------------- |
| Production PHP/JavaScript/CSS                    | `0`                                     |
| Production concrete types/concepts               | `0`                                     |
| Public seams/API markers                         | `0`                                     |
| Persistence/schema/options/tables                | `0`                                     |
| Remote/provider calls                            | `0`                                     |
| Local WordPress/database/cache/runtime mutations | `0`                                     |
| Tests                                            | One test-only negative-conformance type |
| Documentation                                    | One source evidence document            |

Rollback is a normal revert of the single characterization commit. There is no
data, schema, provider or infrastructure rollback because this slice changes no
runtime or external state. Abandon rather than widen it if a green test would
require production code, a webhook-owned file, a generic detector/service or a
false confidentiality claim.

## Overlap, residual risk and deferred work

- Canonical secret-policy characterization separately owns policy invocation,
  constant overlays, `SecretsFile` call timing and any structural/semantic
  validation decision. This evidence reads those paths but does not edit them.
- Provider-fitness characterization separately owns fixed-operation shape,
  Assisted Hooks workflow cost and remote-call budgets. This evidence freezes
  its trust inputs/outputs but does not define or implement that operation.
- The Webhook Ingress stream owns route/controller/processor ordering, signing
  request proof, Activity and host/edge operator guidance. This slice edits no
  webhook-owned source or test file.
- A malicious active WordPress plugin can inspect raw request globals, attach
  global HTTP hooks, query the database, read files available to the PHP host
  user and invoke callable PHP. Core cannot provide confidentiality in that
  same process. Strong isolation requires separately authorized custody and
  authenticated operations outside WordPress.
- Provider publisher authentication, an external broker, P3 extension-owned
  secret pools, a generic authenticated transport, a permission language and
  persistent feature-to-credential assignments remain deferred or rejected as
  specified by their own plans. This Phase 0 adds no placeholder for them.
