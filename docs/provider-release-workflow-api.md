# Provider release-workflow capability

Provider API 12 keeps release-workflow setup as an optional, separately versioned
provider facet. The base provider registration seam does not change when this
facet evolves.

## Initial-only draft contract

`RepositoryReleaseWorkflowManagementV3` is the only release-workflow management
facet in the v1 baseline and retains `RELEASE_WORKFLOW_API_VERSION = 3`.

The earlier Core-bound `RepositoryReleaseWorkflowManagement` API 1 facet was
removed before 1.0 after the maintained-repository audit found no current
consumer. It is not a compatibility contract for v1.

## API 3 provider-neutral contract

`RepositoryReleaseWorkflowManagementV3` is a `ProviderCapability` whose method
signatures accept only provider-neutral workflow inputs. Loading the facet does
not load Core release-tracking parameter types into an external provider runtime.

API 3 keeps the fixed workflow operation shape while accepting only
provider-neutral inputs:

- `RepositoryReleaseWorkflowTarget` contains only the package type, installed
  identifier, source revision, stable provider repository identity, package
  root, installed version and expected update URI needed by provider workflow
  logic.
- `RepositoryReleaseWorkflowPreflight` contains only the bounded machine code
  and reason code required by workflow inspection and setup.

The target's expected Update URI may be empty. When present, it must be an HTTPS
URL with a host and no userinfo. Ordinary release tracking may retain a
non-HTTPS canonical Update URI, but Core cannot project that value into an API 3
target, so that provider/package is not eligible for current workflow-helper
calls until its expected Update URI satisfies the stricter workflow boundary.

Those values expose no Core release-tracking facade, storage object, package
model, credential material, callback or service resolver. Core constructs fresh
API 3 values at the provider call boundary. Provider-owned workflow status,
preview and result outputs remain bounded. Preview accepts only initial
`bootstrap` setup on `stable`; its summary no longer contains old/new template
tags. The five methods are `workflowStatus`, `workflowPreview`,
`workflowInspect`, `workflowSetup` and `workflowOutcome`. Update operations
are rejected before provider, credential or preflight access.

The draft helper resolves `RepositoryReleaseWorkflowManagementV3` directly.
Merged GitHub Provider source `d9f18a10d593d88d0373e377197553c02ee664f2`
implements it. The production lock still selects the V2-only beta.6 release;
this draft must not be merged or installed with that composition. Isolated
exact-source candidate testing does not change the lock or certify a release.

The API 3 facet still requires the same five release-consumption capabilities on
the registered provider aggregate: `RepositoryReleaseMetadata`,
`RepositoryReleaseCandidateListing`, `RepositoryReleaseInspector`,
`RepositoryReleaseAcquirer` and `RepositoryReleaseNativeTargets`. Current Core
workflow-helper controls and calls also require that aggregate's
`ProviderMetadata` to expose non-null `ProviderAdminMetadata`. Admin metadata
remains optional for ordinary provider registration and other capabilities.

## Provider API 12 compatibility boundary

Provider API 12 identifies this breaking, initial-only V3 contract. API 11
promised V2 and is no longer advertised by this candidate. External providers
must check the exact outer API marker before loading their implementation:
old API-11 providers remain unregistered on API 12, and API-12 providers remain
unregistered on older or unknown future hosts. Tests cover both plugin load
orders. No V2 shim, range negotiation or registration redesign is introduced.

Ben approved this narrowly coordinated generation change under organisation
#81 on 30 September 2026. The registration factory signature and Add-on API 16
remain unchanged. GitHub Provider host qualification and Bitbucket registration
must move together with Core; Branch Updater and Release Updater protocols do
not change as part of this work.

## Persisted history

The matching V3 Provider rejects obsolete update-history rows before building
Core's status DTO. Unsupported history produces an empty history; stored bytes
remain untouched. This grants no legacy operation authority, receipt migration
or automatic repair. The Core external-provider regression covers both old
update operations alone and mixed with a valid current row.

## Qualification and delivery boundary

PR #177 remains draft, incomplete and unmerged. UI/presentation implementation
and owner interactive/end-to-end acceptance remain deferred under #81/#85.
Automated candidate checks must identify the exact Core and Provider sources;
they do not establish qualification of the currently locked bundle. Matching
immutable Provider adoption, archive/installed proof and API-12 connected compatibility
remain required before a coherent cutover. Do not alter release/certification
pins or publish a bridge-only release to make this draft appear complete.
