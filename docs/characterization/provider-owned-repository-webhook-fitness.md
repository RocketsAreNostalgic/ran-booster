# Provider-owned repository-webhook fitness characterization

**Decision:** Core implementation landed for the coordinated Alpha contract
replacement within the frozen budgets below. Release remains gated on the
Assisted Hooks migration and combined compatibility proof against the exact
Provider API 8 / Add-on API 14 tuple.

## Core implementation checkpoint

Core now publishes the exact `repository-webhook-management/1` fitness and
management capabilities, four bounded non-secret results/interfaces, and one
private GitHub webhook client. The former callback result types, secret-bearing
Webhook Assistance callbacks, Webhook Cleanup facade, marker and ready hook are
absent. No persistence, schema, option, hook, background, JavaScript or CSS
surface was added.

The management `check()` and `remove()` methods deliberately include Core's
canonical callback URL. The earlier sketch omitted it, but hook ID alone cannot
prove that the remote object belongs to the selected Core endpoint before
readback or deletion. This tightens exact-target ownership without exposing a
generic URL or transport capability: Core derives the URL and the GitHub client
still fixes origin, path shapes, methods, headers and byte/call ceilings.

The remaining join must prove the migrated Assisted Hooks consumer, old/new
tuple failures, both load orders, secret canaries and deterministic release
artifacts before release.

## Pre-implementation Core boundary

The following evidence records the baseline that justified the coordinated
cut. It is retained for review history; the implementation checkpoint above is
the current contract.

Core already owns the safety envelope needed by the replacement:

- `ProviderRegistry::requireCapability()` selects only an installed provider
  implementing a known `ProviderCapability` interface.
- `ProviderRegistry::registerWithCredentialStore()` permanently binds a
  read-only `ProviderCredentialStore` to one provider code.
- `AssistedWebhookFacade` re-resolves the current repository target and owns
  webhook-profile creation, selection, release and safe profile projections.
- `WebhookAssistanceReadinessEvaluator` derives the target from current Core
  package records; the add-on does not establish repository authority.

The built-in GitHub aggregate is not yet using the retained credential-bearing
registration seam. `BoosterServiceProvider` registers `GitHubProvider` as an
initial credential-free provider, while `GitHubProvider` and
`RAN\GitHub\RepositoryBrowser` hold `SecretsFile` directly. The runtime slice
must first construct `gh` through
`registerWithCredentialStore( 'gh', ... )` and give the GitHub aggregate only
its bound store. It must not add another credential resolver.

The current public Webhook Assistance seam is unsuitable for an ordinary
add-on: `withCredential()` delivers a saved PAT to an add-on callback, while
`provision()` and `reconfigure()` deliver a Core-held signing secret to add-on
callbacks. `ProvisioningCallbackResult` acknowledges only callback success and
cannot express a provider timeout, partial remote mutation, ambiguous readback,
or compensation outcome.

## Frozen authority and namespace binding

The only phase-one provider operation identity is:

`gh / repository-webhook-management / 1`

The operation belongs to the provider registered as `gh`. Core resolves that
provider through the sealed registry and requires the two exact optional
capabilities below. Registration order, plugin basename, display label and an
operation string supplied by an add-on grant no authority.

Every assessment and execution is bound immediately before use to:

1. the current administrator's `manage_options` capability and an
   action-specific nonce;
2. the allowlisted Assisted Hooks facade method;
3. provider code `gh`, operation version `1` and one closed action variant;
4. Core's current stable provider repository ID and normalized
   `owner/repository` locator for the selected package target;
5. the selected GitHub credential profile ID plus its current provider-owned
   authority revision, effective expiry and local self-destruction state; and
6. for reconfigure/remove, the recorded GitHub hook ID and Core webhook-profile
   ID/revision already bound to that repository.

Core re-derives the target and lifecycle on every call. Fitness evidence,
cached observations, an add-on record and a previous success never authorize a
later call. The provider owns PAT resolution only inside its `gh` namespace.
Only exact setup and reconfigure receive one Core-held signing secret, and only
for the duration of that fixed method. Assessment, check, remove, descriptors,
results, logs and the ordinary add-on receive none.

The request-only PAT fallback remains a separate, weaker input to these exact
methods. It is supplied for one capability- and nonce-confirmed request and is
never saved, assigned, returned or converted into a reusable handle. The saved
profile path and request-only path must not share a generic credential or
transport abstraction.

## Smallest separately typed contracts

The provider capability must have explicit methods, not a registered callback
or `execute( operation, payload )` dispatcher.

