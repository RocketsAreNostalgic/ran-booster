# Release workflow controls separation baseline

This evidence freezes the observable release-workflow boundary before any
production extraction. The source baseline is live Core `main` at
`1865e8368b6d9d13e424aaeecb336cc88bcbae03`.

## Reproducible boundary

Run from the Core repository root:

```sh
wc -l \
  RAN/Admin/ReleaseManagement/ReleaseWorkflowControls.php \
  RAN/Admin/ReleaseManagement/ReleaseWorkflowDisplay.php
wc -l \
  tests/Admin/ReleaseManagement/ReleaseWorkflowControlsTest.php \
  tests/Admin/ReleaseManagement/ReleaseWorkflowDisplayTest.php
wc -l docs/characterization/release-workflow-controls-separation-baseline.md
```

The frozen pre-characterization boundary is:

| Kind | Files | Lines |
| --- | --- | ---: |
| Production PHP | `ReleaseWorkflowControls.php` (1,481), `ReleaseWorkflowDisplay.php` (286) | 1,767 |
| Focused test PHP | `ReleaseWorkflowControlsTest.php` (869), `ReleaseWorkflowDisplayTest.php` (621) | 1,490 |
| Task-specific evidence | This file did not exist | 0 |

The extraction boundary is the four workflow owners plus the net change in
every directly modified supporting production file. Test and documentation
changes remain separate and cannot offset production growth.

Current concepts are two internal concrete production types, no workflow-owned
interface or DTO, four WordPress registrations, one `admin-post.php` route, no
public provider capability beyond `RepositoryReleaseWorkflowManagement`, and
no workflow-owned option, table, schema, cache, cron event, or other persistent
state.

## Callback and composition contract

`ReleaseWorkflowControls::register()` installs these exact registrations:

| Hook | Callback | Priority | Accepted arguments |
| --- | --- | ---: | ---: |
| `ran_booster_admin_package_source_choices` | `keepReleaseSettingsDiscoverable` | 20 | 5 |
| `ran_booster_admin_package_release_readiness_actions` | `renderPackageReleaseAutomationLink` | 20 | 2 |
| `ran_booster_admin_repository_release_sections` | `renderRepositoryReleaseSections` | 20 | 2 |
| `admin_post_ran_booster_release_workflow` | `handleWorkflow` | 10 | 1 |

Runtime bootstrap resolves and registers Controls once on `plugins_loaded` at
`PHP_INT_MAX`, after provider sealing and before `ReleaseManagementControls`.
Dashboard composition passes the same Controls instance directly to
`ProviderRepositoryRowsNormalizer::projectPage()`. The normalizer calls
`enrichRepositoryRows()` after webhook enrichment, normalizes that result, and
only then runs the bounded public provider-row filter.

The five callback-shaped methods retained by the final adapter are
`keepReleaseSettingsDiscoverable()`,
`renderPackageReleaseAutomationLink()`,
`renderRepositoryReleaseSections()`, `handleWorkflow()`, and
`enrichRepositoryRows()`. `register()` and construction remain composition
mechanics, not extension seams.

## Current method ownership map

The destination is the owner of the method's substantive responsibility after
separation. A mixed method is decomposed across the named pipeline rather than
moved wholesale or assigned to a fifth owner.

