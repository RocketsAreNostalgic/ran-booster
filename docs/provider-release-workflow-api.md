# Provider release-workflow capability

Provider API 10 keeps release-workflow setup as an optional, separately versioned
provider facet. The base provider registration seam does not change when this
facet evolves.

## API 1 compatibility contract

`RepositoryReleaseWorkflowManagement` is the frozen API 1 contract and retains
`RELEASE_WORKFLOW_API_VERSION = 1`. Its method signatures continue to accept
Core `ReleaseTrackingStatus` and `ReleaseTrackingPreflight` values so a provider
already compiled against API 1 can still declare and load without a PHP method-
compatibility fatal after Core introduces the next workflow facet.

Core does not rewrite this published interface in place. Existing API 1 classes
remain valid implementations of `RepositoryReleaseWorkflowManagement`, but the
current workflow helper no longer resolves API 1 for workflow operations.

## API 2 provider-neutral contract

`RepositoryReleaseWorkflowManagementV2` is a separate `ProviderCapability` with
`RELEASE_WORKFLOW_API_VERSION = 2`. It intentionally does **not** extend API 1:
loading the API 2 facet must not load API 1's Core release-tracking parameter
types into an external provider runtime.

API 2 keeps the same fixed workflow operation shape while accepting only
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

The bundled GitHub provider implements only API 2 for workflow management.
Core's current workflow helper resolves `RepositoryReleaseWorkflowManagementV2`
directly. An API 1 implementation remains load-compatible but receives no
workflow-helper calls from this Core generation until it explicitly adopts the
API 2 capability.

The API 2 facet still requires the same five release-consumption capabilities on
the registered provider aggregate: `RepositoryReleaseMetadata`,
`RepositoryReleaseCandidateListing`, `RepositoryReleaseInspector`,
`RepositoryReleaseAcquirer` and `RepositoryReleaseNativeTargets`. Current Core
workflow-helper controls and calls also require that aggregate's
`ProviderMetadata` to expose non-null `ProviderAdminMetadata`. Admin metadata
remains optional for ordinary provider registration and other capabilities.

## Provider API 10 feature detection

The unchanged `RAN_BOOSTER_PROVIDER_API_VERSION === 10` guard proves only that
the base Provider API 10 registration contract is present. Older Provider API 10
Booster releases do not define `RepositoryReleaseWorkflowManagementV2`, so a
provider that supports API 2 must feature-detect that interface before loading
or declaring any class that implements it:

```php
$workflowV2Available = interface_exists(
	\RAN\RepositoryProvider\RepositoryReleaseWorkflowManagementV2::class
);

if ( $workflowV2Available ) {
	require_once __DIR__ . '/ExampleProviderV2.php';
}
```

The API-2 implementation must therefore live behind that feature gate; do not
unconditionally require, instantiate or otherwise autoload the V2 class before
`interface_exists()` has succeeded. A provider that also supports older API-10
hosts may load/register its ordinary Provider API 10 implementation when the
facet is absent and use its V2-capable aggregate only when the interface exists.
Providers that do not adopt workflow API 2 need no additional feature check.

This versioned facet does not change `RAN_BOOSTER_PROVIDER_API_VERSION`, which
remains 10, and does not introduce a separate Provider API package or a second
provider registration mechanism.