```text
RepositoryWebhookFitness
  assessSetup(authority, credentialProfileId)
  assessCheck(authority, credentialProfileId, hookId)
  assessReconfigure(authority, credentialProfileId, hookId)
  assessRemove(authority, credentialProfileId, hookId)

RepositoryWebhookManagement
  setup(authority, credentialProfileId, signingSecret)
  check(authority, credentialProfileId, hookId)
  reconfigure(authority, credentialProfileId, hookId, signingSecret)
  remove(authority, credentialProfileId, hookId)
```

The request-only fallback may be represented only as explicit overloads or an
explicit sensitive parameter on those same fixed methods. It may not become a
general credential input, provider SDK or authenticated transport.

Fitness and execution are separate. `RepositoryWebhookFitness` is read-only,
cannot receive a signing secret, cannot mutate or compensate, and never grants
execution authority. `RepositoryWebhookManagement` independently reauthorizes
the complete binding before resolving one credential.

Provider-specific permission names, token kinds, GitHub endpoints, request
bodies, pagination, error interpretation, compensation and readback stay in
the GitHub aggregate. Core owns only the fixed bounds, safe result validation,
facade authorization and mapping to administrator copy. An external synthetic
provider fixture must be able to use different permission terms without a
provider-name branch in Core.

## Safe non-secret results

Fitness has three support states: `supported`, `unsupported` and `unknown`.
Supported means the exact provider capability and version exist; it is not a
claim that the credential can mutate the repository. Suitability remains
`suitable|insufficient|unknown`, least-privilege evidence remains
`appropriate|overscoped|unknown`, and evidence strength remains a closed
observed/inferred/unknown-by-design/unavailable/stale value. Write fitness for
fine-grained PATs will commonly remain `unknown`: a successful read does not
prove write authority or selected-repository completeness.

Execution has four states: `succeeded`, `partial`, `ambiguous` and `failed`.

- `succeeded` requires operation-specific authoritative readback. Setup and
  reconfigure return `configured_pending_delivery`; remote configuration
  cannot reveal the stored secret, and a GitHub ping response is not proof of a
  correctly signed inbound delivery. Only Core's inbound verifier may promote
  the profile to verified.
- `partial` means known remote and local effects disagree, or compensation is
  known to be incomplete. Local state is retained for recovery.
- `ambiguous` means a timeout, rate limit, unusable response or failed readback
  prevents determining whether GitHub mutated. It never becomes success and
  automatic retry is forbidden.
- `failed` means no remote effect occurred or authoritative compensation
  restored the safe pre-operation state.

Results contain only the state, a bounded code, observation time, hook ID when
authoritatively known, safe configuration flags and remediation. They contain
no token fragment, secret-derived value, Authorization material, raw header,
raw response, repository enumeration or vendor message.

## Provider evidence and its limits

Current GitHub documentation says repository-hook list/get/test operations
require fine-grained **Webhooks: read**, while create/update/delete require
**Webhooks: write**. The list endpoint defaults to 30 results and supports at
most 100 per page, so a single unqualified request cannot establish absence or
uniqueness. GitHub returns `204` for a successful delete, but the Booster
contract requires a subsequent `GET` yielding `404` before reporting absence.
GitHub's token-creation form supports prefilled `target_name`, `expires_in` and
permission query parameters; the provider should own a short-expiry link using
`repository_hooks=write`, while the operator still selects the exact
repository.

Official sources, checked 2026-08-02:

