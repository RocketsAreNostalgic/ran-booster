# Provider release-workflow capability

> Provider API 15 / workflow V4 is published in [Core v1.0.0-beta.37](https://github.com/RocketsAreNostalgic/ran-booster/releases/tag/v1.0.0-beta.37). External providers still need qualification against their exact supported host and contract. Publication does not clear separate manual acceptance gates.

Provider API 15 keeps release-workflow setup as an optional, separately versioned
Git provider facet. The base provider registration seam does not change when this
facet evolves.

## Initial-only contract

`RepositoryReleaseWorkflowManagementV4` is the only release-workflow management
facet in the v1 baseline and retains `RELEASE_WORKFLOW_API_VERSION = 4`.

The earlier Core-bound `RepositoryReleaseWorkflowManagement` API 1 facet was
removed before 1.0 after the maintained-repository audit found no current
consumer. It is not a compatibility contract for v1.

## API 4 provider-neutral contract

`RepositoryReleaseWorkflowManagementV4` is a `ProviderCapability` whose method
signatures accept only provider-neutral workflow inputs. Loading the facet does
not load Core release-tracking parameter types into an external provider runtime.

API 4 keeps the fixed workflow operation shape while accepting only
provider-neutral inputs:

- `RepositoryReleaseWorkflowTarget` contains only the package type, installed
  identifier, source revision, stable provider repository identity, package
  root, installed version and expected update URI needed by provider workflow
  logic.
- `RepositoryReleaseWorkflowPreflight` contains only the bounded machine code
  and reason code required by workflow inspection and setup.

The target's expected Update URI may be empty. When present, it must be an HTTPS
URL with a host and no userinfo. Ordinary release tracking may retain a
non-HTTPS canonical Update URI, but Core cannot project that value into an API 4
target, so that provider/package is not eligible for current workflow-helper
calls until its expected Update URI satisfies the stricter workflow boundary.

Those values expose no Core release-tracking facade, storage object, package
model, credential material, callback or service resolver. Core constructs fresh
API 4 values at the provider call boundary. Provider-owned workflow status,
preview and result outputs remain bounded. Preview accepts only initial
`bootstrap` setup on `stable`; its summary no longer contains old/new template
tags. The five methods are `workflow_status`, `workflow_preview`,
`workflow_inspect`, `workflow_setup` and `workflow_outcome`. Update operations
are rejected before provider, credential or preflight access.

The helper resolves `RepositoryReleaseWorkflowManagementV4` directly.
The provider returns operational setup/PR state, credential choices and safe links.
Core enriches it with durable assessment and failure history. An assessment is
established only by a recognized result; an unrelated failed attempt does not
erase it. Currentness includes the captured adoption and source/access context.

`workflow_inspect`, `workflow_setup` and `workflow_outcome` accept an optional
final `?RAN\Activity\ActivityProgress $progress = null`. Providers report actual
stages and return their existing `RepositoryReleaseWorkflowResult`; Core owns
terminal recording. Setup claims, previews and pull-request records remain
provider-owned operational state. See [activity usage](provider-extension-contract.md#durable-activity-reporting).

The API 4 facet still requires the same five release-consumption capabilities on
the registered provider aggregate: `RepositoryReleaseMetadata`,
`RepositoryReleaseCandidateListing`, `RepositoryReleaseInspector`,
`RepositoryReleaseAcquirer` and `RepositoryReleaseNativeTargets`. Current Core
workflow-helper controls and calls also require that aggregate's
`ProviderMetadata` to expose non-null `ProviderAdminMetadata`. Admin metadata
remains optional for ordinary provider registration and other capabilities.

## Provider API 15 compatibility boundary

Provider API 15 completes snake_case naming of owned methods, parameters,
properties and DTO accessors with initial-only workflow V4. API 11
promised V2 and is no longer advertised by Core. External providers must check
the exact outer API marker before loading their implementation. API11, API12
and API13/API14 providers remain unregistered on API15; API15 providers remain
unregistered on older or unknown future hosts. Both plugin load orders must
be covered. No V2 shim, range negotiation or registration redesign is introduced.

The earlier API12 initial-only V3 change was approved under organisation #81;
API13 then migrated capability methods. Core #167 coordinates landing and
qualification of the completed API14 naming and matching consumers. The credential-bearing registration factory
still takes three arguments, but its owned PHP members use snake_case. Add-on
API17 and Admin Interaction API3 are separately versioned connected changes.
GitHub Provider host qualification and Bitbucket registration must move together
with Core; Branch Updater and Release Updater protocols do not change as part of
this work.

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

At that checkpoint, UI/presentation implementation and owner interactive/end-to-end
acceptance were deferred under #81/#85. This separate feature-acceptance boundary is not
closed by released-dependency adoption or source/API qualification. Automated
checks must identify exact sources and
distinguish source candidates, installed candidate archives and certified
releases. Immutable API14 Core beta.31 publication and Bitbucket/Migrator
release-backed qualification against that actual release were pending at that
historical checkpoint; this is not beta.37's publication status. Keep certification pins tied to the actual
certified release; do not publish a bridge-only release or claim full G1/G2
acceptance from this candidate proof.
