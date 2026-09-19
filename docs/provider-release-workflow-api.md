# Provider release-workflow capability

Provider API 11 keeps release-workflow setup as an optional, separately versioned
provider facet. The base provider registration seam does not change when this
facet evolves.

## Pre-1.0 baseline

`RepositoryReleaseWorkflowManagementV2` is the only release-workflow management
facet in the v1 baseline and retains `RELEASE_WORKFLOW_API_VERSION = 2`.

The earlier Core-bound `RepositoryReleaseWorkflowManagement` API 1 facet was
removed before 1.0 after the maintained-repository audit found no current
consumer. It is not a compatibility contract for v1.

## API 2 provider-neutral contract

`RepositoryReleaseWorkflowManagementV2` is a `ProviderCapability` whose method
signatures accept only provider-neutral workflow inputs. Loading the facet does
not load Core release-tracking parameter types into an external provider runtime.

API 2 keeps the fixed workflow operation shape while accepting only
provider-neutral inputs:

- `RepositoryReleaseWorkflowTarget` contains only the package type, installed
  identifier, source revision, stable provider repository identity, package
  root, installed version and expected update URI needed by provider workflow
  logic.
- `RepositoryReleaseWorkflowPreflight` contains only the bounded machine code
  and reason code required by workflow inspection and setup.

The target's expected Update URI may be empty. When present, it must be an HTTPS
URL with a host and no userinfo. Ordinary release tracking may retain a
non-HTTPS canonical Update URI, but Core cannot project that value into an API 2
target, so that provider/package is not eligible for current workflow-helper
calls until its expected Update URI satisfies the stricter workflow boundary.

Those values expose no Core release-tracking facade, storage object, package
model, credential material, callback or service resolver. Core constructs fresh
API 2 values at the provider call boundary. Provider-owned workflow status,
preview and result outputs remain unchanged.

The bundled GitHub provider implements API 2 for workflow management. Core's
current workflow helper resolves `RepositoryReleaseWorkflowManagementV2`
directly.

The API 2 facet still requires the same five release-consumption capabilities on
the registered provider aggregate: `RepositoryReleaseMetadata`,
`RepositoryReleaseCandidateListing`, `RepositoryReleaseInspector`,
`RepositoryReleaseAcquirer` and `RepositoryReleaseNativeTargets`. Current Core
workflow-helper controls and calls also require that aggregate's
`ProviderMetadata` to expose non-null `ProviderAdminMetadata`. Admin metadata
remains optional for ordinary provider registration and other capabilities.

## Provider API 11 baseline

Provider API 11 hosts publish
`RepositoryReleaseWorkflowManagementV2` as the current provider-neutral
workflow-management contract. Providers targeting API 11 may load and implement
that optional facet directly; providers that do not adopt workflow management
need no additional feature check.

The workflow facet remains separately versioned at API 2 so a future workflow
contract can evolve independently without widening the base provider
registration surface.

