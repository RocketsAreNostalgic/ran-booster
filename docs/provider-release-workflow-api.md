# Provider release-workflow capability

Provider API 12 keeps release-workflow setup as an optional, separately versioned
provider facet. The base provider registration seam does not change when this
facet evolves.

## Initial-only contract

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

The helper resolves `RepositoryReleaseWorkflowManagementV3` directly.
The production lock selects immutable GitHub Provider `v1.0.0-beta.8`
(`556f19923f6564f1bbd5cecee089d6b136afc5cd`), which implements V3 and preserves
a valid bootstrap record's operation across source revisions. Core requires a
`bootstrap` operation before exposing or invoking outcomes. The locked composition passed repository quality, archive verification and the
four supported WordPress/database installation jobs on merged Core #177. This
installed candidate proof does not establish an immutable API-12 Core release.

The API 3 facet still requires the same five release-consumption capabilities on
the registered provider aggregate: `RepositoryReleaseMetadata`,
`RepositoryReleaseCandidateListing`, `RepositoryReleaseInspector`,
`RepositoryReleaseAcquirer` and `RepositoryReleaseNativeTargets`. Current Core
workflow-helper controls and calls also require that aggregate's
`ProviderMetadata` to expose non-null `ProviderAdminMetadata`. Admin metadata
remains optional for ordinary provider registration and other capabilities.

## Provider API 12 compatibility boundary

Provider API 12 identifies this breaking, initial-only V3 contract. API 11
promised V2 and is no longer advertised by Core. External providers
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

PR #177 was squash-merged as `c335d6a1322db6dbb51dec4dee6c0fe2d026cc1e`
on 30 September 2026. Exact merged-main Quality run
[36776240469](https://github.com/RocketsAreNostalgic/ran-booster/actions/runs/36776240469)
passed repository quality, runtime archive verification and all four supported
WordPress/database installation jobs, including bundled Provider API-12/V3
readback without the development Composer autoloader.

UI/presentation implementation and owner interactive/end-to-end acceptance
remain deferred under #81/#85. Automated checks must identify exact sources and
distinguish source candidates, installed candidate archives and certified
releases. An immutable API-12 Core release and Bitbucket qualification against
that actual release remain pending. Keep certification pins tied to the actual
certified release; do not publish a bridge-only release or claim full G1/G2
acceptance from this candidate proof.