- [Repository webhook endpoints and permissions](https://docs.github.com/en/rest/repos/webhooks)
- [REST pagination](https://docs.github.com/en/rest/using-the-rest-api/using-pagination-in-the-rest-api)
- [Fine-grained PAT endpoint permissions](https://docs.github.com/en/rest/authentication/permissions-required-for-fine-grained-personal-access-tokens)
- [Fine-grained PAT creation templates](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens#pre-filling-fine-grained-personal-access-token-details-using-url-parameters)

No mutating permission probe is allowed. A `403` can reflect permission,
repository selection, role, organisation approval, SSO or provider policy and
must map to bounded insufficient/unknown/failed evidence rather than a guessed
cause.

## Approved operation budgets

All calls use the provider's one production client, fixed GitHub origin, no
redirects, TLS verification, an 8-second per-call timeout, a 25-second total
deadline, bounded response reads and no retries. Rate-limit or unavailable
responses stop the operation. Fitness and execution budgets are independent;
an unavailable assessment never proceeds into mutation.

| Action      |       Read-only fitness |      Successful execution | Worst case including compensation | Required truth                                                                                                                                                                                                 |
| ----------- | ----------------------: | ------------------------: | --------------------------------: | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| setup       | at most 1 call / 64 KiB | at most 5 calls / 896 KiB |           at most 7 calls / 1 MiB | Up to three `per_page=100` list pages, create, exact readback; if a known created ID fails readback, delete plus absence readback. Hitting the third full page is incomplete/unknown and performs no mutation. |
| check       | at most 1 call / 64 KiB |           1 call / 64 KiB |                   1 call / 64 KiB | Exact hook readback plus Core-local profile/revision state; no ping and no signing claim.                                                                                                                      |
| reconfigure | at most 1 call / 64 KiB |         3 calls / 192 KiB |                 3 calls / 192 KiB | Pre-mutation ownership read, patch, exact readback. Uncertain readback is ambiguous; do not invent rollback of a secret GitHub cannot return.                                                                  |
| remove      | at most 1 call / 64 KiB |         3 calls / 128 KiB |                 3 calls / 128 KiB | Pre-delete ownership read, delete, then exact absence readback. Release local profile/record only after confirmed absence.                                                                                     |

Assessment may reuse evidence already returned by the same explicit operation
without another call, but no cached verdict authorizes work. No call occurs on
ordinary bootstrap, dashboard rendering, credential-list rendering or public
webhook ingress.

## Production and concept budget

Later implementation is approved only within these caps, measured as physical
shipped PHP lines and reported separately from tests/docs:

- Core: at most **+420 net production PHP lines** and zero production
  JavaScript/CSS. Prefer direct consolidation in the GitHub aggregate.
- Public provider seam: exactly two optional interfaces and two bounded result
  types. Removing `ProvisioningCallbackResult` and replacing the current
  provisioning result leaves at most **+2 net public types**.
- Existing Webhook Assistance facade: replace its three secret-bearing callback
  methods; do not add a second facade, registry or generic operation object.
- Concrete production concepts: one GitHub webhook client owned by the built-in
  provider may be added only if extending `RepositoryBrowser` would mix archive
  and webhook lifecycle concerns. No separate SDK, transport layer, request
  DTO graph or duplicate result hierarchy.
- Persistence: **zero** new table, option, sidecar field, assignment record,
  cache, cron, transient or background scan. Reuse credential profile,
  provider/repository identity, webhook profile and Assisted Hooks' existing
  non-secret installation record.
- API impact in the later coordinated cut: Provider API and Add-on/Webhook
  Assistance contract change exactly once; Logging API is unchanged by this
  lane. This characterization changes no marker.

Assisted Hooks' identified GitHub, provider-adapter and workflow strata total
1,135 physical production PHP lines. The migration must delete those duplicate
remote/workflow implementations and target at least **800 net production PHP
lines removed** after its thin controller/gateway adoption. Core growth and
add-on deletion are reported separately; add-on deletion does not buy extra
Core budget.

Tests may add up to 1,500 lines across both repositories for external-provider
proof, pagination/call ceilings, mixed versions, secret canaries, compensation
and result mapping. Documentation may add up to 700 lines across both
repositories. Neither offsets production growth.

## Rejected shapes

NO-GO for:

- `execute( operationId, payload )`, a provider operation registry, callable
  descriptors or a generic dispatcher;
- Core-owned GitHub/Bitbucket permission vocabulary or a generic permission
  expression language;
- a provider SDK, generic authenticated HTTP client, signer, verifier,
  credential handle or feature-controlled URL/method/header/body;
- a credential-to-feature assignment graph, cached authorization, background
  sweep or all-credentials/all-features scan; and
- retaining the existing secret callbacks as an adapter for mixed versions.

## Dependencies, rollback and abandonment

Required merge order is Webhook V1, phase-zero characterization evidence,
Core secret/provider cut, then Assisted Hooks migration and the coordinated
proof. The runtime lane overlaps `BoosterServiceProvider`, `GitHubProvider`,
the provider registry composition, Webhook Assistance facade and their tests.
It must serialize with the canonical secret-policy lane. It does not require a
change to webhook route/controller/processor, Activity presentation or ingress
guidance; those are owned by the parallel webhook stream.

Before merge, old/new Core and add-on tuples, both plugin load orders, missing
provider/capability/version, partial update and rollback must fail closed with
no remote call, state mutation or legacy plaintext path.

Rollback before release is a coordinated revert of Core and Assisted Hooks to
their prior exact tuple. This phase adds no state to migrate. After remote
mutation, ambiguous/partial records must remain visible for operator recovery;
rollback must not delete remote hooks or local evidence automatically.

Abandon the runtime design and retain manual setup if it exceeds any production
or type cap, needs a generic dispatcher/transport/permission language, cannot
bound pagination and response bytes, cannot preserve ambiguous state, or
requires webhook-owned files. Reapproval is required before widening a cap.

## Characterization provenance

This report used source and synthetic/unit-test fixtures only. It made no
GitHub request, provider-account mutation, Local WordPress/database request,
release, archive, API/schema/persistence or production-runtime change. No PAT,
webhook secret or secret canary was added to output or artifacts.