| Current method | Destination | Boundary note |
| --- | --- | --- |
| `__construct()` | Adapter | Compose the three internal owners and existing dependencies. |
| `register()` | Adapter | Register only the four existing WordPress callbacks. |
| `keepReleaseSettingsDiscoverable()` | Adapter -> presenter | Retain the callback and delegate the source-choice projection. |
| `enrichRepositoryRows()` | Adapter -> presenter | Retain the direct normalizer seam and delegate immediately. |
| `renderPackageReleaseAutomationLink()` | Mixed callback pipeline | Adapter callback; controller verifies signed feedback, presenter projects navigation, Display renders. |
| `renderPackageWorkflowResult()` | Mixed callback pipeline | Remove as a separate helper; controller screen-binds the result and Display renders the bounded notice. |
| `renderRepositoryReleaseSections()` | Mixed callback pipeline | Adapter binds the row/slot, controller verifies feedback, presenter builds the model, Display renders the inner panel. |
| `repositorySourceGuard()` | Presenter | Passive repository/package relationship projection. |
| `renderRepositoryReleaseLifecycle()` | Renderer | Workflow lifecycle markup only. |
| `renderRepositoryReadiness()` | Renderer | Readiness markup only. |
| `repositoryReleaseAutomationState()` | Presenter | Display-safe label, tone, provenance, and notice state. |
| `renderPackageAutomationObservation()` | Renderer | Observation markup only. |
| `observationKindForResult()` | Presenter | Translate a bounded result into read-side observation state. |
| `publishedReleasesWorking()` | Presenter | Passive status projection. |
| `statusMatchesSummary()` | Presenter | GET-side exact summary binding. |
| `repositoryPackageReadinessMessage()` | Presenter | Display-safe readiness message selection. |
| `handleWorkflow()` | Adapter -> request controller | Retain the hook target and delegate the raw POST callback. |
| `redirectTo()` | Request controller | Native and HTMX transport. |
| `processWorkflowRequest()` | Request controller | Admission, provider operation, failure containment, signed PRG, and destination. |
| `workflowType()` | Request controller | Raw request normalization. |
| `workflowIdentifier()` | Request controller | Raw request normalization. |
| `workflowRevision()` | Request controller | Raw request normalization. |
| `workflowPreview()` | Request controller | Raw request normalization. |
| `workflowStatus()` | Request controller | Exact POST status read. |
| `workflowSourceGuard()` | Request controller | Mutation source and exclusivity guard; presenter continues through its read-side guard. |
| `workflowNonceAction()` | Request controller | Single workflow-operation nonce definition, exposed only as needed for form projection. |
| `workflowPreflightNonce()` | Request controller | Assessment-preflight nonce input. |
| `workflowResult()` | Request controller | Bounded operation outcome. |
| `preserveRequestFailure()` | Request controller | Failure containment and correlation persistence. |
| `failureDiagnosticCode()` | Request controller | Signed diagnostic allow-listing. |
| `failureReference()` | Request controller | Bounded correlation reference creation. |
| `workflowPackage()` | Request controller | Exact POST package binding; presenter uses its local GET package lookup. |
| `packageMatchesStatus()` | Request controller | POST package/status binding; presenter retains an independent GET matcher. |
| `anonymousWorkflowInspectionAllowed()` | Request controller | Credential-admission decision; display availability remains independently projected. |
| `repositoryReleaseAutomationProjection()` | Presenter | Repository detail/action projection. |
| `localPackage()` | Presenter | Passive package lookup with unavailable fallback. |
| `recordMatchesStatus()` | Presenter | Exact read-side record projection. |
| `recordMatchesPackageStatus()` | Mixed security/read projection | Controller owns POST record admission; presenter independently projects occupied/exact state. |
| `boundedReference()` | Presenter | Display-safe bounded label input. |
| `workflowView()` | Presenter | Package callback projection adapter. |
| `workflowViewFor()` | Presenter | Complete display-safe workflow model. |
| `unavailableWorkflowView()` | Presenter | Stable unavailable projection. |
| `workflowDisplayStatus()` | Presenter | Passive exact status read. |
| `workflowUnavailableReason()` | Presenter | Display-safe unavailable reason. |
| `workflowForm()` | Presenter | Form projection using the controller's single nonce definition. |
| `workflowCapability()` | Request controller | Provider operation capability admission. |
| `workflowProvider()` | Mixed provider resolution | Controller admits operations; presenter independently resolves the passive read capability. |
| `releaseProviderSupported()` | Presenter | Read-side complete release-capability projection; controller repeats the fail-closed admission check. |
| `workflowProviderStatus()` | Presenter | Cached passive status read; controller performs its own request-local credential/status read. |
| `workflowProviderCode()` | Mixed exact binding | Controller derives POST binding; presenter derives form and navigation projection independently. |
| `requestBoundary()` | Presenter | Contain passive status, preview, package, and source-read failures. |
| `returnUrl()` | Request controller | Canonical package fallback destination. |
| `repositoryReleaseUrl()` | Mixed destination | Controller builds the canonical PRG destination; presenter builds the equivalent navigation link without depending on controller transport. |
| `resultQueryArguments()` | Request controller | Signed result serialization. |
| `requestedResult()` | Request controller | Signed result parsing and tamper rejection. |
| `resultNonceAction()` | Request controller | One signature definition for creation and validation. |
| `resultDisplayText()` | Request controller | Signed display-text bounds. |
| `resultMatchesCurrentScreen()` | Request controller | Exact package/current-screen binding. |
| `requestedPreviewKey()` | Request controller | Opaque GET preview-key normalization. |
| `releaseChannelFrom()` | Request controller | Raw request channel normalization. |

`ReleaseWorkflowDisplay` keeps all thirteen of its current methods. Its public
`workflow()`, `stateNotice()`, `resultNotice()`, and `resultMarker()` methods
remain fixed render seams; its private model, legacy, preview, form, message,
diagnostic, and failure-stage methods remain renderer implementation. It also
absorbs the workflow-owned lifecycle, readiness, badge, observation, link, and
section markup currently embedded in Controls.

The mixed rows above are decomposition findings, not a fifth owner. The
controller and presenter must keep independent POST-admission and GET-display
checks where their strictness differs. They must not share a new service,
facade, DTO, registry, or generic projection layer.

## Observable outcome matrix

| Contract | Characterization |
| --- | --- |
| Four hooks, exact callbacks, priorities, and arities | `ReleaseWorkflowControlsTest::testRegistersNeutralReleaseRoutesWithoutAddingCoreRowsToThePublicExtensionFilter` |
| Five operations on one provider-neutral route | `testNonGitHubFixtureCompletesAllFiveOperationsThroughTheSingleNeutralRoute` |
| Exact provider, repository, type, identifier, revision, nonce, source, capability, credential, preview, and preflight rejection before an inappropriate operation | `testMissingAggregateAndWrongAuthorityFailClosedWithoutProviderCalls`, `testMalformedAuthorityFieldsAndExpiredNonceDoNotReachAProviderOperation`, `testWrongTupleAndNonceRefuseBeforeProviderStatusOrWorkflowCalls`, `testWrongCredentialAndPreviewTupleRefuseBeforeAnyWorkflowRemoteOperation`, and `testRejectedPreviewAndPreflightDoNotInvokeAWriteOperation` |
| Anonymous inspection | `testAnonymousPublicInspectionPassesNullCredentialAndPermissionsAndNonceDoNotReachProvider` |
| Signed result round-trip, every-field tamper rejection, and current-screen binding | Focused signed-result characterization in `ReleaseWorkflowControlsTest` |
| Native redirect and equivalent `HX-Location` transport | Focused callback-transport characterization in `ReleaseWorkflowControlsTest` |
| Package fallback and exact selected-repository destination | `testWorkflowResultFallsBackToThePackageReleaseAssetSettingsWhenTheRepositoryCannotBeResolved`, `testFallbackPackageSettingsRenderTheSignedWorkflowResultWithoutWorkflowControls`, and `testWorkflowResultReturnsToTheExactRepositoryReleaseView` |
| Unavailable, incomplete, partial, and exception containment | Controls tests for incomplete providers/source reads and the focused provider-exception characterization; `ReleaseWorkflowDisplayTest` covers unavailable and partial notices. |
| Passive GET uses status/preview reads and none of the five mutations | Focused passive-render characterization in `ReleaseWorkflowControlsTest` |
| Preview, five forms, schema-2 record, legacy/unknown record, diagnostics, labels, and escaping | `ReleaseWorkflowDisplayTest` preview/form, record, legacy, diagnostic, shell-ordering, and escaping tests. |
| Row enrichment, stable `core:release-workflow-*` action keys, `release_workflow` history category, capacity, lifecycle, and readiness | Controls enrichment/capacity tests, `ProviderRepositoryRowsNormalizerTest`, and `RepositoryDetailRendererTest`. |
| Bootstrap order and single registration | `ReleaseManagementCutoverBootstrapTest` and `ProviderApiLifecycleTest`. |

No live provider call is part of this matrix; every provider interaction uses a
local fixture.

## Extraction projection and decision

The current Core-owned invariant is one fixed provider-neutral route and UI
surface with fail-closed request authority, signed bounded feedback, passive
GET reads, and fixed Core escaping. Smaller alternatives are rejected:

- retaining the mixed class does not reduce its request, projection, and HTML
  reasons to change;
- extracting only a controller leaves projection and workflow markup mixed;
- forcing three classes puts WordPress registration into the controller or
  presenter;
- a shared service, DTO, generic renderer, or fifth owner adds a prohibited
  concept rather than resolving ownership; and
- duplicating one nonce algorithm would split a security contract.

The exact projected deltas are:

| Gate | Request-controller task | Completed four-owner separation |
| --- | ---: | ---: |
| Concrete internal production types | +1 | +2 |
| Interfaces, services, DTOs, registries, or facades | 0 | 0 |
| Public hooks, routes, provider capabilities, or extension seams | 0 | 0 |
| Persistent state | 0 | 0 |
| Production PHP lines | +42 intermediate maximum | 0 target; 1,767 total |

The `+42` intermediate ceiling covers one class boundary, constructor wiring,
and request-local copies of the few exact-binding checks that GET projection
must retain independently. It is justified only by keeping raw request,
credential, provider-operation, signed-result, and redirect authority together.
Reusing Controls as a forwarding facade, adding a shared helper type, or
exceeding `+42` is not part of this projection. The presenter/renderer task must
recover the intermediate overhead by deleting mixed callback bodies and moving
markup without duplication. Any final positive production delta requires a
new named invariant and rejected smaller alternative; more than `+50` stops
for owner review.

**Decision: GO, narrowly.** Proceed with the request-controller extraction as
one coherent POST/PRG unit. Stop and return to this gate if signed creation and
parsing split, GET presentation enters the controller, the adapter becomes a
substantial forwarding facade, another production concept appears, or the
measured line projection is missed.
